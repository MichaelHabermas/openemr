<?php

/**
 * Isolated tests for the Week 2 clinical-document deployed smoke runner.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 4) . '/agent-forge/scripts/lib/clinical-document-deployed-smoke-runner.php';

final class ClinicalDocumentDeployedSmokeRunnerTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $priorEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['AGENTFORGE_SMOKE_USER', 'AGENTFORGE_SMOKE_PASSWORD', 'AGENTFORGE_CLINICAL_SMOKE_LAB_PATH', 'AGENTFORGE_CLINICAL_SMOKE_INTAKE_PATH', 'AGENTFORGE_VM_SSH_HOST'] as $name) {
            $this->priorEnv[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->priorEnv as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $value);
            }
        }

        parent::tearDown();
    }

    public function testPreflightRequiresCredentialsAndReadableFixtures(): void
    {
        $issues = \agentforge_clinical_smoke_preflight_issues([
            'username' => '',
            'password' => '',
            'lab_path' => '/missing/lab.pdf',
            'intake_path' => '/missing/intake.pdf',
        ]);

        $this->assertContains('AGENTFORGE_SMOKE_USER is required', $issues);
        $this->assertContains('AGENTFORGE_SMOKE_PASSWORD is required', $issues);
        $this->assertContains('lab_path does not point to a readable fixture', $issues);
        $this->assertContains('intake_path does not point to a readable fixture', $issues);
    }

    public function testPreflightRequiresSshHostForRemoteDeployedUrl(): void
    {
        $fixture = dirname(__DIR__, 4) . '/agent-forge/docs/example-documents/lab-results/p01-chen-lipid-panel.pdf';

        $issues = \agentforge_clinical_smoke_preflight_issues([
            'base_url' => 'https://openemr.example.test/',
            'username' => 'smoke',
            'password' => 'secret',
            'lab_path' => $fixture,
            'intake_path' => $fixture,
        ]);

        $this->assertContains('AGENTFORGE_VM_SSH_HOST is required when AGENTFORGE_DEPLOYED_URL points at a remote host', $issues);
    }

    public function testPreflightAllowsLocalhostWithoutSshHost(): void
    {
        $fixture = dirname(__DIR__, 4) . '/agent-forge/docs/example-documents/lab-results/p01-chen-lipid-panel.pdf';

        $issues = \agentforge_clinical_smoke_preflight_issues([
            'base_url' => 'http://127.0.0.1:8300/',
            'username' => 'smoke',
            'password' => 'secret',
            'lab_path' => $fixture,
            'intake_path' => $fixture,
        ]);

        $this->assertNotContains('AGENTFORGE_VM_SSH_HOST is required when AGENTFORGE_DEPLOYED_URL points at a remote host', $issues);
    }

    public function testArtifactRedactionRemovesRawClinicalContentKeys(): void
    {
        $redacted = \agentforge_clinical_smoke_redact_artifact([
            'question_text' => 'What changed?',
            'answer' => 'Raw answer',
            'raw_value' => '8.2',
            'quote' => 'patient text',
            'failure_detail' => 'p01-chen-intake-typed.pdf failed for Margaret Chen on 1970-01-01',
            'safe' => 'kept',
        ]);
        $encoded = json_encode($redacted, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('question_text', $encoded);
        $this->assertStringNotContainsString('answer', $encoded);
        $this->assertStringNotContainsString('raw_value', $encoded);
        $this->assertStringNotContainsString('quote', $encoded);
        $this->assertStringNotContainsString('p01-chen-intake-typed.pdf', $encoded);
        $this->assertStringNotContainsString('Margaret Chen', $encoded);
        $this->assertStringNotContainsString('1970-01-01', $encoded);
        $this->assertStringContainsString('kept', $encoded);
    }

    public function testSmokeReferencesHashOperationalIds(): void
    {
        $this->assertStringStartsWith('document:', \agentforge_clinical_smoke_ref('document', 123));
        $this->assertNotSame('document:123', \agentforge_clinical_smoke_ref('document', 123));
    }

    public function testQuestionEvaluationRequiresDocumentAndGuidelineCitations(): void
    {
        $this->assertSame([], \agentforge_clinical_smoke_evaluate_question([
            'status' => 'ok',
            'citation_details' => [
                ['source_type' => 'clinical_document_fact'],
                ['source_type' => 'guideline'],
            ],
        ]));
        $this->assertNotSame([], \agentforge_clinical_smoke_evaluate_question([
            'status' => 'ok',
            'citation_details' => [
                ['source_type' => 'guideline'],
            ],
        ]));
    }
}
