<?php

/**
 * Isolated tests for HL7 v2 message content normalization.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Document\Content;

use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\Document\Content\DocumentContentNormalizationException;
use OpenEMR\AgentForge\Document\Content\DocumentContentNormalizationRequest;
use OpenEMR\AgentForge\Document\Content\Hl7v2DocumentContentNormalizer;
use OpenEMR\AgentForge\Document\DocumentId;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\ExtractionErrorCode;
use OpenEMR\AgentForge\Document\Worker\DocumentLoadResult;
use OpenEMR\Tests\Isolated\AgentForge\Support\TickingMonotonicClock;
use PHPUnit\Framework\TestCase;

final class Hl7v2DocumentContentNormalizerTest extends TestCase
{
    public function testHl7v2NormalizerParsesSeparatorsFieldsComponentsRepetitionsAndStableAnchors(): void
    {
        $bytes = implode("\r", [
            'MSH|^~\&|LAB|ACME|OPENEMR|CLINIC|202605090930||ORU^R01|MSG-123|P|2.5.1',
            'PID|1||12345^^^ACME^MR||Chen^Margaret^L||19680312|F',
            'OBR|1|ORD-1|FILL-1|24323-8^BMP^LN',
            'OBX|1|NM|2951-2^Sodium^LN||142|mmol/L|135-145|N|||F',
            'NTE|1|L|Patient reports salt\F\fluid restriction~Repeat BMP next week',
        ]);
        $normalizer = new Hl7v2DocumentContentNormalizer(new TickingMonotonicClock([100, 112]));
        $request = new DocumentContentNormalizationRequest(
            new DocumentId(91),
            DocumentType::Hl7v2Message,
            new DocumentLoadResult($bytes, 'text/plain', 'chen-oru.hl7'),
        );

        $content = $normalizer->normalize($request, new Deadline(new TickingMonotonicClock([100]), 1_000));

        $this->assertTrue($normalizer->supports($request));
        $this->assertFalse($normalizer->supports(new DocumentContentNormalizationRequest(
            new DocumentId(92),
            DocumentType::LabPdf,
            new DocumentLoadResult($bytes, 'text/plain', 'chen-oru.hl7'),
        )));
        $this->assertSame('hl7v2', $content->telemetry()->normalizer);
        $this->assertSame(5, $content->telemetry()->messageSegmentCount);
        $this->assertSame(12, $content->telemetry()->normalizationElapsedMs);
        $this->assertSame([], $content->telemetry()->warningCodes);

        $msh = $content->messageSegments[0];
        $this->assertSame('message:MSG-123; MSH[1]', $msh->segmentId);
        $this->assertSame('MSH', $msh->segmentType);
        $this->assertSame('|', $msh->fields['MSH.1']);
        $this->assertSame('^~\&', $msh->fields['MSH.2']);
        $this->assertSame('ORU^R01', $msh->fields['MSH.9']);
        $this->assertSame('ORU', $msh->fields['MSH.9[1].1']);
        $this->assertSame('R01', $msh->fields['MSH.9[1].2']);
        $this->assertSame('MSG-123', $msh->fields['MSH.10']);

        $pid = $content->messageSegments[1];
        $this->assertSame('message:MSG-123; PID[1]', $pid->segmentId);
        $this->assertSame('12345^^^ACME^MR', $pid->fields['PID.3']);
        $this->assertSame('12345', $pid->fields['PID.3[1].1']);
        $this->assertSame('ACME', $pid->fields['PID.3[1].4']);
        $this->assertSame('MR', $pid->fields['PID.3[1].5']);
        $this->assertSame('Margaret', $pid->fields['PID.5[1].2']);

        $nte = $content->messageSegments[4];
        $this->assertSame('Patient reports salt|fluid restriction~Repeat BMP next week', $nte->fields['NTE.3']);
        $this->assertSame('Patient reports salt|fluid restriction', $nte->fields['NTE.3[1]']);
        $this->assertSame('Repeat BMP next week', $nte->fields['NTE.3[2]']);
    }

    public function testRealFixtureNormalizesWithMessageControlIdCitations(): void
    {
        $bytes = file_get_contents(__DIR__ . '/../../../../../../agent-forge/docs/example-documents/hl7v2/p01-chen-oru-r01.hl7');
        $this->assertIsString($bytes);
        $normalizer = new Hl7v2DocumentContentNormalizer(new TickingMonotonicClock([100, 100]));

        $content = $normalizer->normalize(new DocumentContentNormalizationRequest(
            new DocumentId(91),
            DocumentType::Hl7v2Message,
            new DocumentLoadResult($bytes, 'text/plain', 'p01-chen-oru-r01.hl7'),
        ), new Deadline(new TickingMonotonicClock([100]), 1_000));

        $this->assertSame('hl7v2', $content->telemetry()->normalizer);
        $this->assertGreaterThanOrEqual(4, $content->telemetry()->messageSegmentCount);
        $this->assertStringStartsWith('message:', $content->messageSegments[0]->segmentId);
        $this->assertStringContainsString('MSH[1]', $content->messageSegments[0]->segmentId);
        $this->assertStringContainsString('OBX[1]', json_encode($content->messageSegments, JSON_THROW_ON_ERROR));
    }

    public function testMalformedMissingMshAndMissingControlIdFailuresAreStableAndPhiSafe(): void
    {
        foreach ([
            'malformed' => 'Jane Doe raw bytes',
            'missing_msh' => "PID|1||12345||Doe^Jane\r",
            'missing_control_id' => "MSH|^~\\&|LAB|ACME|OPENEMR|CLINIC|202605090930||ADT^A08||P|2.5.1\rPID|1||12345||Doe^Jane",
            'oversized' => "MSH|^~\\&|LAB|ACME|OPENEMR|CLINIC|202605090930||ADT^A08|MSG-OVERSIZED|P|2.5.1\rPID|1||12345||Doe^Jane",
        ] as $case => $bytes) {
            $normalizer = new Hl7v2DocumentContentNormalizer(
                new TickingMonotonicClock([100]),
                maxSourceBytes: $case === 'oversized' ? 8 : 1_048_576,
            );
            $request = new DocumentContentNormalizationRequest(
                new DocumentId(91),
                DocumentType::Hl7v2Message,
                new DocumentLoadResult($bytes, 'text/plain', 'jane.hl7'),
            );

            try {
                $normalizer->normalize($request, new Deadline(new TickingMonotonicClock([100]), 1_000));
                $this->fail('Expected unsafe HL7 v2 message to fail safely.');
            } catch (DocumentContentNormalizationException $exception) {
                $this->assertSame(ExtractionErrorCode::NormalizationFailure, $exception->errorCode);
                $this->assertSame('HL7 v2 content normalization failed.', $exception->getMessage());
                $this->assertStringNotContainsString('Jane Doe', $exception->getMessage());
                $this->assertStringNotContainsString('jane.hl7', $exception->getMessage());
            }
        }
    }
}
