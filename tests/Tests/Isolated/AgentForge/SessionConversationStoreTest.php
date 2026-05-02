<?php

/**
 * Isolated tests for the session-backed AgentForge conversation store.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge;

use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\Conversation\ConversationLookup;
use OpenEMR\AgentForge\Conversation\ConversationTurnSummary;
use OpenEMR\AgentForge\Conversation\SessionConversationStore;
use OpenEMR\Common\Session\SessionWrapperFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class SessionConversationStoreTest extends TestCase
{
    public function testStoresAndLoadsBoundConversationStateThroughSessionInterface(): void
    {
        $store = new SessionConversationStore();
        $this->useIsolatedSession();

        $state = $store->start(7, new PatientId(900001), 1000);
        $updated = $store->recordTurn(
            $state,
            new ConversationTurnSummary('lab', ['lab:procedure_result/a1c@2026-04-10']),
            2000,
        );
        $lookup = $store->lookup($state->id, 3000);

        $this->assertSame(1, $updated->turnCount);
        $this->assertSame(ConversationLookup::FOUND, $lookup->status);
        $this->assertNotNull($lookup->state);
        $this->assertSame(7, $lookup->state->userId);
        $this->assertSame(900001, $lookup->state->patientId->value);
        $this->assertNotNull($lookup->state->summary);
        $this->assertSame('lab', $lookup->state->summary->questionType);
        $this->assertSame(['lab:procedure_result/a1c@2026-04-10'], $lookup->state->summary->sourceIds);
    }

    public function testExpiredStateIsRemovedFromSession(): void
    {
        $store = new SessionConversationStore(ttlMs: 10);
        $this->useIsolatedSession();

        $state = $store->start(7, new PatientId(900001), 1000);

        $this->assertSame(ConversationLookup::EXPIRED, $store->lookup($state->id, 1010)->status);
        $this->assertSame(ConversationLookup::MISSING, $store->lookup($state->id, 1011)->status);
    }

    public function testStartingNewConversationPrunesExpiredSessionState(): void
    {
        $store = new SessionConversationStore(ttlMs: 10);
        $this->useIsolatedSession();

        $expired = $store->start(7, new PatientId(900001), 1000);
        $active = $store->start(7, new PatientId(900001), 1010);

        $this->assertSame(ConversationLookup::MISSING, $store->lookup($expired->id, 1011)->status);
        $this->assertSame(ConversationLookup::FOUND, $store->lookup($active->id, 1011)->status);
    }

    public function testTurnLimitIsEnforced(): void
    {
        $store = new SessionConversationStore(maxTurns: 1);
        $this->useIsolatedSession();

        $state = $store->start(7, new PatientId(900001), 1000);
        $store->recordTurn($state, new ConversationTurnSummary('lab'), 1001);

        $this->assertSame(ConversationLookup::TURN_LIMIT_EXCEEDED, $store->lookup($state->id, 1002)->status);
    }

    private function useIsolatedSession(): void
    {
        SessionWrapperFactory::getInstance()->setActiveSession(new Session(new MockArraySessionStorage()));
    }
}
