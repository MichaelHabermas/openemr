<?php

/**
 * Isolated tests for deterministic HL7 v2 extraction.
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
use OpenEMR\AgentForge\Document\Extraction\Hl7v2MessageExtractionProvider;
use OpenEMR\AgentForge\Document\Schema\Hl7v2MessageExtraction;
use OpenEMR\AgentForge\Document\Worker\DocumentLoadResult;
use OpenEMR\AgentForge\Time\SystemMonotonicClock;
use PHPUnit\Framework\TestCase;

final class Hl7v2MessageExtractionProviderTest extends TestCase
{
    public function testExtractsAdtIdentityVisitAndMessageMetadataWithoutModel(): void
    {
        $document = $this->document("MSH|^~\\&|REGISTRATION|BHS|EHR^^L|EHR|20260506143215||ADT^A08^ADT_A01|MSG-ADT-1|P|2.5.1\r"
            . "EVN|A08|20260506143215|||1618829315^PARK^HELEN|Medication change recorded; ezetimibe added\r"
            . "PID|1||BHS-2847163^^^MRN^MR||CHEN^MARGARET^L||19680312|F\r"
            . "PV1|1|O|BHS IM CLINIC||||||||||||||||VN-CHEN-ADT\r");

        $response = (new Hl7v2MessageExtractionProvider())->extract(
            new DocumentId(77),
            $document,
            DocumentType::Hl7v2Message,
            $this->deadline(),
        );

        $this->assertSame('deterministic-hl7v2', $response->model);
        $this->assertInstanceOf(Hl7v2MessageExtraction::class, $response->extraction);
        $this->assertSame('ADT^A08', $response->extraction->messageType);
        $this->assertSame('MSG-ADT-1', $response->extraction->messageControlId);
        $this->assertSame('CHEN^MARGARET^L', $response->extraction->patientIdentity[0]->value);
        $this->assertSame('1968-03-12', $response->extraction->patientIdentity[1]->value);
        $this->assertSame('BHS-2847163', $response->extraction->patientIdentity[2]->value);
        $this->assertSame('visit_update', $response->facts[0]['type']);
        $citation = $this->citation($response->facts[0]);
        $this->assertSame('message:MSG-ADT-1', $citation['page_or_section']);
        $this->assertSame('EVN[1].7', $citation['field_or_chunk_id']);
        $this->assertStringStartsWith('sha256:', $citation['source_id']);
        $this->assertSame([], $response->warnings);
    }

    public function testExtractsOruOrdersObservationsAndNotesWithoutModel(): void
    {
        $document = $this->document("MSH|^~\\&|BHS-LIS|BHS LAB|EHR^^L|EHR|20260506143215||ORU^R01^ORU_R01|MSG-ORU-1|P|2.5.1\r"
            . "PID|1||BHS-2847163^^^MRN^MR||CHEN^MARGARET^L||19680312|F\r"
            . "ORC|RE|ORD-p01-0001|FIL-p01-0001\r"
            . "OBR|1|ORD-p01-0001|FIL-p01-0001|57698-3^Lipid panel with direct LDL - Serum or Plasma^LN|||20260412093000\r"
            . "OBX|1|NM|2093-3^Cholesterol [Mass/volume] in Serum or Plasma^LN||218|mg/dL|<200|H|||F|||20260412093000\r"
            . "OBX|2|NM|2089-1^Cholesterol in LDL [Mass/volume] in Serum or Plasma by Direct assay^LN||142|mg/dL|<100|H|||F|||20260412093000\r"
            . "NTE|1|L|Repeat lipid panel in 6 weeks after intensification of therapy.\r");

        $response = (new Hl7v2MessageExtractionProvider())->extract(
            new DocumentId(78),
            $document,
            DocumentType::Hl7v2Message,
            $this->deadline(),
        );

        $this->assertInstanceOf(Hl7v2MessageExtraction::class, $response->extraction);
        $this->assertSame('ORU^R01', $response->extraction->messageType);
        $this->assertSame('MSG-ORU-1', $response->extraction->messageControlId);
        $types = array_column($response->facts, 'type');
        $this->assertContains('order', $types);
        $this->assertContains('observation_order', $types);
        $this->assertContains('lab_result', $types);
        $this->assertContains('note', $types);
        $ldl = $response->facts[3];
        $this->assertSame('lab_result', $ldl['type']);
        $this->assertSame('OBX[2].5', $ldl['field_path']);
        $this->assertSame('Cholesterol in LDL [Mass/volume] in Serum or Plasma by Direct assay', $ldl['label']);
        $this->assertSame('142 mg/dL abnormal h collected 20260412093000', $ldl['value']);
        $this->assertSame('OBX[2].5', $this->citation($ldl)['field_or_chunk_id']);
        $this->assertSame('NTE[1].3', $this->citation($response->facts[4])['field_or_chunk_id']);
        $this->assertSame('hl7v2', $response->normalizationTelemetry['normalizer'] ?? null);
    }

    public function testExtractsWithDeclaredSeparatorsAndCorrectMshIndexing(): void
    {
        $document = $this->document("MSH*#$%!*BHS-LIS*BHS LAB*EHR##L*EHR*20260506143215**ORU#R01#ORU_R01*MSG-ORU-SEP*P*2.5.1\r"
            . "PID*1**BHS-2847163###MRN#MR**CHEN#MARGARET#L**19680312*F\r"
            . "OBR*1*ORD-p01-0001*FIL-p01-0001*57698-3#Lipid panel with direct LDL - Serum or Plasma#LN\r"
            . "OBX*1*NM*2089-1#Cholesterol in LDL [Mass/volume] in Serum or Plasma by Direct assay#LN**142*mg/dL*<100*H|||F|||20260412093000\r");

        $response = (new Hl7v2MessageExtractionProvider())->extract(
            new DocumentId(83),
            $document,
            DocumentType::Hl7v2Message,
            $this->deadline(),
        );

        $this->assertInstanceOf(Hl7v2MessageExtraction::class, $response->extraction);
        $this->assertSame('ORU^R01', $response->extraction->messageType);
        $this->assertSame('MSG-ORU-SEP', $response->extraction->messageControlId);
        $this->assertSame('BHS-2847163', $response->extraction->patientIdentity[2]->value);
        $this->assertSame('Lipid panel with direct LDL - Serum or Plasma', $response->facts[0]['label']);
        $this->assertSame('Cholesterol in LDL [Mass/volume] in Serum or Plasma by Direct assay', $response->facts[1]['label']);
        $this->assertSame('OBX[1].5', $response->facts[1]['field_path']);
    }

    public function testSelectsMrnIdentifierRepetitionByIdentifierType(): void
    {
        $document = $this->document("MSH|^~\\&|BHS-LIS|BHS LAB|EHR^^L|EHR|20260506143215||ORU^R01|MSG-ORU-MRN|P|2.5.1\r"
            . "PID|1||ALT-999^^^BHS^PI~BHS-2847163^^^BHS^MR||CHEN^MARGARET^L||19680312|F\r"
            . "OBX|1|NM|2089-1^LDL^LN||142|mg/dL|<100|H\r");

        $response = (new Hl7v2MessageExtractionProvider())->extract(
            new DocumentId(84),
            $document,
            DocumentType::Hl7v2Message,
            $this->deadline(),
        );

        $this->assertInstanceOf(Hl7v2MessageExtraction::class, $response->extraction);
        $this->assertSame('BHS-2847163', $response->extraction->patientIdentity[2]->value);
    }

    public function testIdentityOnlyAdtProducesSchemaValidPatientIdentityFact(): void
    {
        $document = $this->document("MSH|^~\\&|REGISTRATION|BHS|EHR^^L|EHR|20260506143215||ADT^A08|MSG-ADT-IDENTITY|P|2.5.1\r"
            . "PID|1||BHS-2847163^^^BHS^MR||CHEN^MARGARET^L||19680312|F\r");

        $response = (new Hl7v2MessageExtractionProvider())->extract(
            new DocumentId(85),
            $document,
            DocumentType::Hl7v2Message,
            $this->deadline(),
        );

        $this->assertTrue($response->schemaValid);
        $this->assertSame('patient_identity_update', $response->facts[0]['type']);
        $this->assertSame('PID[1]', $this->citation($response->facts[0])['field_or_chunk_id']);
    }

    public function testEscapedHl7ValuesAreDecodedBeforeExtraction(): void
    {
        $document = $this->document("MSH|^~\\&|BHS-LIS|BHS LAB|EHR^^L|EHR|20260506143215||ORU^R01|MSG-ORU-ESCAPE|P|2.5.1\r"
            . "PID|1||BHS-2847163^^^BHS^MR||CHEN^MARGARET^L||19680312|F\r"
            . "OBX|1|TX|NOTE^Result note^L||salt\\F\\fluid|text|||||F|||20260412093000\r");

        $response = (new Hl7v2MessageExtractionProvider())->extract(
            new DocumentId(86),
            $document,
            DocumentType::Hl7v2Message,
            $this->deadline(),
        );

        $this->assertSame('salt|fluid text collected 20260412093000', $response->facts[0]['value']);
    }

    public function testMalformedHl7FailsClosed(): void
    {
        $this->expectException(ExtractionProviderException::class);
        $this->expectExceptionMessage('HL7 v2 content normalization failed.');

        (new Hl7v2MessageExtractionProvider())->extract(
            new DocumentId(79),
            $this->document("PID|1||BHS-2847163\r"),
            DocumentType::Hl7v2Message,
            $this->deadline(),
        );
    }

    public function testMissingPidFailsClosed(): void
    {
        $this->expectException(ExtractionProviderException::class);
        $this->expectExceptionMessage('missing PID');

        (new Hl7v2MessageExtractionProvider())->extract(
            new DocumentId(80),
            $this->document("MSH|^~\\&|BHS-LIS|BHS LAB|EHR^^L|EHR|20260506143215||ORU^R01^ORU_R01|MSG-ORU-1|P|2.5.1\r"),
            DocumentType::Hl7v2Message,
            $this->deadline(),
        );
    }

    public function testUnsupportedMessageTypeFailsClosed(): void
    {
        $this->expectException(ExtractionProviderException::class);
        $this->expectExceptionMessage('message type is not supported');

        (new Hl7v2MessageExtractionProvider())->extract(
            new DocumentId(81),
            $this->document("MSH|^~\\&|BHS-LIS|BHS LAB|EHR^^L|EHR|20260506143215||ORM^O01|MSG-ORM-1|P|2.5.1\r"
                . "PID|1||BHS-2847163^^^MRN^MR||CHEN^MARGARET^L||19680312|F\r"),
            DocumentType::Hl7v2Message,
            $this->deadline(),
        );
    }

    public function testDuplicateObxRowsFailClosed(): void
    {
        $this->expectException(ExtractionProviderException::class);
        $this->expectExceptionMessage('duplicate OBX rows');

        (new Hl7v2MessageExtractionProvider())->extract(
            new DocumentId(82),
            $this->document("MSH|^~\\&|BHS-LIS|BHS LAB|EHR^^L|EHR|20260506143215||ORU^R01|MSG-ORU-DUP|P|2.5.1\r"
                . "PID|1||BHS-2847163^^^MRN^MR||CHEN^MARGARET^L||19680312|F\r"
                . "OBX|1|NM|2093-3^Cholesterol^LN||218|mg/dL|<200|H\r"
                . "OBX|2|NM|2093-3^Cholesterol^LN||218|mg/dL|<200|H\r"),
            DocumentType::Hl7v2Message,
            $this->deadline(),
        );
    }

    public function testObxSetIdsMayRestartAcrossOrderGroups(): void
    {
        $response = (new Hl7v2MessageExtractionProvider())->extract(
            new DocumentId(87),
            $this->document("MSH|^~\\&|BHS-LIS|BHS LAB|EHR^^L|EHR|20260506143215||ORU^R01|MSG-ORU-RESTART|P|2.5.1\r"
                . "PID|1||BHS-2847163^^^MRN^MR||CHEN^MARGARET^L||19680312|F\r"
                . "OBR|1|||57698-3^Lipid panel^LN\r"
                . "OBX|1|NM|2093-3^Cholesterol^LN||218|mg/dL|<200|H\r"
                . "OBR|2|||24331-1^Metabolic panel^LN\r"
                . "OBX|1|NM|2951-2^Sodium^LN||140|mmol/L|135-145|N\r"),
            DocumentType::Hl7v2Message,
            $this->deadline(),
        );

        $this->assertTrue($response->schemaValid);
        $this->assertCount(4, $response->facts);
    }

    private function document(string $bytes): DocumentLoadResult
    {
        return new DocumentLoadResult($bytes, 'text/plain', 'message.hl7');
    }

    private function deadline(): Deadline
    {
        return new Deadline(new SystemMonotonicClock(), 8000);
    }

    /**
     * @param array<string, mixed> $fact
     * @return array<string, string>
     */
    private function citation(array $fact): array
    {
        $citation = $fact['citation'] ?? null;
        $this->assertIsArray($citation);

        $strings = [];
        foreach ($citation as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $strings[$key] = $value;
            }
        }

        return $strings;
    }
}
