<?php

/**
 * Adapter around OpenEMR's legacy Document storage class.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Worker;

use Closure;
use ErrorException;
use OpenEMR\AgentForge\Document\DocumentId;
use RuntimeException;
use Throwable;

final readonly class OpenEmrDocumentLoader implements DocumentLoader
{
    /** @var Closure(int): object */
    private Closure $documentFactory;

    /** @param null|Closure(int): object $documentFactory */
    public function __construct(?Closure $documentFactory = null)
    {
        $this->documentFactory = $documentFactory ?? static function (int $id): object {
            if (!class_exists(\Document::class)) {
                require_once dirname(__DIR__, 4) . '/library/classes/Document.class.php';
            }

            return new \Document($id);
        };
    }

    public function load(DocumentId $documentId): DocumentLoadResult
    {
        set_error_handler(
            static function (int $severity, string $message, string $file, int $line): never {
                throw new ErrorException($message, 0, $severity, $file, $line);
            },
        );

        try {
            $document = ($this->documentFactory)($documentId->value);

            if ($this->callOptional($document, 'get_id') === null) {
                throw DocumentLoadException::missing();
            }

            if ((bool) $this->callOptional($document, 'is_deleted')) {
                throw DocumentLoadException::sourceDeleted();
            }

            if ((bool) $this->callOptional($document, 'has_expired')) {
                throw DocumentLoadException::expired();
            }

            $bytes = $this->callRequired($document, 'get_data');
            if (!is_string($bytes) || $bytes === '') {
                throw DocumentLoadException::missing();
            }

            return new DocumentLoadResult(
                bytes: $bytes,
                mimeType: $this->stringRequired($document, 'get_mimetype'),
                name: $this->stringRequired($document, 'get_name'),
            );
        } catch (DocumentLoadException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw DocumentLoadException::unreadable($e);
        } finally {
            restore_error_handler();
        }
    }

    private function callOptional(object $document, string $method): mixed
    {
        if (!method_exists($document, $method)) {
            return null;
        }

        return $document->{$method}();
    }

    private function callRequired(object $document, string $method): mixed
    {
        if (!method_exists($document, $method)) {
            throw new RuntimeException("Document method {$method} is unavailable.");
        }

        return $document->{$method}();
    }

    private function stringRequired(object $document, string $method): string
    {
        $value = $this->callRequired($document, $method);
        if (!is_scalar($value) || (string) $value === '') {
            throw new RuntimeException("Document method {$method} returned an invalid value.");
        }

        return (string) $value;
    }
}
