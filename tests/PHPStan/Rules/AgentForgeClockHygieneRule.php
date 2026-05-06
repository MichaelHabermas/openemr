<?php

/**
 * Forbid wall-clock and monotonic-clock primitives in AgentForge code.
 *
 * Inside the `OpenEMR\AgentForge\` namespace, callers must obtain time
 * via injected `Psr\Clock\ClockInterface` (wall clock) or
 * `OpenEMR\AgentForge\Time\MonotonicClock` (monotonic clock). Direct
 * `new \DateTimeImmutable()` (zero-arg or `'now'` form) and the
 * `microtime()` / `hrtime()` functions are forbidden.
 *
 * Hydration parsers — `new \DateTimeImmutable($string_from_db)` — are
 * explicitly allowed: the constructor receives a non-empty argument that is
 * not the literal `'now'`.
 *
 * The clock implementations under `OpenEMR\AgentForge\Time\` are exempt;
 * they are the single source of truth for wall and monotonic time.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<Node>
 */
class AgentForgeClockHygieneRule implements Rule
{
    private const AGENTFORGE_NAMESPACE_PREFIX = 'OpenEMR\\AgentForge\\';

    private const TIME_NAMESPACE_PREFIX = 'OpenEMR\\AgentForge\\Time\\';

    private const FORBIDDEN_FUNCTIONS = ['microtime', 'hrtime'];

    public function getNodeType(): string
    {
        return Node::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$this->isInAgentForgeButNotTimeNamespace($scope)) {
            return [];
        }

        if ($node instanceof New_) {
            return $this->checkDateTimeImmutable($node);
        }

        if ($node instanceof FuncCall) {
            return $this->checkForbiddenFunction($node);
        }

        return [];
    }

    private function isInAgentForgeButNotTimeNamespace(Scope $scope): bool
    {
        $namespace = $scope->getNamespace();
        if ($namespace === null) {
            return false;
        }

        if (!str_starts_with($namespace . '\\', self::AGENTFORGE_NAMESPACE_PREFIX)) {
            return false;
        }

        if (str_starts_with($namespace . '\\', self::TIME_NAMESPACE_PREFIX)) {
            return false;
        }

        return true;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    private function checkDateTimeImmutable(New_ $node): array
    {
        if (!($node->class instanceof Name)) {
            return [];
        }

        $className = ltrim($node->class->toString(), '\\');
        if ($className !== 'DateTimeImmutable') {
            return [];
        }

        $args = $node->getArgs();
        if ($args === []) {
            return [$this->buildDateTimeError()];
        }

        $first = $args[0]->value;
        if ($first instanceof String_ && strtolower($first->value) === 'now') {
            return [$this->buildDateTimeError()];
        }

        return [];
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    private function checkForbiddenFunction(FuncCall $node): array
    {
        if (!($node->name instanceof Name)) {
            return [];
        }

        $functionName = strtolower(ltrim($node->name->toString(), '\\'));
        if (!in_array($functionName, self::FORBIDDEN_FUNCTIONS, true)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Direct call to %s() is forbidden in AgentForge code. Inject OpenEMR\\AgentForge\\Time\\MonotonicClock and use nowMs() instead.',
                $functionName,
            ))
                ->identifier('openemr.agentForgeClockHygiene')
                ->tip('See src/AgentForge/Time/SystemMonotonicClock.php for the only sanctioned monotonic-clock callsite.')
                ->build(),
        ];
    }

    private function buildDateTimeError(): \PHPStan\Rules\IdentifierRuleError
    {
        return RuleErrorBuilder::message(
            'Direct `new \\DateTimeImmutable()` (zero-arg or \'now\') is forbidden in AgentForge code. Inject Psr\\Clock\\ClockInterface and call now() instead. Hydration parsers `new \\DateTimeImmutable($string)` remain allowed.',
        )
            ->identifier('openemr.agentForgeClockHygiene')
            ->tip('See src/AgentForge/Time/SystemPsrClock.php for the only sanctioned wall-clock callsite.')
            ->build();
    }
}
