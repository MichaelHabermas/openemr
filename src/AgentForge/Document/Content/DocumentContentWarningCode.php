<?php

/**
 * Stable warning codes emitted by document content normalizers.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Content;

enum DocumentContentWarningCode: string
{
    case PageLimitExceeded = 'page_limit_exceeded';
    case ByteLimitExceeded = 'byte_limit_exceeded';
    case UnsupportedEmbeddedObject = 'unsupported_embedded_object';
    case EmptyTextLayer = 'empty_text_layer';
    case RenderedPageLimitApplied = 'rendered_page_limit_applied';
    case UnsupportedMimeType = 'unsupported_mime_type';
}
