<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Mcp\Tool;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\Log\Package;

/**
 * @experimental stableVersion:v6.8.0 feature:MCP_SERVER
 *
 * Provides a unified response envelope for MCP tools.
 *
 * All tools must extend this class and use success() and error() to build
 * return values so AI clients receive a predictable JSON structure.
 *
 * Error handling for extension developers:
 * - Unhandled exceptions propagate to the MCP SDK's generic handler and produce
 *   a generic "Error while executing tool" message to the AI client.
 * - To expose error details, either throw a ToolCallException or return $this->error().
 * - Use $this->error() for expected/business errors (missing privilege, not found, etc.).
 * - Let unexpected exceptions propagate so they appear in logs without leaking internals.
 */
#[Package('framework')]
abstract class McpToolResponse
{
    private const MAX_RESPONSE_SIZE = 100_000;

    /**
     * @param array<string, mixed>|list<mixed> $data
     * @param array<string, mixed> $meta
     */
    protected function success(array $data, array $meta = []): string
    {
        $response = ['success' => true, 'data' => $data];

        if ($meta !== []) {
            $response['_meta'] = $meta;
        }

        $json = json_encode($response, \JSON_THROW_ON_ERROR);

        if (\strlen($json) > self::MAX_RESPONSE_SIZE) {
            $meta['truncated'] = true;
            $meta['truncatedMessage'] = 'Response exceeded size limit. Use "includes" in criteria to select specific fields, or reduce "limit".';

            if (array_is_list($data)) {
                $data = \array_slice($data, 0, 5);
            }

            $response = ['success' => true, 'data' => $data, '_meta' => $meta];
            $json = json_encode($response, \JSON_THROW_ON_ERROR);

            if (\strlen($json) > self::MAX_RESPONSE_SIZE) {
                $response['data'] = [];
                $response['_meta']['truncatedMessage'] = 'Response still too large after truncation. Data cleared. Use "includes" to select specific fields.';
                $json = json_encode($response, \JSON_THROW_ON_ERROR);
            }
        }

        return $json;
    }

    protected function error(string $message): string
    {
        return json_encode(['success' => false, 'error' => $message], \JSON_THROW_ON_ERROR);
    }

    /**
     * @return string|null Error JSON string if a privilege is missing, null if all granted
     */
    protected function requirePrivilege(Context $context, string ...$privileges): ?string
    {
        foreach ($privileges as $privilege) {
            if (!$context->isAllowed($privilege)) {
                return $this->error(\sprintf('Missing privilege: %s', $privilege));
            }
        }

        return null;
    }

    /**
     * Executes an operation within a transaction that is always rolled back (dry-run preview).
     *
     * The context receives SKIP_TRIGGER_FLOW to prevent flows from firing during the
     * dry-run. Note that with Redis-based delayed cache invalidation, DAL writes may
     * still enqueue invalidations that are not reverted by the DB rollback.
     *
     * @param callable(): string $operation Must return the JSON result string
     */
    protected function executeWithDryRun(Connection $connection, Context $context, callable $operation): string
    {
        $context->addState(Context::SKIP_TRIGGER_FLOW);

        $connection->beginTransaction();

        try {
            $result = $operation();
        } catch (\Throwable $e) {
            $result = $this->error($e->getMessage());
        }

        $context->removeState(Context::SKIP_TRIGGER_FLOW);

        try {
            $connection->rollBack();
        } catch (\Throwable $rollbackException) {
            return $this->error(\sprintf('Dry-run rollback failed: data may have been persisted. %s', $rollbackException->getMessage()));
        }

        return $result;
    }

    /**
     * @return list<array{entity: string, ids: list<string>, operation: string}>
     */
    protected function formatWriteEvents(EntityWrittenContainerEvent $events, string $operation): array
    {
        $result = [];
        foreach ($events->getEvents()?->getElements() ?? [] as $event) {
            $result[] = [
                'entity' => $event->getEntityName(),
                'ids' => $event->getIds(),
                'operation' => $operation,
            ];
        }

        return $result;
    }
}
