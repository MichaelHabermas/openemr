<?php

/**
 * AgentForge dashboard card.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Patient\Cards;

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Events\Patient\Summary\Card\CardModel;

final class AgentForgeViewCard extends CardModel
{
    private const TEMPLATE_FILE = 'patient/card/agent_forge.html.twig';
    private const CARD_ID = 'agent_forge';
    private const CARD_ID_EXPAND = 'agent_forge_ps_expand';

    public function __construct(private readonly int $pid, array $opts = [])
    {
        parent::__construct(array_merge($opts, [
            'acl' => ['patients', 'med'],
            'initiallyCollapsed' => getUserSetting(self::CARD_ID_EXPAND) == 0,
            'add' => false,
            'edit' => false,
            'collapse' => true,
            'templateFile' => self::TEMPLATE_FILE,
            'identifier' => self::CARD_ID,
            'title' => xl('Clinical Co-Pilot'),
            'templateVariables' => [],
        ]));
    }

    public function getTemplateVariables(): array
    {
        $session = SessionWrapperFactory::getInstance()->getActiveSession();

        return array_merge(parent::getTemplateVariables(), [
            'title' => xl('Clinical Co-Pilot'),
            'id' => self::CARD_ID_EXPAND,
            'initiallyCollapsed' => getUserSetting(self::CARD_ID_EXPAND) == 0,
            'auth' => AclMain::aclCheckCore('patients', 'med'),
            'patientId' => $this->pid,
            'csrfToken' => CsrfUtils::collectCsrfToken(session: $session),
            'endpoint' => 'agent_request.php',
        ]);
    }
}
