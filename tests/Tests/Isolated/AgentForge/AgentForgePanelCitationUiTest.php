<?php

/**
 * Isolated regression tests for the AgentForge chart-panel citation display.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge;

use PHPUnit\Framework\TestCase;

final class AgentForgePanelCitationUiTest extends TestCase
{
    public function testPanelRendersStructuredCitationsOutsideAnswerText(): void
    {
        $template = $this->agentForgePanelTemplate();

        $this->assertStringContainsString('appendSources(response, payload)', $template);
        $this->assertStringContainsString('payload.citation_details || []', $template);
        $this->assertStringContainsString('payload.citations && payload.citations.length > 0', $template);
        $this->assertStringContainsString('row.textContent = citationLabel(detail)', $template);
    }

    public function testPanelCanOpenDocumentCitationOverlayWithNormalizedBoundingBox(): void
    {
        $template = $this->agentForgePanelTemplate();

        $this->assertStringContainsString('agent-forge-source-overlay', $template);
        $this->assertStringContainsString("detail.source_type === 'document' || detail.source_type === 'document_review'", $template);
        $this->assertStringContainsString('fetchSourceReview(detail)', $template);
        $this->assertStringContainsString("'agent_document_source_review.php?' + params.toString()", $template);
        $this->assertStringContainsString("params.set('document_id', documentIdFromCitation(detail, citation))", $template);
        $this->assertStringContainsString("params.set('job_id', citation.job_id || detail.job_id || '')", $template);
        $this->assertStringContainsString("params.set('fact_id', citation.fact_id || detail.fact_id)", $template);
        $this->assertStringContainsString("'Accept': 'application/json'", $template);
        $this->assertStringContainsString('agent-forge-source-image-wrap', $template);
        $this->assertStringContainsString('reviewed.page_image_url', $template);
        $this->assertStringNotContainsString('<iframe', $template);
        $this->assertStringNotContainsString('agent_document_source.php?document_id=', $template);
        $this->assertStringContainsString('function showSourceBox(box)', $template);
        $this->assertStringContainsString("sourceBox.style.left = (box.x * 100) + '%'", $template);
        $this->assertStringContainsString("sourceBox.style.top = (box.y * 100) + '%'", $template);
        $this->assertStringContainsString("sourceBox.style.width = (box.width * 100) + '%'", $template);
        $this->assertStringContainsString("sourceBox.style.height = (box.height * 100) + '%'", $template);
    }

    public function testPanelShowsDeterministicNoBoxFallbackWithPageAndQuote(): void
    {
        $template = $this->agentForgePanelTemplate();

        $this->assertStringContainsString('agent-forge-source-fallback', $template);
        $this->assertStringContainsString('payload && payload.review ? payload.review', $template);
        $this->assertStringContainsString('reviewed.document_url', $template);
        $this->assertStringContainsString("sourceBox.className = 'agent-forge-source-box d-none'", $template);
        $this->assertStringContainsString("citation.page_or_section || {{ \"Unknown page\"|xlj }}", $template);
        $this->assertStringContainsString(
            "citation.quote_or_value || reviewed.value || {{ \"No quoted source text was returned.\"|xlj }}",
            $template,
        );
        $this->assertStringContainsString("].filter(Boolean).join(' - ')", $template);
        $this->assertStringContainsString('showSourceOverlay(detail, detail)', $template);
    }

    public function testPanelDoesNotCreateSourceButtonsForNonDocumentCitations(): void
    {
        $template = $this->agentForgePanelTemplate();

        $this->assertStringContainsString('if (canFetchSourceReview(detail))', $template);
        $this->assertStringContainsString("detail.source_type === 'document'", $template);
        $this->assertStringContainsString("detail.source_type === 'document_review'", $template);
        $this->assertStringContainsString('row.textContent = citationLabel(detail)', $template);
        $this->assertStringNotContainsString('canShowSourceOverlay', $template);
    }

    public function testPanelShowsMissingWarningsAndEmptySourceStateSeparately(): void
    {
        $template = $this->agentForgePanelTemplate();

        $this->assertStringContainsString(
            'appendList(response, {{ "Missing or unchecked"|xlj }}, payload.missing_or_unchecked_sections || [])',
            $template,
        );
        $this->assertStringContainsString(
            'appendList(response, {{ "Warnings"|xlj }}, payload.refusals_or_warnings || [])',
            $template,
        );
        $this->assertStringContainsString('No chart sources were returned.', $template);
    }

    public function testPanelKeepsConversationIdOnlyInMemoryForFollowUps(): void
    {
        $template = $this->agentForgePanelTemplate();

        $this->assertStringContainsString('let conversationId = null;', $template);
        $this->assertStringContainsString("body.append('conversation_id', conversationId)", $template);
        $this->assertStringContainsString('conversationId = payload.conversation_id', $template);
        $this->assertStringNotContainsString('localStorage', $template);
        $this->assertStringNotContainsString('sessionStorage', $template);
    }

    public function testPanelClearsQuestionAfterHandledResponse(): void
    {
        $template = $this->agentForgePanelTemplate();

        $this->assertStringContainsString("body.append('question', value)", $template);
        $this->assertStringContainsString("question.value = '';", $template);
        $this->assertStringContainsString('agent-forge-last-question', $template);
        $this->assertStringContainsString("lastQuestion.textContent = {{ \"Question\"|xlj }} + ': ' + value", $template);
        $this->assertStringNotContainsString('question.placeholder = value;', $template);
    }

    private function agentForgePanelTemplate(): string
    {
        $template = file_get_contents(dirname(__DIR__, 4) . '/templates/patient/card/agent_forge.html.twig');

        $this->assertIsString($template);

        return $template;
    }
}
