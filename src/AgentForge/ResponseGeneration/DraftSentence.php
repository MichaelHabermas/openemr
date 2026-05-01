<?php

/**
 * One sentence or bullet in a structured draft answer.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\ResponseGeneration;

use DomainException;

final readonly class DraftSentence
{
    public function __construct(
        public string $id,
        public string $text,
    ) {
        if (trim($id) === '') {
            throw new DomainException('Draft sentence id is required.');
        }
        if (trim($text) === '') {
            throw new DomainException('Draft sentence text is required.');
        }
    }
}
