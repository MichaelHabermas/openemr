<?php

/**
 * Stable M4 extraction error codes.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document;

enum ExtractionErrorCode: string
{
    case UnsupportedDocType = 'unsupported_doc_type';
    case MissingFile = 'missing_file';
    case StorageFailure = 'storage_failure';
    case ExtractionFailure = 'extraction_failure';
    case SchemaValidationFailure = 'schema_validation_failure';
}
