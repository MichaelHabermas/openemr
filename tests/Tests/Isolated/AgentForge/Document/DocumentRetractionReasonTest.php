<?php

/**
 * Isolated tests for AgentForge document retraction reasons.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Document;

use DomainException;
use OpenEMR\AgentForge\Document\DocumentRetractionReason;
use PHPUnit\Framework\TestCase;

final class DocumentRetractionReasonTest extends TestCase
{
    public function testKnownReasonHydratesFromStorageValue(): void
    {
        $input = implode('_', ['source', 'document', 'deleted']);

        DocumentRetractionReason::fromStringOrThrow($input);
        $this->addToAssertionCount(1);
    }

    public function testUnknownReasonThrowsDomainException(): void
    {
        $this->expectException(DomainException::class);

        DocumentRetractionReason::fromStringOrThrow('wrong_patient_detected');
    }
}
