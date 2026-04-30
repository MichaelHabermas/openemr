<?php

/**
 * Isolated tests for AgentForge draft provider selection.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge;

use OpenEMR\AgentForge\DraftProviderFactory;
use OpenEMR\AgentForge\FixtureDraftProvider;
use PHPUnit\Framework\TestCase;

final class DraftProviderFactoryTest extends TestCase
{
    public function testDefaultProviderIsFixtureFirst(): void
    {
        $this->assertInstanceOf(FixtureDraftProvider::class, DraftProviderFactory::createDefault());
    }
}
