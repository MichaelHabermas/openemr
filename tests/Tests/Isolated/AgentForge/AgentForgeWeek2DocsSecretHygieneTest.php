<?php

/**
 * Isolated secret-hygiene tests for Week 2 reviewer documentation.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AgentForgeWeek2DocsSecretHygieneTest extends TestCase
{
    use AgentForgeDocsTestTrait;

    #[DataProvider('secretCheckedDocuments')]
    public function testDocsDoNotContainRealLookingSecrets(string $documentPath): void
    {
        $document = $this->readRepoFile($documentPath);

        $forbiddenPatterns = [
            '/sk-[A-Za-z0-9_-]{20,}/',
            '/sk-ant-[A-Za-z0-9_-]{20,}/',
            '/ghp_[A-Za-z0-9_]{30,}/',
            '/-----BEGIN (?:RSA |OPENSSH |EC |DSA )?PRIVATE KEY-----/',
        ];

        foreach ($forbiddenPatterns as $pattern) {
            $this->assertDoesNotMatchRegularExpression($pattern, $document, $documentPath);
        }
    }

    #[DataProvider('secretCheckedDocuments')]
    public function testSecretAssignmentsUseBlankOrPlaceholderValues(string $documentPath): void
    {
        $document = $this->readRepoFile($documentPath);
        $lines = preg_split('/\R/', $document);

        $this->assertIsArray($lines);

        foreach ($lines as $lineNumber => $line) {
            if (!preg_match('/^\s*(?:export\s+)?([A-Z0-9_]*(?:API_KEY|PASSWORD|TOKEN|SECRET))=(.*)$/', $line, $matches)) {
                continue;
            }

            $value = trim($matches[2]);
            $value = trim($value, "'\"");

            $this->assertTrue(
                $value === ''
                    || str_starts_with($value, '<')
                    || str_starts_with($value, '${{ secrets.')
                    || str_starts_with($value, 'assigned-'),
                sprintf('%s:%d assigns a non-placeholder secret value', $documentPath, $lineNumber + 1)
            );
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function secretCheckedDocuments(): array
    {
        return [
            'env sample' => ['/agent-forge/.env.sample'],
            'root readme' => ['/README.md'],
            'reviewer guide' => ['/AGENTFORGE-REVIEWER-GUIDE.md'],
            'week2 hub' => ['/agent-forge/docs/week2/README.md'],
            'week2 acceptance matrix' => ['/agent-forge/docs/week2/W2_ACCEPTANCE_MATRIX.md'],
            'cost latency report' => ['/agent-forge/docs/operations/CLINICAL-DOCUMENT-COST-LATENCY.md'],
        ];
    }
}
