<?php

/**
 * Isolated tests for normalized document content value objects.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Document\Content;

use DomainException;
use OpenEMR\AgentForge\Document\Content\DocumentContentWarning;
use OpenEMR\AgentForge\Document\Content\DocumentContentWarningCode;
use OpenEMR\AgentForge\Document\Content\NormalizedDocumentContent;
use OpenEMR\AgentForge\Document\Content\NormalizedDocumentSource;
use OpenEMR\AgentForge\Document\Content\NormalizedRenderedPage;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\Worker\DocumentLoadResult;
use OpenEMR\AgentForge\Observability\SensitiveLogPolicy;
use PHPUnit\Framework\TestCase;

final class NormalizedDocumentContentTest extends TestCase
{
    public function testTelemetryIsPhiSafeAggregateOnly(): void
    {
        $source = NormalizedDocumentSource::fromLoadResult(
            new DocumentLoadResult('pdf-bytes', 'application/pdf', 'chen-lab.pdf'),
            DocumentType::LabPdf,
        );

        $content = new NormalizedDocumentContent(
            $source,
            renderedPages: [new NormalizedRenderedPage(1, 'image/png', 'page-bytes')],
            warnings: [
                new DocumentContentWarning(
                    DocumentContentWarningCode::RenderedPageLimitApplied,
                    'pdf',
                    ['max_pages' => 1],
                ),
            ],
            normalizer: 'pdf',
            normalizationElapsedMs: 17,
        );

        $telemetry = $content->telemetry()->toLogContext();

        $this->assertSame('pdf', $telemetry['normalizer']);
        $this->assertSame('application/pdf', $telemetry['source_mime_type']);
        $this->assertSame(strlen('pdf-bytes'), $telemetry['source_byte_count']);
        $this->assertSame(1, $telemetry['rendered_page_count']);
        $this->assertSame(['rendered_page_limit_applied'], $telemetry['warning_codes']);
        $this->assertSame(17, $telemetry['normalization_elapsed_ms']);
        $this->assertArrayNotHasKey('name', $telemetry);
        $this->assertArrayNotHasKey('document_text', $telemetry);
        $this->assertSame($telemetry, SensitiveLogPolicy::sanitizeContext($telemetry));
    }

    public function testEmptyContentIsRejected(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('at least one content part');

        new NormalizedDocumentContent(new NormalizedDocumentSource(
            'empty.pdf',
            'application/pdf',
            str_repeat('a', 64),
            1,
            DocumentType::LabPdf,
        ));
    }
}
