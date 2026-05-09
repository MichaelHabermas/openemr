<?php

/**
 * Forbid direct instantiation of `SqlQueryUtilsExecutor` outside sanctioned wiring sites.
 *
 * `SqlQueryUtilsExecutor` is the single concrete `DatabaseExecutor` implementation that
 * reaches into legacy global database state. Domain code must depend on the
 * `DatabaseExecutor` interface and receive an instance via constructor injection. Only
 * a small allowlist of composition-root sites (entry-point scripts and the few default
 * factories under `src/AgentForge/`) may construct it directly. Tests are exempt — they
 * either inject a `FakeDatabaseExecutor` or, for a handful of legacy isolation tests,
 * implement `DatabaseExecutor` inline.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\PHPStan\Rules;

use OpenEMR\AgentForge\SqlQueryUtilsExecutor;
use PhpParser\Node;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<New_>
 */
class ForbiddenAgentForgeWiringRule implements Rule
{
    /**
     * Composition-root sites where `new SqlQueryUtilsExecutor()` is permitted. Paths are
     * matched as suffixes against the analyzed file's absolute path so the rule is
     * portable across checkout locations.
     *
     * @var list<string>
     */
    private const ALLOWED_PATH_SUFFIXES = [
        '/interface/patient_file/summary/agent_request.php',
        '/interface/patient_file/summary/agent_document_source.php',
        '/interface/patient_file/summary/agent_document_source_page.php',
        '/interface/patient_file/summary/agent_document_source_review.php',
        '/agent-forge/scripts/run-sql-evidence-evals.php',
        '/agent-forge/scripts/index-clinical-guidelines.php',
        '/src/AgentForge/Document/DocumentHookServiceBinding.php',
        '/src/AgentForge/Document/DocumentUploadEnqueuerFactory.php',
        '/src/AgentForge/Document/Worker/DocumentJobWorkerFactory.php',
    ];

    /**
     * Path fragments under which any file may construct the executor (test code).
     *
     * @var list<string>
     */
    private const ALLOWED_PATH_FRAGMENTS = [
        '/tests/',
    ];

    public function getNodeType(): string
    {
        return New_::class;
    }

    /**
     * @param New_ $node
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!($node->class instanceof Name)) {
            return [];
        }

        $className = ltrim($node->class->toString(), '\\');
        if ($className !== SqlQueryUtilsExecutor::class) {
            return [];
        }

        $file = str_replace('\\', '/', $scope->getFile());
        foreach (self::ALLOWED_PATH_SUFFIXES as $suffix) {
            if (str_ends_with($file, $suffix)) {
                return [];
            }
        }
        foreach (self::ALLOWED_PATH_FRAGMENTS as $fragment) {
            if (str_contains($file, $fragment)) {
                return [];
            }
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Direct instantiation of %s is forbidden outside AgentForge composition-root wiring sites. Depend on OpenEMR\\AgentForge\\DatabaseExecutor and receive an instance via constructor injection.',
                SqlQueryUtilsExecutor::class,
            ))
                ->identifier('openemr.agentForgeWiring')
                ->tip('See src/AgentForge/Document/DocumentUploadEnqueuerFactory.php for an example of an allowlisted wiring site.')
                ->build(),
        ];
    }
}
