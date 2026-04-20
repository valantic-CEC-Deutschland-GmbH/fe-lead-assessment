<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Mcp\Loader;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use Mcp\Capability\Registry\Loader\LoaderInterface;
use Mcp\Capability\RegistryInterface;
use Mcp\Schema\Resource;
use Mcp\Server\RequestContext;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Log\Package;

/**
 * @experimental stableVersion:v6.8.0 feature:MCP_SERVER
 *
 * Loads app-provided MCP resources from the database and registers them
 * with the MCP server registry at build time.
 */
#[Package('framework')]
class AppMcpResourceLoader implements LoaderInterface
{
    /**
     * @internal
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly AppMcpToolExecutor $executor,
    ) {
    }

    public function load(RegistryInterface $registry): void
    {
        try {
            $resources = $this->loadActiveAppResources();
        } catch (DBALException) {
            return;
        }

        foreach ($resources as $resourceData) {
            $appName = (string) $resourceData['app_name'];
            $name = (string) $resourceData['name'];
            $resourceName = $appName . '-' . $name;

            $description = (string) ($resourceData['label'] ?? $resourceData['description'] ?? $resourceName);
            $mimeType = isset($resourceData['mime_type']) ? (string) $resourceData['mime_type'] : null;

            $resource = new Resource(
                uri: (string) $resourceData['uri'],
                name: $resourceName,
                description: $description,
                mimeType: $mimeType,
            );

            $appSecret = (string) $resourceData['app_secret'];
            $url = (string) $resourceData['url'];
            $uri = (string) $resourceData['uri'];

            $registry->registerResource($resource, function (RequestContext $context) use ($resourceName, $appSecret, $url, $uri): string {
                return $this->executor->execute($resourceName, $appSecret, $url, ['uri' => $uri]);
            }, true);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadActiveAppResources(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT
                r.name,
                r.uri,
                r.url,
                r.mime_type,
                a.name AS app_name,
                a.app_secret,
                rt.label,
                rt.description
            FROM app_mcp_resource r
            INNER JOIN app a ON r.app_id = a.id AND a.active = 1
            LEFT JOIN app_mcp_resource_translation rt
                ON r.id = rt.app_mcp_resource_id
                AND rt.language_id = UNHEX(:languageId)
            WHERE a.app_secret IS NOT NULL
            ORDER BY a.name, r.name',
            ['languageId' => Defaults::LANGUAGE_SYSTEM],
        );
    }
}
