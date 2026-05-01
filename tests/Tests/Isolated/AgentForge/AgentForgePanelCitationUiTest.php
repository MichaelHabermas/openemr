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

        $this->assertStringContainsString('appendList(response, {{ "Sources"|xlj }}, payload.citations)', $template);
        $this->assertStringContainsString('payload.citations && payload.citations.length > 0', $template);
        $this->assertStringContainsString('row.textContent = item', $template);
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

    private function agentForgePanelTemplate(): string
    {
        $template = file_get_contents(dirname(__DIR__, 4) . '/templates/patient/card/agent_forge.html.twig');

        $this->assertIsString($template);

        return $template;
    }
}
