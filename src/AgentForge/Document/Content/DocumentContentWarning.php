<?php

/**
 * PHI-safe coded warning from content normalization.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Content;

use DomainException;

final readonly class DocumentContentWarning
{
    /** @var array<string, int|string|float|bool|null> */
    public array $safeContext;

    /** @param array<string, int|string|float|bool|null> $safeContext */
    public function __construct(
        public DocumentContentWarningCode $code,
        public string $scope,
        array $safeContext = [],
    ) {
        if (trim($scope) === '') {
            throw new DomainException('Document content warning scope must not be empty.');
        }

        $this->safeContext = $safeContext;
    }
}
