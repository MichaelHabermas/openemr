<?php

/**
 * Isolated tests for the SQL MAX_EXECUTION_TIME hint injector.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge;

use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\SqlDeadlineHint;
use OpenEMR\Tests\Isolated\AgentForge\Support\AgentForgeTestFixtures;
use PHPUnit\Framework\TestCase;

final class SqlDeadlineHintTest extends TestCase
{
    public function testReturnsOriginalSqlWhenNoDeadlineProvided(): void
    {
        $sql = 'SELECT id FROM patient_data WHERE pid = ?';

        self::assertSame($sql, SqlDeadlineHint::apply($sql, null));
    }

    public function testReturnsOriginalSqlWhenDeadlineBudgetIsNegative(): void
    {
        $clock = AgentForgeTestFixtures::advanceableMonotonicClock(1000);
        $deadline = new Deadline($clock, -1);
        $sql = 'SELECT id FROM patient_data WHERE pid = ?';

        self::assertSame($sql, SqlDeadlineHint::apply($sql, $deadline));
    }

    public function testReturnsOriginalSqlWhenDeadlineHasExpired(): void
    {
        $clock = AgentForgeTestFixtures::advanceableMonotonicClock(1000);
        $deadline = new Deadline($clock, 100);
        $clock->advance(200);
        $sql = 'SELECT id FROM patient_data WHERE pid = ?';

        self::assertSame($sql, SqlDeadlineHint::apply($sql, $deadline));
    }

    public function testInjectsHintIntoLeadingSelect(): void
    {
        $clock = AgentForgeTestFixtures::advanceableMonotonicClock(0);
        $deadline = new Deadline($clock, 5000);

        $patched = SqlDeadlineHint::apply('SELECT id FROM patient_data WHERE pid = ?', $deadline);

        self::assertSame(
            'SELECT /*+ MAX_EXECUTION_TIME(5000) */ id FROM patient_data WHERE pid = ?',
            $patched,
        );
    }

    public function testInjectsHintIntoFirstSelectInsideParenthesizedUnion(): void
    {
        $clock = AgentForgeTestFixtures::advanceableMonotonicClock(0);
        $deadline = new Deadline($clock, 4000);

        $sql = '(SELECT id FROM prescriptions WHERE patient_id = ?) UNION ALL '
            . '(SELECT id FROM lists WHERE pid = ?) ORDER BY id';

        $patched = SqlDeadlineHint::apply($sql, $deadline);

        self::assertSame(
            '(SELECT /*+ MAX_EXECUTION_TIME(4000) */ id FROM prescriptions WHERE patient_id = ?) '
            . 'UNION ALL (SELECT id FROM lists WHERE pid = ?) ORDER BY id',
            $patched,
        );
    }

    public function testDoesNotDoubleInjectHint(): void
    {
        $clock = AgentForgeTestFixtures::advanceableMonotonicClock(0);
        $deadline = new Deadline($clock, 5000);

        $sql = 'SELECT /*+ MAX_EXECUTION_TIME(1000) */ id FROM patient_data WHERE pid = ?';

        self::assertSame($sql, SqlDeadlineHint::apply($sql, $deadline));
    }

    public function testReturnsOriginalSqlWhenNoSelectFound(): void
    {
        $clock = AgentForgeTestFixtures::advanceableMonotonicClock(0);
        $deadline = new Deadline($clock, 5000);

        $sql = 'UPDATE patient_data SET fname = ? WHERE pid = ?';

        self::assertSame($sql, SqlDeadlineHint::apply($sql, $deadline));
    }

    public function testRespectsLeadingWhitespaceWhenInjecting(): void
    {
        $clock = AgentForgeTestFixtures::advanceableMonotonicClock(0);
        $deadline = new Deadline($clock, 2500);

        $patched = SqlDeadlineHint::apply("\n  SELECT 1", $deadline);

        self::assertSame("\n  SELECT /*+ MAX_EXECUTION_TIME(2500) */ 1", $patched);
    }
}
