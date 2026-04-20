<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\Mcp\Tool;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Mcp\Tool\McpToolResponse;

/**
 * @internal
 */
#[Package('framework')]
#[CoversClass(McpToolResponse::class)]
class McpToolResponseConventionTest extends TestCase
{
    public function testSuccessWithoutMetaOmitsMetaKey(): void
    {
        $helper = new McpToolResponseTestHelper();
        $result = json_decode($helper->callSuccess(['key' => 'value']), true, 512, \JSON_THROW_ON_ERROR);

        static::assertTrue($result['success']);
        static::assertSame(['key' => 'value'], $result['data']);
        static::assertArrayNotHasKey('_meta', $result);
    }

    public function testSuccessWithMetaIncludesMetaKey(): void
    {
        $helper = new McpToolResponseTestHelper();
        $result = json_decode($helper->callSuccess(['x' => 1], ['total' => 5]), true, 512, \JSON_THROW_ON_ERROR);

        static::assertSame(5, $result['_meta']['total']);
    }

    public function testErrorReturnsCorrectStructure(): void
    {
        $helper = new McpToolResponseTestHelper();
        $result = json_decode($helper->callError('Something broke'), true, 512, \JSON_THROW_ON_ERROR);

        static::assertFalse($result['success']);
        static::assertSame('Something broke', $result['error']);
        static::assertArrayNotHasKey('data', $result);
    }

    public function testOversizedListResponseIsTruncatedToFiveItems(): void
    {
        $helper = new McpToolResponseTestHelper();

        $largeList = [];
        for ($i = 0; $i < 500; ++$i) {
            $largeList[] = ['data' => str_repeat('x', 500)];
        }

        $result = json_decode($helper->callSuccess($largeList), true, 512, \JSON_THROW_ON_ERROR);

        static::assertTrue($result['success']);
        static::assertCount(5, $result['data']);
        static::assertTrue($result['_meta']['truncated']);
        static::assertArrayHasKey('truncatedMessage', $result['_meta']);
    }

    public function testDryRunReturnsErrorOnRollbackFailure(): void
    {
        $connection = static::createStub(Connection::class);
        $connection->method('rollBack')->willThrowException(new \RuntimeException('rollback failed'));

        $context = new Context(new AdminApiSource(null, null), [], Defaults::CURRENCY, [Defaults::LANGUAGE_SYSTEM]);

        $helper = new McpToolResponseTestHelper();
        $result = json_decode($helper->callDryRun($connection, $context, fn () => '{"success":true}'), true, 512, \JSON_THROW_ON_ERROR);

        static::assertFalse($result['success']);
        static::assertStringContainsString('Dry-run rollback failed', $result['error']);
        static::assertStringContainsString('rollback failed', $result['error']);
    }

    public function testOversizedAssocResponseStillTooLargeClearsData(): void
    {
        $helper = new McpToolResponseTestHelper();

        $largeAssoc = ['content' => str_repeat('x', 200_000)];

        $result = json_decode($helper->callSuccess($largeAssoc), true, 512, \JSON_THROW_ON_ERROR);

        static::assertTrue($result['success']);
        static::assertSame([], $result['data']);
        static::assertTrue($result['_meta']['truncated']);
        static::assertStringContainsString('still too large', $result['_meta']['truncatedMessage']);
    }
}

/**
 * @internal
 */
class McpToolResponseTestHelper extends McpToolResponse
{
    /**
     * @param array<string, mixed>|list<mixed> $data
     * @param array<string, mixed> $meta
     */
    public function callSuccess(array $data, array $meta = []): string
    {
        return $this->success($data, $meta);
    }

    public function callError(string $message): string
    {
        return $this->error($message);
    }

    /**
     * @param callable(): string $operation
     */
    public function callDryRun(Connection $connection, Context $context, callable $operation): string
    {
        return $this->executeWithDryRun($connection, $context, $operation);
    }
}
