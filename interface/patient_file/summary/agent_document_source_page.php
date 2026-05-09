<?php

/**
 * Guarded rendered-page preview for AgentForge document source citations.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

ob_start();

require_once("../../globals.php");
require_once(dirname(__DIR__, 3) . "/controllers/C_Document.class.php");

use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\Document\DocumentId;
use OpenEMR\AgentForge\Document\DocumentJobId;
use OpenEMR\AgentForge\Document\SourceReview\SourceDocumentAccessGate;
use OpenEMR\AgentForge\Document\StrictPositiveInt;
use OpenEMR\AgentForge\SqlQueryUtilsExecutor;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Core\OEGlobalsBag;
use Symfony\Component\Process\Process;

$fail = static function (int $status): never {
    if (ob_get_level() > 0) {
        ob_clean();
    }

    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Source page preview could not be rendered.';
    exit;
};

$emit = static function (string $bytes, string $mimeType): never {
    if (ob_get_level() > 0) {
        ob_clean();
    }

    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: private, no-store');
    echo $bytes;
    exit;
};

$session = SessionWrapperFactory::getInstance()->getActiveSession();
$sessionPatientId = StrictPositiveInt::tryParse($session->get('pid'));
$documentId = StrictPositiveInt::tryParse(filter_input(INPUT_GET, 'document_id'));
$jobId = StrictPositiveInt::tryParse(filter_input(INPUT_GET, 'job_id'));
$factId = StrictPositiveInt::tryParse(filter_input(INPUT_GET, 'fact_id'));
$pageNumber = StrictPositiveInt::tryParse(filter_input(INPUT_GET, 'page')) ?? 1;

if ($sessionPatientId === null || $documentId === null || $jobId === null) {
    $fail(400);
}

if (!AclMain::aclCheckCore('patients', 'med')) {
    $fail(403);
}

$executor = new SqlQueryUtilsExecutor();
if (!(new SourceDocumentAccessGate($executor))->allows(
    new PatientId($sessionPatientId),
    new DocumentId($documentId),
    new DocumentJobId($jobId),
    $factId,
)) {
    $fail(404);
}

$fixturePreviewPath = static function (string $documentName, int $pageNumber): ?string {
    $baseName = basename($documentName);
    if (!str_ends_with(strtolower($baseName), '.pdf')) {
        return null;
    }

    $slug = substr($baseName, 0, -4);
    $previewRoot = dirname(__DIR__, 3) . '/agent-forge/docs/example-documents/source-previews';
    $candidate = $previewRoot . '/' . $slug . '-page-' . $pageNumber . '.png';
    $realRoot = realpath($previewRoot);
    $realCandidate = realpath($candidate);

    if (
        $realRoot === false
        || $realCandidate === false
        || !str_starts_with($realCandidate, $realRoot . DIRECTORY_SEPARATOR)
        || !is_file($realCandidate)
    ) {
        return null;
    }

    return $realCandidate;
};

$document = new Document($documentId);
$mimeRaw = $document->get_mimetype();
$mimeType = strtolower(trim(is_string($mimeRaw) ? $mimeRaw : ''));
$previewPath = $fixturePreviewPath((string) $document->get_name(), $pageNumber);
if ($previewPath !== null) {
    $preview = file_get_contents($previewPath);
    if ($preview !== false && $preview !== '') {
        $emit($preview, 'image/png');
    }
}

$controller = new C_Document();
$controller->onReturnRetrieveKey();
$bytes = $controller->retrieve_action(
    (string) $sessionPatientId,
    $documentId,
    true,
    true,
    true,
    true,
);
$controller->offReturnRetrieveKey();

if (!is_string($bytes) || $bytes === '') {
    $fail(404);
}

if (str_starts_with($mimeType, 'image/')) {
    $emit($bytes, $mimeType);
}

if ($mimeType !== 'application/pdf') {
    $fail(415);
}

$temporaryDir = OEGlobalsBag::getInstance()->getString('temporary_files_dir');
$temporaryDirectory = $temporaryDir !== '' ? $temporaryDir : sys_get_temp_dir();
$tempBase = tempnam($temporaryDirectory, 'afsrc');
if ($tempBase === false) {
    $fail(500);
}

$pdfPath = $tempBase . '.pdf';
$pngPath = $tempBase . '.png';
file_put_contents($pdfPath, $bytes);

if (extension_loaded('imagick') && class_exists(\Imagick::class)) {
    try {
        $imagick = new \Imagick();
        $imagick->setResolution(150, 150);
        $imagick->readImage($pdfPath . '[' . ($pageNumber - 1) . ']');
        $imagick->setImageFormat('png');
        $imagick->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
        $png = $imagick->getImageBlob();
        $imagick->clear();
        $imagick->destroy();
        @unlink($tempBase);
        @unlink($pdfPath);
        $emit($png, 'image/png');
    } catch (\ImagickException) {
        // Fall through to the command-line renderer used by legacy document previews.
    }
}

$convertProcess = new Process([
    'convert', '-density', '150',
    $pdfPath . '[' . ($pageNumber - 1) . ']',
    '-background', 'white', '-alpha', 'remove',
    $pngPath,
]);
$convertProcess->run();
$status = $convertProcess->getExitCode() ?? 1;

if ($status !== 0 || !is_file($pngPath)) {
    $pdftoppmBase = $tempBase . '-page';
    $pdftoppmProcess = new Process([
        'pdftoppm', '-png', '-singlefile', '-r', '150',
        '-f', (string) $pageNumber,
        '-l', (string) $pageNumber,
        $pdfPath,
        $pdftoppmBase,
    ]);
    $pdftoppmProcess->run();
    $status = $pdftoppmProcess->getExitCode() ?? 1;
    if ($status === 0 && is_file($pdftoppmBase . '.png')) {
        @rename($pdftoppmBase . '.png', $pngPath);
    }
}

@unlink($tempBase);
@unlink($pdfPath);

if ($status !== 0 || !is_file($pngPath)) {
    @unlink($pngPath);
    $fail(500);
}

$png = file_get_contents($pngPath);
@unlink($pngPath);

if ($png === false || $png === '') {
    $fail(500);
}

$emit($png, 'image/png');
