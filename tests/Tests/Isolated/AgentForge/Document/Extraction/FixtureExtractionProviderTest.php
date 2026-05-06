<?php

/**
 * Isolated tests for AgentForge fixture extraction provider.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Document\Extraction;

use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\Document\DocumentId;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\Extraction\ExtractionProviderException;
use OpenEMR\AgentForge\Document\Extraction\FixtureExtractionProvider;
use OpenEMR\AgentForge\Document\Schema\LabPdfExtraction;
use OpenEMR\AgentForge\Document\Worker\DocumentLoadResult;
use OpenEMR\AgentForge\SystemAgentForgeClock;
use PHPUnit\Framework\TestCase;

final class FixtureExtractionProviderTest extends TestCase
{
    public function testLooksUpExtractionByDocumentSha256(): void
    {
        $document = new DocumentLoadResult('fixture-pdf-bytes', 'application/pdf', 'lab.pdf');
        $manifestPath = $this->writeManifest([
            hash('sha256', $document->bytes) => [
                'doc_type' => 'lab_pdf',
                'lab_name' => 'Acme Lab',
                'collected_at' => '2026-04-01',
                'patient_identity' => [],
                'results' => [
                    [
                        'test_name' => 'LDL',
                        'value' => '91 mg/dL',
                        'unit' => 'mg/dL',
                        'reference_range' => '<100 mg/dL',
                        'collected_at' => '2026-04-01',
                        'abnormal_flag' => 'normal',
                        'certainty' => 'verified',
                        'confidence' => 0.97,
                        'citation' => [
                            'source_type' => 'lab_pdf',
                            'source_id' => 'sha256:' . hash('sha256', $document->bytes),
                            'page_or_section' => 'page 1',
                            'field_or_chunk_id' => 'results[0]',
                            'quote_or_value' => 'LDL 91 mg/dL',
                            'bounding_box' => ['x' => 0.1, 'y' => 0.2, 'width' => 0.3, 'height' => 0.4],
                        ],
                    ],
                ],
            ],
        ]);

        $response = (new FixtureExtractionProvider($manifestPath))->extract(
            new DocumentId(123),
            $document,
            DocumentType::LabPdf,
            $this->deadline(),
        );

        $this->assertTrue($response->schemaValid);
        $this->assertSame('LDL', $response->facts[0]['label']);
        $this->assertSame('verified', $response->facts[0]['certainty']);
        $this->assertSame([], $response->warnings);
        $this->assertSame('fixture', $response->model);
    }

    public function testMissingShaFailsClosed(): void
    {
        $this->expectException(ExtractionProviderException::class);
        $this->expectExceptionMessage('No fixture extraction found');

        (new FixtureExtractionProvider($this->writeManifest([])))->extract(
            new DocumentId(123),
            new DocumentLoadResult('unknown', 'application/pdf', 'unknown.pdf'),
            DocumentType::LabPdf,
            $this->deadline(),
        );
    }

    public function testLoadsExtractionFromFixturesBySha256SidecarFiles(): void
    {
        $document = new DocumentLoadResult('sidecar-doc-bytes', 'application/pdf', 'lab.pdf');
        $sha = hash('sha256', $document->bytes);

        $dir = sys_get_temp_dir() . '/af-fix-sidecar-' . bin2hex(random_bytes(4));
        if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
            $this->fail('Unable to create temp directory.');
        }

        $sidecarPath = $dir . '/extraction-entry.json';
        file_put_contents($sidecarPath, json_encode([
            'doc_type' => 'lab_pdf',
            'lab_name' => 'Sidecar Lab',
            'collected_at' => '2026-04-01',
            'patient_identity' => [],
            'results' => [
                [
                    'test_name' => 'HDL',
                    'value' => '60',
                    'unit' => 'mg/dL',
                    'reference_range' => '>40',
                    'collected_at' => '2026-04-01',
                    'abnormal_flag' => 'normal',
                    'certainty' => 'verified',
                    'confidence' => 0.96,
                    'citation' => [
                        'source_type' => 'lab_pdf',
                        'source_id' => 'sha256:' . $sha,
                        'page_or_section' => 'page 1',
                        'field_or_chunk_id' => 'results[0]',
                        'quote_or_value' => 'HDL 60',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $manifestPath = $dir . '/manifest.json';
        file_put_contents($manifestPath, json_encode([
            'fixtures_by_sha256' => [
                $sha => 'extraction-entry.json',
            ],
        ], JSON_THROW_ON_ERROR));

        try {
            $response = (new FixtureExtractionProvider($manifestPath))->extract(
                new DocumentId(200),
                $document,
                DocumentType::LabPdf,
                $this->deadline(),
            );
        } finally {
            @unlink($sidecarPath);
            @unlink($manifestPath);
            @rmdir($dir);
        }

        $this->assertTrue($response->schemaValid);
        $this->assertSame('HDL', $response->facts[0]['label']);
        $this->assertInstanceOf(LabPdfExtraction::class, $response->extraction);
        $this->assertSame('Sidecar Lab', $response->extraction->labName);
    }

    /** @param array<string, mixed> $documents */
    private function writeManifest(array $documents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'agentforge-extraction-fixture-');
        if ($path === false) {
            $this->fail('Unable to create temporary manifest.');
        }

        file_put_contents($path, json_encode(['documents' => $documents], JSON_THROW_ON_ERROR));

        return $path;
    }

    private function deadline(): Deadline
    {
        return new Deadline(new SystemAgentForgeClock(), 8000);
    }
}
