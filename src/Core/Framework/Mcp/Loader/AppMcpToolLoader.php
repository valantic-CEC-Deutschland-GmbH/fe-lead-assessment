<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Mcp\Loader;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use Mcp\Capability\Registry\Loader\LoaderInterface;
use Mcp\Capability\RegistryInterface;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Tool;
use Mcp\Server\RequestContext;
use Psr\Log\LoggerInterface;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Log\Package;

/**
 * @experimental stableVersion:v6.8.0 feature:MCP_SERVER
 *
 * Loads app-provided MCP tools from the database and registers them
 * with the MCP server registry at build time.
 */
#[Package('framework')]
class AppMcpToolLoader implements LoaderInterface
{
    /**
     * @internal
     *
     * @param list<string> $allowedTools When non-empty, only these tool names are registered. Empty = all allowed.
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly AppMcpToolExecutor $executor,
        private readonly array $allowedTools = [],
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function load(RegistryInterface $registry): void
    {
        try {
            $tools = $this->loadActiveAppTools();
        } catch (DBALException) {
            return;
        }

        foreach ($tools as $toolData) {
            $appName = (string) $toolData['app_name'];
            $name = (string) $toolData['name'];
            $toolName = $appName . '-' . $name;

            if (str_starts_with($toolName, 'shopware-')) {
                $this->logger?->warning('App tool name uses reserved "shopware-" prefix, skipping', ['toolName' => $toolName, 'appName' => $appName]);

                continue;
            }

            if ($this->allowedTools !== [] && !\in_array($toolName, $this->allowedTools, true)) {
                continue;
            }
            $description = (string) ($toolData['label'] ?? $toolData['description'] ?? $toolName);
            $inputSchema = $this->buildInputSchema(isset($toolData['input_schema']) ? (string) $toolData['input_schema'] : null);

            $tool = new Tool(
                name: $toolName,
                inputSchema: $inputSchema,
                description: $description,
                annotations: null,
            );

            $appSecret = (string) $toolData['app_secret'];
            $url = (string) $toolData['url'];
            $appVersion = (string) ($toolData['version'] ?? '0.0.0');

            $registry->registerTool($tool, function (RequestContext $context) use ($toolName, $appSecret, $url, $appVersion): string {
                $request = $context->getRequest();
                $arguments = $request instanceof CallToolRequest ? $request->arguments : [];

                return $this->executor->execute($toolName, $appSecret, $url, $arguments, $appVersion);
            }, true);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadActiveAppTools(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT
                t.name,
                t.url,
                t.input_schema,
                a.name AS app_name,
                a.app_secret,
                a.version,
                tt.label,
                tt.description
            FROM app_mcp_tool t
            INNER JOIN app a ON t.app_id = a.id AND a.active = 1
            LEFT JOIN app_mcp_tool_translation tt
                ON t.id = tt.app_mcp_tool_id
                AND tt.language_id = UNHEX(:languageId)
            WHERE a.app_secret IS NOT NULL
            ORDER BY a.name, t.name',
            ['languageId' => Defaults::LANGUAGE_SYSTEM],
        );
    }

    /**
     * @return array{type: 'object', properties: array<string, mixed>, required: list<string>}
     */
    private function buildInputSchema(?string $inputSchemaJson): array
    {
        if ($inputSchemaJson === null) {
            return ['type' => 'object', 'properties' => [], 'required' => []];
        }

        $schema = json_decode($inputSchemaJson, true);

        if (!\is_array($schema)) {
            return ['type' => 'object', 'properties' => [], 'required' => []];
        }

        /** @var array<string, mixed> $properties */
        $properties = [];
        /** @var list<string> $required */
        $required = [];

        foreach ($schema as $name => $config) {
            $prop = ['type' => $config['type'] ?? 'string'];

            if (isset($config['description'])) {
                $prop['description'] = $config['description'];
            }

            $properties[(string) $name] = $prop;

            if (($config['required'] ?? false) === true) {
                $required[] = (string) $name;
            }
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
        ];
    }
}
