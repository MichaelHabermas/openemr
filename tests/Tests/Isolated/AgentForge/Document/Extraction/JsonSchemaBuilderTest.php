<?php

/**
 * Isolated tests for AgentForge extraction JSON schema builder.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Document\Extraction;

use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\Extraction\JsonSchemaBuilder;
use PHPUnit\Framework\TestCase;

final class JsonSchemaBuilderTest extends TestCase
{
    public function testBuildsStrictExtractionSchemaForLabPdf(): void
    {
        $schema = (new JsonSchemaBuilder())->schema(DocumentType::LabPdf);

        $this->assertFalse($schema['additionalProperties']);
        $this->assertSame(['doc_type', 'lab_name', 'collected_at', 'patient_identity', 'results'], $schema['required']);
        $properties = $schema['properties'];
        $this->assertIsArray($properties);
        $results = $properties['results'] ?? null;
        $this->assertIsArray($results);
        $items = $results['items'] ?? null;
        $this->assertIsArray($items);
        $this->assertFalse($items['additionalProperties']);
        $itemProperties = $items['properties'] ?? null;
        $this->assertIsArray($itemProperties);
        $flag = $itemProperties['abnormal_flag'] ?? null;
        $this->assertIsArray($flag);
        $enum = $flag['enum'] ?? null;
        $this->assertIsArray($enum);
        $this->assertContains(
            'critical_high',
            $enum,
        );
    }

    public function testBuildsStrictExtractionSchemaForIntakeForm(): void
    {
        $schema = (new JsonSchemaBuilder())->schema(DocumentType::IntakeForm);

        $this->assertFalse($schema['additionalProperties']);
        $this->assertSame(['doc_type', 'form_name', 'patient_identity', 'findings'], $schema['required']);
        $properties = $schema['properties'];
        $this->assertIsArray($properties);
        $docType = $properties['doc_type'] ?? null;
        $this->assertIsArray($docType);
        $this->assertSame(['intake_form'], $docType['enum'] ?? null);
        $findings = $properties['findings'] ?? null;
        $this->assertIsArray($findings);
        $items = $findings['items'] ?? null;
        $this->assertIsArray($items);
        $this->assertFalse($items['additionalProperties']);
        $itemRequired = $items['required'] ?? null;
        $this->assertIsArray($itemRequired);
        $this->assertSame(
            ['field', 'value', 'certainty', 'confidence', 'citation'],
            $itemRequired,
        );
    }
}
