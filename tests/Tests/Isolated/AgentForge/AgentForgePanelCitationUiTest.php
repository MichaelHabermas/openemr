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
        $this->assertStringContainsString("detail.source_type === 'document'", $template);
        $this->assertStringContainsString('detail.citation.bounding_box', $template);
        $this->assertStringContainsString('agent_document_source.php?document_id=', $template);
        $this->assertStringContainsString("encodeURIComponent(citation.job_id || '')", $template);
        $this->assertStringContainsString('agent-forge-source-sheet', $template);
        $this->assertStringNotContainsString('<iframe', $template);
        $this->assertStringContainsString("sourceBox.style.left = (box.x * 100) + '%'", $template);
        $this->assertStringContainsString("sourceBox.style.top = (box.y * 100) + '%'", $template);
        $this->assertStringContainsString("sourceBox.style.width = (box.width * 100) + '%'", $template);
        $this->assertStringContainsString("sourceBox.style.height = (box.height * 100) + '%'", $template);
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
