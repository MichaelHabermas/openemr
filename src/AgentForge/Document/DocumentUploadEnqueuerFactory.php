<?php

/**
 * Default wiring for the AgentForge document upload enqueuer.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document;

use OpenEMR\AgentForge\Observability\PatientRefHasher;
use OpenEMR\AgentForge\SqlQueryUtilsExecutor;
use OpenEMR\BC\ServiceContainer;

final class DocumentUploadEnqueuerFactory
{
    public static function createDefault(): DocumentUploadEnqueuer
    {
        $executor = new SqlQueryUtilsExecutor();
        $logger = ServiceContainer::getLogger();

        return new DocumentUploadEnqueuer(
            mappings: new SqlDocumentTypeMappingRepository($executor, $logger),
            jobs: new SqlDocumentJobRepository($executor),
            logger: $logger,
            patientRefHasher: PatientRefHasher::createDefault(),
        );
    }
}
