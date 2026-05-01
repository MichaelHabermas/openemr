<?php

/**
 * Verifiable claim inside a structured draft answer.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\ResponseGeneration;

use DomainException;

final readonly class DraftClaim
{
    public const TYPE_PATIENT_FACT = 'patient_fact';
    public const TYPE_MISSING_DATA = 'missing_data';
    public const TYPE_REFUSAL = 'refusal';
    public const TYPE_WARNING = 'warning';

    private const ALLOWED_TYPES = [
        self::TYPE_PATIENT_FACT,
        self::TYPE_MISSING_DATA,
        self::TYPE_REFUSAL,
        self::TYPE_WARNING,
    ];

    /** @var list<string> */
    public array $citedSourceIds;

    /** @param list<mixed> $citedSourceIds */
    public function __construct(
        public string $text,
        public string $type,
        array $citedSourceIds,
        public string $sentenceId,
    ) {
        if (trim($text) === '') {
            throw new DomainException('Draft claim text is required.');
        }
        if (trim($sentenceId) === '') {
            throw new DomainException('Draft claim sentence id is required.');
        }
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            throw new DomainException('Draft claim type is not supported.');
        }
        $validatedSourceIds = [];
        foreach ($citedSourceIds as $sourceId) {
            if (!is_string($sourceId)) {
                throw new DomainException('Draft claim cited source ids must be strings.');
            }
            if (trim($sourceId) === '') {
                throw new DomainException('Draft claim cited source id is required.');
            }
            $validatedSourceIds[] = $sourceId;
        }
        if ($type === self::TYPE_PATIENT_FACT && $validatedSourceIds === []) {
            throw new DomainException('Patient-specific draft claims require cited source ids.');
        }

        $this->citedSourceIds = $validatedSourceIds;
    }
}
