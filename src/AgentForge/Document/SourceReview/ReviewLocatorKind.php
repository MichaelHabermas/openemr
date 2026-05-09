<?php

/**
 * UI-facing review locator kinds for AgentForge cited document sources.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\SourceReview;

enum ReviewLocatorKind: string
{
    case ImageRegion = 'image_region';
    case PageQuote = 'page_quote';
    case TextAnchor = 'text_anchor';
    case TableCell = 'table_cell';
    case MessageField = 'message_field';

    public function hasPageImage(): bool
    {
        return match ($this) {
            self::ImageRegion, self::PageQuote => true,
            self::TextAnchor, self::TableCell, self::MessageField => false,
        };
    }
}
