<?php

/**
 * Isolated source-contract tests for clinical document integration points.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Document;

use PHPUnit\Framework\TestCase;

final class DocumentIntegrationWiringTest extends TestCase
{
    public function testControllerUploadProcessDispatchesClinicalDocumentHookOnce(): void
    {
        $source = $this->readProjectFile('/controllers/C_Document.class.php');

        $this->assertSame(1, substr_count($source, 'DocumentUploadEnqueuerHook::dispatch('));
        $this->assertStringContainsString('$d->createDocument(', $source);
        $this->assertStringContainsString(
            'DocumentUploadEnqueuerHook::dispatch($patient_id, $category_id, [\'doc_id\' => $d->get_id()]);',
            $source,
        );
    }

    public function testAddNewDocumentUsesControllerUploadProcess(): void
    {
        $source = $this->readProjectFile('/library/documents.php');

        $this->assertStringContainsString('function addNewDocument(', $source);
        $this->assertStringContainsString('$cd->upload_action_process();', $source);
    }

    public function testAjaxUploadDoesNotDispatchOutsideAddNewDocument(): void
    {
        $source = $this->readProjectFile('/library/ajax/upload.php');

        $this->assertStringNotContainsString('DocumentUploadEnqueuerHook', $source);
        $this->assertStringContainsString('addNewDocument(', $source);
    }

    public function testDeletePathRetractsAfterDocumentIsMarkedDeleted(): void
    {
        $source = $this->readProjectFile('/interface/patient_file/deleter.php');
        $deletePosition = strpos($source, 'UPDATE `documents` SET `deleted` = 1 WHERE id = ?');
        $retractPosition = strpos($source, 'DocumentRetractionHook::dispatch($document);');

        $this->assertIsInt($deletePosition);
        $this->assertIsInt($retractPosition);
        $this->assertGreaterThan($deletePosition, $retractPosition);
    }

    public function testDocumentDeleteRelationCleanupDoesNotEchoSql(): void
    {
        $source = $this->readProjectFile('/interface/patient_file/deleter.php');

        $this->assertStringContainsString(
            'deleter_row_delete("categories_to_documents", "document_id = ?", [$document], false);',
            $source,
        );
        $this->assertStringContainsString(
            'deleter_row_delete("gprelations", "type1 = 1 AND id1 = ?", [$document], false);',
            $source,
        );
    }

    public function testDirectDocumentDeleteChecksActivePatientOwnership(): void
    {
        $source = $this->readProjectFile('/interface/patient_file/deleter.php');

        $this->assertStringContainsString('SELECT foreign_id FROM documents WHERE id = ?', $source);
        $this->assertStringContainsString(
            '$activePatient = filter_var($session->get(\'pid\'), FILTER_VALIDATE_INT);',
            $source,
        );
    }

    private function readProjectFile(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 5) . $path);
        $this->assertIsString($contents);

        return $contents;
    }
}
