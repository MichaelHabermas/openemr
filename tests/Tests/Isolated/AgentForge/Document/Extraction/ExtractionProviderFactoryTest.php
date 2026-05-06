<?php

/**
 * Isolated tests for AgentForge extraction provider selection.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Document\Extraction;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\Document\DocumentId;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\Extraction\ExtractionProviderConfig;
use OpenEMR\AgentForge\Document\Extraction\ExtractionProviderFactory;
use OpenEMR\AgentForge\Document\Extraction\FixtureExtractionProvider;
use OpenEMR\AgentForge\Document\Extraction\OpenAiVlmExtractionProvider;
use OpenEMR\AgentForge\Document\Extraction\PdfPageRenderer;
use OpenEMR\AgentForge\Document\Extraction\RenderedPdfPage;
use OpenEMR\AgentForge\Document\Schema\LabPdfExtraction;
use OpenEMR\AgentForge\Document\Worker\DocumentLoadResult;
use OpenEMR\AgentForge\SystemAgentForgeClock;
use PHPUnit\Framework\TestCase;

final class ExtractionProviderFactoryTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $providerEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ($this->providerEnvNames() as $name) {
            $this->providerEnv[$name] = getenv($name, true);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->providerEnv as $name => $value) {
            if ($value === false) {
                putenv($name);
                continue;
            }

            putenv($name . '=' . $value);
        }

        $this->providerEnv = [];

        parent::tearDown();
    }

    public function testDefaultProviderIsFixtureFirst(): void
    {
        foreach ($this->providerEnvNames() as $name) {
            putenv($name);
        }

        $this->assertInstanceOf(FixtureExtractionProvider::class, ExtractionProviderFactory::createDefault());
    }

    public function testOpenAiModeReturnsOpenAiProvider(): void
    {
        $provider = ExtractionProviderFactory::create(new ExtractionProviderConfig(
            mode: ExtractionProviderConfig::MODE_OPENAI,
            apiKey: 'test-key',
            model: 'gpt-4o-mini',
        ));

        $this->assertInstanceOf(OpenAiVlmExtractionProvider::class, $provider);
    }

    public function testOpenAiCreateUsesInjectedHttpClient(): void
    {
        $body = [
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'doc_type' => 'lab_pdf',
                            'lab_name' => 'Injected Client Lab',
                            'collected_at' => '2026-04-01',
                            'patient_identity' => [],
                            'results' => [
                                [
                                    'test_name' => 'LDL',
                                    'value' => '91',
                                    'unit' => 'mg/dL',
                                    'reference_range' => '<100',
                                    'collected_at' => '2026-04-01',
                                    'abnormal_flag' => 'normal',
                                    'certainty' => 'verified',
                                    'confidence' => 0.97,
                                    'citation' => [
                                        'source_type' => 'lab_pdf',
                                        'source_id' => 'doc:1',
                                        'page_or_section' => '1',
                                        'field_or_chunk_id' => 'r0',
                                        'quote_or_value' => 'LDL 91',
                                    ],
                                ],
                            ],
                        ], JSON_THROW_ON_ERROR),
                    ],
                ],
            ],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ];

        $mock = new MockHandler([
            new Response(200, [], json_encode($body, JSON_THROW_ON_ERROR)),
        ]);
        $client = new Client([
            'base_uri' => 'https://api.openai.com',
            'handler' => HandlerStack::create($mock),
        ]);

        $provider = ExtractionProviderFactory::create(
            new ExtractionProviderConfig(
                mode: ExtractionProviderConfig::MODE_OPENAI,
                apiKey: 'k',
                model: 'gpt-4o-mini',
            ),
            new FactoryTestPdfRenderer(),
            $client,
        );

        $this->assertInstanceOf(OpenAiVlmExtractionProvider::class, $provider);
        $response = $provider->extract(
            new DocumentId(1),
            new DocumentLoadResult('%PDF', 'application/pdf', 'x.pdf'),
            DocumentType::LabPdf,
            new Deadline(new SystemAgentForgeClock(), 8000),
        );
        $this->assertTrue($response->schemaValid);
        $this->assertInstanceOf(LabPdfExtraction::class, $response->extraction);
        $this->assertSame('Injected Client Lab', $response->extraction->labName);
    }

    public function testOpenAiDefaultsUseKnownGpt4oPricing(): void
    {
        $config = new ExtractionProviderConfig(
            mode: ExtractionProviderConfig::MODE_OPENAI,
            apiKey: 'test-key',
        );

        $this->assertSame('gpt-4o', $config->model);
        $this->assertSame(2.50, $config->inputCostPerMillionTokens);
        $this->assertSame(10.00, $config->outputCostPerMillionTokens);
        $this->assertSame(60.0, $config->timeoutSeconds);
        $this->assertSame(10.0, $config->connectTimeoutSeconds);
        $this->assertSame(5, $config->maxPdfPages);
    }

    /** @return list<string> */
    private function providerEnvNames(): array
    {
        return [
            'AGENTFORGE_VLM_PROVIDER',
            'AGENTFORGE_OPENAI_API_KEY',
            'OPENAI_API_KEY',
            'AGENTFORGE_VLM_MODEL',
            'AGENTFORGE_EXTRACTION_FIXTURE_MANIFEST',
            'AGENTFORGE_EXTRACTION_FIXTURES_DIR',
        ];
    }
}

final class FactoryTestPdfRenderer implements PdfPageRenderer
{
    /**
     * @return list<RenderedPdfPage>
     */
    public function render(string $pdfBytes, int $maxPages): array
    {
        return [new RenderedPdfPage(1, 'image/png', 'page-bytes')];
    }
}
