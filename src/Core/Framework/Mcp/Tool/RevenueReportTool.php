<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Bucket\DateHistogramAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\AvgAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\CountAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\SumAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Bucket\DateHistogramResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\AvgResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\CountResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\SumResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Mcp\Context\McpContextProvider;

/**
 * @experimental stableVersion:v6.8.0 feature:MCP_SERVER
 */
#[McpTool(name: 'shopware-revenue-report', description: 'Generate a revenue report for a date range. Provide ISO 8601 dates (e.g. "2025-01-01"). Excludes cancelled orders. Optional groupBy: day (default), week, month. Use page/limit to paginate the timeline buckets. Returns {success, data: {period, totalRevenue, orderCount, averageOrderValue, timeline: [{date, revenue}]}, _meta: {page, limit, total}}.')]
#[Package('framework')]
class RevenueReportTool extends McpToolResponse
{
    /**
     * @internal
     */
    public function __construct(
        private readonly DefinitionInstanceRegistry $registry,
        private readonly McpContextProvider $contextProvider,
    ) {
    }

    public function __invoke(string $from, string $to, string $groupBy = 'day', string $salesChannelId = '', int $page = 1, int $limit = 31): string
    {
        $context = $this->contextProvider->getContext();

        if ($error = $this->requirePrivilege($context, 'order:read')) {
            return $error;
        }

        $interval = RevenueGroupBy::tryFrom($groupBy);
        if ($interval === null) {
            return $this->error(\sprintf('Invalid groupBy value "%s". Allowed: %s', $groupBy, implode(', ', array_column(RevenueGroupBy::cases(), 'value'))));
        }

        if ($page < 1) {
            return $this->error('page must be >= 1.');
        }

        if ($limit < 1 || $limit > 366) {
            return $this->error('limit must be between 1 and 366.');
        }

        $criteria = new Criteria();
        $criteria->setLimit(1);
        $criteria->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_EXACT);

        $criteria->addFilter(new RangeFilter('orderDateTime', [
            RangeFilter::GTE => $from,
            RangeFilter::LTE => $to,
        ]));

        $criteria->addFilter(new NotFilter(MultiFilter::CONNECTION_OR, [
            new EqualsFilter('stateMachineState.technicalName', 'cancelled'),
        ]));

        if ($salesChannelId !== '') {
            $criteria->addFilter(new EqualsFilter('salesChannelId', $salesChannelId));
        }

        $criteria->addAggregation(new SumAggregation('revenue', 'amountTotal'));
        $criteria->addAggregation(new CountAggregation('orderCount', 'id'));
        $criteria->addAggregation(new AvgAggregation('avgOrderValue', 'amountTotal'));
        $criteria->addAggregation(
            new DateHistogramAggregation(
                'revenueOverTime',
                'orderDateTime',
                $interval->value,
                null,
                new SumAggregation('dayRevenue', 'amountTotal'),
            )
        );

        $repository = $this->registry->getRepository('order');
        $result = $repository->search($criteria, $context);

        $aggregations = $result->getAggregations();

        $revenue = $aggregations->get('revenue');
        $orderCount = $aggregations->get('orderCount');
        $avgOrderValue = $aggregations->get('avgOrderValue');
        $timeline = $aggregations->get('revenueOverTime');

        $timelineData = [];

        if ($timeline instanceof DateHistogramResult) {
            foreach ($timeline->getBuckets() as $bucket) {
                $bucketResult = $bucket->getResult();

                $timelineData[] = [
                    'date' => $bucket->getKey(),
                    'revenue' => $bucketResult instanceof SumResult ? $bucketResult->getSum() : 0,
                ];
            }
        }

        $total = \count($timelineData);
        $offset = ($page - 1) * $limit;
        $timelineData = \array_slice($timelineData, $offset, $limit);

        return $this->success([
            'period' => ['from' => $from, 'to' => $to],
            'totalRevenue' => $revenue instanceof SumResult ? $revenue->getSum() : 0,
            'orderCount' => $orderCount instanceof CountResult ? $orderCount->getCount() : 0,
            'averageOrderValue' => $avgOrderValue instanceof AvgResult ? $avgOrderValue->getAvg() : 0,
            'timeline' => $timelineData,
        ], [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
        ]);
    }
}
