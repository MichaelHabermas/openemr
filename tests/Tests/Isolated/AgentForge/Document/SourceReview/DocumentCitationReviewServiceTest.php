<?php

/**
 * Isolated tests for AgentForge document citation source-review payloads.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Document\SourceReview;

use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\DatabaseExecutor;
use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\Document\DocumentId;
use OpenEMR\AgentForge\Document\DocumentJobId;
use OpenEMR\AgentForge\Document\SourceReview\DocumentCitationReviewService;
use OpenEMR\AgentForge\Document\SourceReview\SourceDocumentAccessGate;
use OpenEMR\AgentForge\Document\SourceReview\SourceReviewPresenter;
use PHPUnit\Framework\TestCase;

final class DocumentCitationReviewServiceTest extends TestCase
{
    public function testBoundingBoxCitationReturnsReviewPayload(): void
    {
        $executor = new SourceReviewExecutor($this->results([['id' => 17]], [$this->factRow()]));
        $service = new DocumentCitationReviewService(
            $executor,
            new SourceDocumentAccessGate($executor),
            presenter: new SourceReviewPresenter(sourceUrlBase: 'doc.php?retrieve'),
        );

        $review = $service->review(new PatientId(900101), new DocumentId(11), new DocumentJobId(7), 41);

        $this->assertNotNull($review);
        $payload = $review->toArray();
        $this->assertArrayNotHasKey('review_mode', $payload);
        $this->assertSame('image_region', $payload['locator']['kind']);
        $this->assertSame(11, $payload['document_id']);
        $this->assertSame(7, $payload['job_id']);
        $this->assertSame(41, $payload['fact_id']);
        $this->assertSame(1, $payload['page_number']);
        $this->assertSame('page 1', $payload['page_or_section']);
        $this->assertSame('results[0]', $payload['field_or_chunk_id']);
        $this->assertSame('LDL Cholesterol 148 mg/dL', $payload['quote_or_value']);
        $this->assertArrayHasKey('bounding_box', $payload['locator']);
        $this->assertSame(['x' => 0.1, 'y' => 0.2, 'width' => 0.3, 'height' => 0.08], $payload['locator']['bounding_box']);
        $this->assertStringContainsString('patient_id=900101', $payload['document_url']);
        $this->assertStringContainsString('document_id=11', $payload['document_url']);
        $this->assertArrayHasKey('page_image_url', $payload);
        $this->assertStringContainsString('agent_document_source_page.php', $payload['page_image_url']);
        $this->assertStringContainsString('document_id=11', $payload['page_image_url']);
        $this->assertStringContainsString('page=1', $payload['page_image_url']);
    }

    public function testMissingBoundingBoxReturnsDeterministicFallback(): void
    {
        $row = $this->factRow([
            'page_or_section' => 'chief concern',
            'field_or_chunk_id' => 'chief_concern',
            'quote_or_value' => 'Follow-up for fatigue',
            'bounding_box' => null,
        ]);
        $executor = new SourceReviewExecutor($this->results([['id' => 17]], [$row]));
        $service = new DocumentCitationReviewService($executor, new SourceDocumentAccessGate($executor));

        $review = $service->review(new PatientId(900101), new DocumentId(11), new DocumentJobId(7), 41);

        $this->assertNotNull($review);
        $payload = $review->toArray();
        $this->assertArrayNotHasKey('review_mode', $payload);
        $this->assertSame('page_quote', $payload['locator']['kind']);
        $this->assertNull($payload['page_number']);
        $this->assertArrayNotHasKey('bounding_box', $payload['locator']);
        $this->assertSame('chief concern', $payload['page_or_section']);
        $this->assertSame('Follow-up for fatigue', $payload['quote_or_value']);
    }

    public function testMalformedPageLabelDoesNotCrash(): void
    {
        $row = $this->factRow(['page_or_section' => 'scan header']);
        $executor = new SourceReviewExecutor($this->results([['id' => 17]], [$row]));
        $service = new DocumentCitationReviewService($executor, new SourceDocumentAccessGate($executor));

        $review = $service->review(new PatientId(900101), new DocumentId(11), new DocumentJobId(7), 41);

        $this->assertNotNull($review);
        $this->assertNull($review->toArray()['page_number']);
    }

    public function testDoesNotApplyFixtureSpecificCitationCorrections(): void
    {
        $row = $this->factRow([
            'source_type' => 'intake_form',
            'page_or_section' => 'page 1',
            'field_or_chunk_id' => 'needs_review[0]',
            'quote_or_value' => 'shellfish?? maybe iodine itchy?',
            'bounding_box' => ['x' => 0.126, 'y' => 0.300, 'width' => 0.110, 'height' => 0.026],
        ], 'intake_form');
        $executor = new SourceReviewExecutor($this->results([['id' => 17]], [$row]));
        $service = new DocumentCitationReviewService($executor, new SourceDocumentAccessGate($executor));

        $review = $service->review(new PatientId(900101), new DocumentId(22), new DocumentJobId(7), 41);

        $this->assertNotNull($review);
        $payload = $review->toArray();
        $this->assertSame('image_region', $payload['locator']['kind']);
        $this->assertSame(1, $payload['page_number']);
        $this->assertSame('page 1', $payload['page_or_section']);
        $this->assertArrayHasKey('page_image_url', $payload);
        $this->assertStringContainsString('page=1', $payload['page_image_url']);
        $this->assertArrayHasKey('bounding_box', $payload['locator']);
        $this->assertSame(['x' => 0.126, 'y' => 0.300, 'width' => 0.110, 'height' => 0.026], $payload['locator']['bounding_box']);
    }

    public function testReferralDocxReturnsTextAnchorLocatorWithoutPageImage(): void
    {
        $row = $this->factRow([
            'source_type' => 'referral_docx',
            'page_or_section' => 'section:reason-for-referral',
            'field_or_chunk_id' => 'paragraph:3',
            'quote_or_value' => 'Cardiology consult requested',
            'bounding_box' => null,
        ], 'referral_docx');
        $executor = new SourceReviewExecutor($this->results([['id' => 17]], [$row]));
        $service = new DocumentCitationReviewService($executor, new SourceDocumentAccessGate($executor));

        $review = $service->review(new PatientId(900101), new DocumentId(55), new DocumentJobId(7), 41);

        $this->assertNotNull($review);
        $payload = $review->toArray();
        $this->assertSame('text_anchor', $payload['locator']['kind']);
        $this->assertSame('section:reason-for-referral', $payload['locator']['section']);
        $this->assertSame('paragraph:3', $payload['locator']['anchor']);
        $this->assertArrayNotHasKey('bounding_box', $payload['locator']);
        $this->assertArrayNotHasKey('page_image_url', $payload);
        $this->assertNull($payload['page_number']);
        $this->assertSame('Cardiology consult requested', $payload['quote_or_value']);
    }

    public function testClinicalWorkbookReturnsTableCellLocatorWithoutPageImage(): void
    {
        $row = $this->factRow([
            'source_type' => 'clinical_workbook',
            'page_or_section' => 'sheet:Labs_Trend',
            'field_or_chunk_id' => 'Care_Gaps!A4:F4',
            'quote_or_value' => 'Overdue screening',
            'bounding_box' => null,
        ], 'clinical_workbook');
        $executor = new SourceReviewExecutor($this->results([['id' => 17]], [$row]));
        $service = new DocumentCitationReviewService($executor, new SourceDocumentAccessGate($executor));

        $review = $service->review(new PatientId(900101), new DocumentId(66), new DocumentJobId(7), 41);

        $this->assertNotNull($review);
        $payload = $review->toArray();
        $this->assertSame('table_cell', $payload['locator']['kind']);
        $this->assertSame('sheet:Labs_Trend', $payload['locator']['sheet']);
        $this->assertSame('Care_Gaps!A4:F4', $payload['locator']['cell_ref']);
        $this->assertArrayNotHasKey('bounding_box', $payload['locator']);
        $this->assertArrayNotHasKey('page_image_url', $payload);
        $this->assertNull($payload['page_number']);
        $this->assertSame('Overdue screening', $payload['quote_or_value']);
    }

    public function testHl7v2MessageReturnsMessageFieldLocatorWithoutPageImage(): void
    {
        $row = $this->factRow([
            'source_type' => 'hl7v2_message',
            'page_or_section' => 'message:MSG-ORU-1',
            'field_or_chunk_id' => 'OBX[2].5',
            'quote_or_value' => '142 mg/dL',
            'bounding_box' => null,
        ], 'hl7v2_message');
        $executor = new SourceReviewExecutor($this->results([['id' => 17]], [$row]));
        $service = new DocumentCitationReviewService($executor, new SourceDocumentAccessGate($executor));

        $review = $service->review(new PatientId(900101), new DocumentId(77), new DocumentJobId(7), 41);

        $this->assertNotNull($review);
        $payload = $review->toArray();
        $this->assertSame('message_field', $payload['locator']['kind']);
        $this->assertSame('message:MSG-ORU-1', $payload['locator']['message']);
        $this->assertSame('OBX[2].5', $payload['locator']['field_path']);
        $this->assertArrayNotHasKey('bounding_box', $payload['locator']);
        $this->assertArrayNotHasKey('page_image_url', $payload);
        $this->assertNull($payload['page_number']);
        $this->assertSame('142 mg/dL', $payload['quote_or_value']);
    }

    public function testOutOfBoundsBoundingBoxFallsBackToPageQuoteReview(): void
    {
        $row = $this->factRow([
            'bounding_box' => ['x' => 0.8, 'y' => 0.2, 'width' => 0.3, 'height' => 0.08],
        ]);
        $executor = new SourceReviewExecutor($this->results([['id' => 17]], [$row]));
        $service = new DocumentCitationReviewService($executor, new SourceDocumentAccessGate($executor));

        $review = $service->review(new PatientId(900101), new DocumentId(22), new DocumentJobId(7), 41);

        $this->assertNotNull($review);
        $payload = $review->toArray();
        $this->assertArrayNotHasKey('review_mode', $payload);
        $this->assertSame('page_quote', $payload['locator']['kind']);
        $this->assertArrayNotHasKey('bounding_box', $payload['locator']);
    }

    public function testDeniedAccessFailsClosedBeforeFactLookup(): void
    {
        $executor = new SourceReviewExecutor($this->results([]));
        $service = new DocumentCitationReviewService($executor, new SourceDocumentAccessGate($executor));

        $review = $service->review(new PatientId(900101), new DocumentId(11), new DocumentJobId(7), 41);

        $this->assertNull($review);
        $this->assertCount(1, $executor->queries);
    }

    public function testInvalidFactJsonFailsClosed(): void
    {
        $executor = new SourceReviewExecutor($this->results(
            [['id' => 17]],
            [[
                'id' => 41,
                'citation_json' => '{invalid',
                'structured_value_json' => '{}',
            ]],
        ));
        $service = new DocumentCitationReviewService($executor, new SourceDocumentAccessGate($executor));

        $this->assertNull($service->review(new PatientId(900101), new DocumentId(11), new DocumentJobId(7), 41));
    }

    public function testAccessGateRequiresActiveUnretractedDocumentFactWhenFactSpecific(): void
    {
        $executor = new SourceReviewExecutor($this->results([['id' => 17]]));
        $gate = new SourceDocumentAccessGate($executor);

        $this->assertTrue($gate->allows(new PatientId(900101), new DocumentId(11), new DocumentJobId(7), 41));
        $this->assertStringContainsString('INNER JOIN clinical_document_facts f', $executor->queries[0]['sql']);
        $this->assertStringContainsString('j.patient_id = ?', $executor->queries[0]['sql']);
        $this->assertStringContainsString('j.document_id = ?', $executor->queries[0]['sql']);
        $this->assertStringContainsString('j.status = ?', $executor->queries[0]['sql']);
        $this->assertStringContainsString('j.retracted_at IS NULL', $executor->queries[0]['sql']);
        $this->assertStringContainsString('ic.identity_status IN (?, ?)', $executor->queries[0]['sql']);
        $this->assertStringContainsString('ic.review_required = 0 OR ic.review_decision = ?', $executor->queries[0]['sql']);
        $this->assertStringContainsString('d.deleted IS NULL OR d.deleted = 0', $executor->queries[0]['sql']);
        $this->assertStringContainsString('f.active = 1', $executor->queries[0]['sql']);
        $this->assertStringContainsString('f.retracted_at IS NULL', $executor->queries[0]['sql']);
        $this->assertStringContainsString('f.deactivated_at IS NULL', $executor->queries[0]['sql']);
        $this->assertStringContainsString('d.deleted IS NULL OR d.deleted = 0', $executor->queries[0]['sql']);
        $this->assertSame([7, 900101, 11, 'succeeded', 'identity_verified', 'identity_review_approved', 'approved', 'approved', 41], $executor->queries[0]['binds']);
    }

    /**
     * @param list<array<string, mixed>> ...$sets
     * @return list<list<array<string, mixed>>>
     */
    private function results(array ...$sets): array
    {
        return array_values($sets);
    }

    /**
     * @param array<string, mixed> $citation
     * @return array<string, mixed>
     */
    private function factRow(array $citation = [], string $docType = 'lab_pdf'): array
    {
        return [
            'id' => 41,
            'doc_type' => $docType,
            'citation_json' => json_encode(array_merge([
                'source_type' => $docType,
                'source_id' => 'doc:11',
                'page_or_section' => 'page 1',
                'field_or_chunk_id' => 'results[0]',
                'quote_or_value' => 'LDL Cholesterol 148 mg/dL',
                'bounding_box' => ['x' => 0.1, 'y' => 0.2, 'width' => 0.3, 'height' => 0.08],
            ], $citation), JSON_THROW_ON_ERROR),
            'structured_value_json' => '{}',
        ];
    }
}

final class SourceReviewExecutor implements DatabaseExecutor
{
    /** @var list<array{sql: string, binds: list<mixed>}> */
    public array $queries = [];

    /**
     * @param list<list<array<string, mixed>>> $results
     */
    public function __construct(private array $results)
    {
    }

    public function fetchRecords(string $sql, array $binds = [], ?Deadline $deadline = null): array
    {
        $this->queries[] = ['sql' => $sql, 'binds' => $binds];

        return array_shift($this->results) ?? [];
    }

    public function executeStatement(string $sql, array $binds = []): void
    {
    }

    public function executeAffected(string $sql, array $binds = []): int
    {
        return 0;
    }

    public function insert(string $sql, array $binds = []): int
    {
        return 0;
    }
}
