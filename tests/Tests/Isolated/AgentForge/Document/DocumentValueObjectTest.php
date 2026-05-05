<?php

/**
 * Isolated tests for AgentForge document value objects.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Document;

use DomainException;
use OpenEMR\AgentForge\Document\CategoryId;
use OpenEMR\AgentForge\Document\DocumentId;
use OpenEMR\AgentForge\Document\DocumentJobId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DocumentValueObjectTest extends TestCase
{
    public function testPositiveIdsAreAccepted(): void
    {
        $this->assertSame(10, (new DocumentId(10))->value);
        $this->assertSame(20, (new CategoryId(20))->value);
        $this->assertSame(30, (new DocumentJobId(30))->value);
    }

    /**
     * @return list<array{class-string, int}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function invalidIdProvider(): array
    {
        return [
            [DocumentId::class, 0],
            [DocumentId::class, -1],
            [CategoryId::class, 0],
            [CategoryId::class, -1],
            [DocumentJobId::class, 0],
            [DocumentJobId::class, -1],
        ];
    }

    #[DataProvider('invalidIdProvider')]
    public function testNonPositiveIdsAreRejected(string $className, int $value): void
    {
        $this->expectException(DomainException::class);

        new $className($value);
    }
}
