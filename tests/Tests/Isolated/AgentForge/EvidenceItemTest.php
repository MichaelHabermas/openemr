<?php

/**
 * Isolated tests for AgentForge evidence contract.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge;

use DomainException;
use OpenEMR\AgentForge\EvidenceItem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EvidenceItemTest extends TestCase
{
    public function testValidEvidenceItemHasStableJsonShape(): void
    {
        $item = new EvidenceItem(
            'lab',
            'procedure_result',
            'agentforge-a1c-2026-04',
            '2026-04-10',
            'Hemoglobin A1c',
            '7.4 %',
        );

        $this->assertSame(
            [
                'source_type' => 'lab',
                'source_table' => 'procedure_result',
                'source_id' => 'agentforge-a1c-2026-04',
                'source_date' => '2026-04-10',
                'display_label' => 'Hemoglobin A1c',
                'value' => '7.4 %',
            ],
            $item->toArray(),
        );
        $this->assertSame('lab:procedure_result/agentforge-a1c-2026-04@2026-04-10', $item->citation());
        $this->assertJson(json_encode($item->toArray(), JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, array{int}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function requiredFieldProvider(): array
    {
        return [
            'source type' => [0],
            'source table' => [1],
            'source id' => [2],
            'source date' => [3],
            'display label' => [4],
            'value' => [5],
        ];
    }

    #[DataProvider('requiredFieldProvider')]
    public function testMissingSourceMetadataCannotPass(int $blankIndex): void
    {
        $values = [
            'lab',
            'procedure_result',
            'agentforge-a1c-2026-04',
            '2026-04-10',
            'Hemoglobin A1c',
            '7.4 %',
        ];
        $values[$blankIndex] = '   ';

        $this->expectException(DomainException::class);

        new EvidenceItem(...$values);
    }

    /**
     * @return array<string, array{string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function invalidSourceDateProvider(): array
    {
        return [
            'natural language' => ['not-a-date'],
            'invalid month and day' => ['2026-13-99'],
            'datetime instead of date' => ['2026-04-10 12:00:00'],
        ];
    }

    #[DataProvider('invalidSourceDateProvider')]
    public function testSourceDateMustBeYmdOrUnknown(string $sourceDate): void
    {
        $this->expectException(DomainException::class);

        new EvidenceItem(
            'lab',
            'procedure_result',
            'agentforge-a1c-2026-04',
            $sourceDate,
            'Hemoglobin A1c',
            '7.4 %',
        );
    }

    public function testUnknownSourceDateIsExplicitlyAllowed(): void
    {
        $item = new EvidenceItem(
            'lab',
            'procedure_result',
            'agentforge-a1c-2026-04',
            'unknown',
            'Hemoglobin A1c',
            '7.4 %',
        );

        $this->assertSame('unknown', $item->sourceDate);
    }
}
