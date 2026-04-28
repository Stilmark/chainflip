<?php

declare(strict_types=1);

require_once __DIR__ . '/Helpers.php';

final class TableDataBuilder
{
    public function generateAll(array $digest, array $dailyMetrics, array $config, string $outputDir, array $compactStore = []): array
    {
        $outputDir = rtrim($outputDir, '/') . '/tables';
        ensure_dir($outputDir);

        $files = [];

        // Status tables
        $files['status-current.json'] = $this->buildStatusCurrent($digest, $config);
        $files['status-latest-day.json'] = $this->buildStatusLatestDay($dailyMetrics, $config);
        $files['status-by-day.json'] = $this->buildStatusByDay($dailyMetrics, $config);

        // Prediction tables
        $files['prediction-current.json'] = $this->buildPredictionCurrent($digest, $config);
        $files['prediction-latest-day.json'] = $this->buildPredictionLatestDay($dailyMetrics, $config);
        $files['prediction-by-day.json'] = $this->buildPredictionByDay($dailyMetrics, $config);
        $files['prediction-rung-by-day.json'] = $this->buildPredictionRungByDay($dailyMetrics, $config);
        $files['status-daily-breakdown.json'] = $this->buildStatusDailyBreakdown($dailyMetrics, $config);

        // Performance tables
        $files['performance-rungs.json'] = $this->buildRungPerformance($digest, $dailyMetrics, $config);
        $files['performance-scalp.json'] = $this->buildScalpPerformance($digest, $config);
        $files['performance-strategy.json'] = $this->buildStrategySummary($digest, $config);

        // Distribution tables
        $files['distribution-bucket.json'] = $this->buildBucketDistribution($dailyMetrics, $config);

        // Trades tables
        $files['trades-latest.json'] = $this->buildLatestTrades($digest, $config);
        $files['trades-by-day.json'] = $this->buildTradesByDay($compactStore, $config);

        // Config tables
        $files['config-rungs.json'] = $this->buildRungs($config);
        $files['config-balances.json'] = $this->buildConfigBalances($digest, $config);
        $files['config-rebalance.json'] = $this->buildConfigRebalance($config);

        foreach ($files as $filename => $data) {
            file_put_contents(
                $outputDir . '/' . $filename,
                json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            );
        }

        return array_keys($files);
    }

    private function buildStatusCurrent(array $digest, array $config): array
    {
        $meta = $digest['meta'] ?? [];
        $portfolio = $digest['portfolio'] ?? [];
        $rungs = $digest['active_rungs'] ?? [];

        $summary = [
            ['label' => 'Portfolio Value', 'value' => $this->money($portfolio['portfolio_value'] ?? 0)],
            ['label' => 'Total Fees', 'value' => $this->money($portfolio['total_fees_to_date'] ?? 0)],
            ['label' => 'Total TRs', 'value' => (string) ($meta['total_trs_in_scope'] ?? 0)],
            ['label' => 'Window Start', 'value' => substr($meta['analysis_window_start'] ?? '', 0, 10)],
            ['label' => 'As Of Date', 'value' => $meta['as_of_date'] ?? ''],
        ];

        $columns = [
            ['data' => 'rung', 'title' => 'Rung'],
            ['data' => 'name', 'title' => 'Name'],
            ['data' => 'value', 'title' => 'Value', 'className' => 'dt-right'],
            ['data' => 'fees', 'title' => 'Fees', 'className' => 'dt-right'],
            ['data' => 'fee_share', 'title' => 'Fee Share', 'className' => 'dt-right'],
            ['data' => 'eligible_trs', 'title' => 'Eligible TRs', 'className' => 'dt-right'],
            ['data' => 'trades', 'title' => 'Trades', 'className' => 'dt-right'],
            ['data' => 'skipped', 'title' => 'Skipped', 'className' => 'dt-right'],
            ['data' => 'out_of_range', 'title' => 'Out of Range', 'className' => 'dt-right'],
            ['data' => 'depleted', 'title' => 'Depleted', 'className' => 'dt-right'],
            ['data' => 'utilization', 'title' => 'Utilization', 'className' => 'dt-right'],
        ];

        $data = [];
        $totals = [
            'value' => 0, 'fees' => 0, 'eligible_trs' => 0, 'trades' => 0,
            'skipped' => 0, 'out_of_range' => 0, 'depleted' => 0
        ];

        foreach ($rungs as $r) {
            $data[] = [
                'rung' => $r['rung'],
                'name' => $r['name'],
                'value' => $this->money($r['rung_value']),
                'value_raw' => (float) $r['rung_value'],
                'fees' => $this->money($r['fees_to_date']),
                'fees_raw' => (float) $r['fees_to_date'],
                'fee_share' => $this->pct($r['fee_share_pct']),
                'eligible_trs' => (int) $r['eligible_trs'],
                'trades' => (int) $r['trades'],
                'skipped' => (int) $r['skipped_count'],
                'out_of_range' => (int) $r['out_of_range_count'],
                'depleted' => (int) $r['depleted_count'],
                'utilization' => $this->pct($r['utilization_pct']),
            ];

            $totals['value'] += $r['rung_value'];
            $totals['fees'] += $r['fees_to_date'];
            $totals['eligible_trs'] += $r['eligible_trs'];
            $totals['trades'] += $r['trades'];
            $totals['skipped'] += $r['skipped_count'];
            $totals['out_of_range'] += $r['out_of_range_count'];
            $totals['depleted'] += $r['depleted_count'];
        }

        $totalUtil = $totals['eligible_trs'] > 0 ? ($totals['trades'] / $totals['eligible_trs']) * 100 : 0;

        $footer = [
            'rung' => 'Total',
            'name' => '',
            'value' => $this->money($totals['value']),
            'fees' => $this->money($totals['fees']),
            'fee_share' => '100.00%',
            'eligible_trs' => '',
            'trades' => '',
            'skipped' => '',
            'out_of_range' => '',
            'depleted' => '',
            'utilization' => '',
        ];

        return [
            'title' => 'LP Status — Current Window',
            'summary' => $summary,
            'columns' => $columns,
            'data' => $data,
            'footer' => $footer,
        ];
    }

    private function buildStatusLatestDay(array $dailyMetrics, array $config): array
    {
        $days = $dailyMetrics['days'] ?? [];
        if (empty($days)) {
            return ['title' => 'LP Status — Latest Day', 'summary' => [], 'columns' => [], 'data' => [], 'error' => 'No data available'];
        }

        $day = end($days);
        return $this->buildDayStatusData($day, 'LP Status — Latest Day');
    }

    private function buildStatusByDay(array $dailyMetrics, array $config): array
    {
        $days = $dailyMetrics['days'] ?? [];
        if (empty($days)) {
            return ['title' => 'LP Status — By Day', 'days' => [], 'error' => 'No data available'];
        }

        $configRungs = $config['rungs'] ?? [];

        $daysData = [];
        foreach ($days as $day) {
            $daysData[] = $this->buildDayStatusData($day, "Date: {$day['date']}", $configRungs);
        }

        return [
            'title' => 'LP Status — By Day',
            'days' => $daysData,
        ];
    }

    private function buildDayStatusData(array $day, string $title, array $configRungs = []): array
    {
        $meta = $day['meta'] ?? [];
        $portfolio = $day['portfolio'] ?? [];
        $rungs = $day['rung_metrics'] ?? [];
        $dayDate = $day['date'] ?? '';
        $dayEnd = $dayDate . 'T23:59:59Z';

        // Calculate historical portfolio value from config revisions
        $historicalPortfolioValue = $this->getPortfolioValueAtDate($configRungs, $dayEnd);
        if ($historicalPortfolioValue == 0) {
            $historicalPortfolioValue = (float) ($portfolio['portfolio_value'] ?? 0);
        }

        $summary = [
            ['label' => 'Date', 'value' => $day['date'] ?? ''],
            ['label' => 'Portfolio Value', 'value' => $this->money($historicalPortfolioValue)],
            ['label' => 'Total Fees', 'value' => $this->money($portfolio['total_fees'] ?? 0)],
            ['label' => 'TRs', 'value' => (string) ($meta['trs'] ?? 0)],
        ];

        $columns = [
            ['data' => 'rung', 'title' => 'Rung'],
            ['data' => 'name', 'title' => 'Name'],
            ['data' => 'value', 'title' => 'Value', 'className' => 'dt-right'],
            ['data' => 'fees', 'title' => 'Fees', 'className' => 'dt-right'],
            ['data' => 'fee_share', 'title' => 'Fee Share', 'className' => 'dt-right'],
            ['data' => 'eligible_trs', 'title' => 'Eligible TRs', 'className' => 'dt-right'],
            ['data' => 'trades', 'title' => 'Trades', 'className' => 'dt-right'],
            ['data' => 'skipped', 'title' => 'Skipped', 'className' => 'dt-right'],
            ['data' => 'out_of_range', 'title' => 'Out of Range', 'className' => 'dt-right'],
            ['data' => 'depleted', 'title' => 'Depleted', 'className' => 'dt-right'],
            ['data' => 'utilization', 'title' => 'Utilization', 'className' => 'dt-right'],
        ];

        // Build lookup for historical rung values from config
        $rungValueLookup = [];
        foreach ($configRungs as $cr) {
            $rungId = $cr['rung'] ?? '';
            foreach ($cr['revisions'] ?? [] as $rev) {
                $effectiveFrom = $rev['effective_from'] ?? null;
                $effectiveTo = $rev['effective_to'] ?? null;
                if ($effectiveFrom && $dayEnd >= $effectiveFrom) {
                    if ($effectiveTo === null || $dayEnd < $effectiveTo) {
                        $rungValueLookup[$rungId] = (float) ($rev['initial_value']['total_usd'] ?? 0);
                    }
                }
            }
        }

        $data = [];
        $totals = [
            'value' => 0, 'fees' => 0, 'eligible_trs' => 0, 'trades' => 0,
            'skipped' => 0, 'out_of_range' => 0, 'depleted' => 0
        ];

        foreach ($rungs as $r) {
            $rungId = $r['rung'];
            // Use historical value from config if available, otherwise fall back to rung_value
            $rungValue = $rungValueLookup[$rungId] ?? (float) $r['rung_value'];
            
            $data[] = [
                'rung' => $rungId,
                'name' => $r['name'],
                'value' => $this->money($rungValue),
                'value_raw' => $rungValue,
                'fees' => $this->money($r['fees']),
                'fees_raw' => (float) $r['fees'],
                'fee_share' => $this->pct($r['fee_share_pct']),
                'eligible_trs' => (int) $r['eligible_trs'],
                'trades' => (int) $r['trades'],
                'skipped' => (int) $r['skipped_count'],
                'out_of_range' => (int) $r['out_of_range_count'],
                'depleted' => (int) $r['depleted_count'],
                'utilization' => $this->pct($r['utilization_pct']),
            ];

            $totals['value'] += $rungValue;
            $totals['fees'] += $r['fees'];
            $totals['eligible_trs'] += $r['eligible_trs'];
            $totals['trades'] += $r['trades'];
            $totals['skipped'] += $r['skipped_count'];
            $totals['out_of_range'] += $r['out_of_range_count'];
            $totals['depleted'] += $r['depleted_count'];
        }

        $totalUtil = $totals['eligible_trs'] > 0 ? ($totals['trades'] / $totals['eligible_trs']) * 100 : 0;

        $footer = [
            'rung' => 'Total',
            'name' => '',
            'value' => $this->money($totals['value']),
            'fees' => $this->money($totals['fees']),
            'fee_share' => '100.00%',
            'eligible_trs' => $totals['eligible_trs'],
            'trades' => $totals['trades'],
            'skipped' => $totals['skipped'],
            'out_of_range' => $totals['out_of_range'],
            'depleted' => $totals['depleted'],
            'utilization' => $this->pct($totalUtil),
        ];

        return [
            'title' => $title,
            'summary' => $summary,
            'columns' => $columns,
            'data' => $data,
            'footer' => $footer,
            'hide_summary_title' => true,
        ];
    }

    private function buildPredictionCurrent(array $digest, array $config): array
    {
        $meta = $digest['meta'] ?? [];
        $portfolio = $digest['portfolio'] ?? [];
        $rungs = $digest['active_rungs'] ?? [];
        $analysisStart = $config['processing']['analysis_window_start'] ?? '2026-04-15T00:00:00Z';
        $asOfDate = $meta['as_of_date'] ?? date('Y-m-d');

        $startDate = new DateTime(substr($analysisStart, 0, 10));
        $endDate = new DateTime($asOfDate);
        $windowDays = max(1, (int) $endDate->diff($startDate)->days + 1);

        $totalFees = (float) ($portfolio['total_fees_to_date'] ?? 0);
        $portfolioValue = (float) ($portfolio['portfolio_value'] ?? 0);
        $avgDaily = $totalFees / $windowDays;
        $weekly = $avgDaily * 7;
        $monthly = $avgDaily * 30;
        $yearly = $avgDaily * 365;
        $apr = $portfolioValue > 0 ? ($yearly / $portfolioValue) * 100 : 0;

        $summary = [
            ['label' => 'Window Days', 'value' => (string) $windowDays],
            ['label' => 'Total Fees', 'value' => $this->money($totalFees)],
            ['label' => 'Avg Daily Income', 'value' => $this->money($avgDaily)],
            ['label' => 'Weekly Income', 'value' => $this->money($weekly)],
            ['label' => 'Monthly Income', 'value' => $this->money($monthly)],
            ['label' => 'Yearly Income', 'value' => $this->money($yearly)],
            ['label' => 'APR', 'value' => $this->pct($apr)],
        ];

        $columns = [
            ['data' => 'rung', 'title' => 'Rung'],
            ['data' => 'name', 'title' => 'Name'],
            ['data' => 'value', 'title' => 'Value', 'className' => 'dt-right'],
            ['data' => 'days_active', 'title' => 'Days Active', 'className' => 'dt-right'],
            ['data' => 'fees_to_date', 'title' => 'Fees to Date', 'className' => 'dt-right'],
            ['data' => 'daily_income', 'title' => 'Daily Income', 'className' => 'dt-right'],
            ['data' => 'weekly_income', 'title' => 'Weekly Income', 'className' => 'dt-right'],
            ['data' => 'monthly_income', 'title' => 'Monthly Income', 'className' => 'dt-right'],
            ['data' => 'yearly_income', 'title' => 'Yearly Income', 'className' => 'dt-right'],
            ['data' => 'apr', 'title' => 'APR', 'className' => 'dt-right'],
        ];

        $configRungs = [];
        foreach ($config['rungs'] ?? [] as $cr) {
            $configRungs[$cr['rung']] = $cr;
        }

        $data = [];
        foreach ($rungs as $r) {
            $code = $r['rung'];
            $createdAt = $r['created_at'] ?? get_rung_created_at($configRungs[$code] ?? []) ?? $analysisStart;
            $rungStart = new DateTime(substr($createdAt, 0, 10));
            $effectiveStart = $rungStart > $startDate ? $rungStart : $startDate;
            $daysActive = max(1, (int) $endDate->diff($effectiveStart)->days + 1);

            $fees = (float) $r['fees_to_date'];
            $value = (float) $r['rung_value'];
            $daily = $fees / $daysActive;
            $wk = $daily * 7;
            $mo = $daily * 30;
            $yr = $daily * 365;
            $rungApr = $value > 0 ? ($yr / $value) * 100 : 0;

            $data[] = [
                'rung' => $code,
                'name' => $r['name'],
                'value' => $this->money($value),
                'value_raw' => $value,
                'days_active' => $daysActive,
                'fees_to_date' => $this->money($fees),
                'fees_raw' => $fees,
                'daily_income' => $this->money($daily),
                'weekly_income' => $this->money($wk),
                'monthly_income' => $this->money($mo),
                'yearly_income' => $this->money($yr),
                'apr' => $this->pct($rungApr),
                'apr_raw' => $rungApr,
            ];
        }

        return [
            'title' => 'LP Prediction — Current Window',
            'subtitle' => 'Portfolio Forecast',
            'summary' => $summary,
            'table_title' => 'Rung Forecast',
            'columns' => $columns,
            'data' => $data,
        ];
    }

    private function buildPredictionLatestDay(array $dailyMetrics, array $config): array
    {
        $days = $dailyMetrics['days'] ?? [];
        if (empty($days)) {
            return ['title' => 'LP Prediction — Latest Day', 'summary' => [], 'columns' => [], 'data' => [], 'error' => 'No data available'];
        }

        $day = end($days);
        $portfolio = $day['portfolio'] ?? [];
        $rungs = $day['rung_metrics'] ?? [];

        $totalFees = (float) ($portfolio['total_fees'] ?? 0);
        $portfolioValue = (float) ($portfolio['portfolio_value'] ?? 0);
        $weekly = $totalFees * 7;
        $monthly = $totalFees * 30;
        $yearly = $totalFees * 365;
        $apr = $portfolioValue > 0 ? ($yearly / $portfolioValue) * 100 : 0;

        $summary = [
            ['label' => 'Date', 'value' => $day['date'] ?? ''],
            ['label' => 'Daily Income', 'value' => $this->money($totalFees)],
            ['label' => 'Weekly Income', 'value' => $this->money($weekly)],
            ['label' => 'Monthly Income', 'value' => $this->money($monthly)],
            ['label' => 'Yearly Income', 'value' => $this->money($yearly)],
            ['label' => 'APR', 'value' => $this->pct($apr)],
        ];

        $columns = [
            ['data' => 'rung', 'title' => 'Rung'],
            ['data' => 'name', 'title' => 'Name'],
            ['data' => 'value', 'title' => 'Value', 'className' => 'dt-right'],
            ['data' => 'fees_today', 'title' => 'Fees Today', 'className' => 'dt-right'],
            ['data' => 'daily_income', 'title' => 'Daily Income', 'className' => 'dt-right'],
            ['data' => 'weekly_income', 'title' => 'Weekly Income', 'className' => 'dt-right'],
            ['data' => 'monthly_income', 'title' => 'Monthly Income', 'className' => 'dt-right'],
            ['data' => 'yearly_income', 'title' => 'Yearly Income', 'className' => 'dt-right'],
            ['data' => 'apr', 'title' => 'APR', 'className' => 'dt-right'],
        ];

        $data = [];
        $totals = ['value' => 0, 'fees' => 0, 'weekly' => 0, 'monthly' => 0, 'yearly' => 0];

        foreach ($rungs as $r) {
            $fees = (float) $r['fees'];
            $value = (float) $r['rung_value'];
            $wk = $fees * 7;
            $mo = $fees * 30;
            $yr = $fees * 365;
            $rungApr = $value > 0 ? ($yr / $value) * 100 : 0;

            $data[] = [
                'rung' => $r['rung'],
                'name' => $r['name'],
                'value' => $this->money($value),
                'value_raw' => $value,
                'fees_today' => $this->money($fees),
                'fees_raw' => $fees,
                'daily_income' => $this->money($fees),
                'weekly_income' => $this->money($wk),
                'monthly_income' => $this->money($mo),
                'yearly_income' => $this->money($yr),
                'apr' => $this->pct($rungApr),
                'apr_raw' => $rungApr,
            ];

            $totals['value'] += $value;
            $totals['fees'] += $fees;
            $totals['weekly'] += $wk;
            $totals['monthly'] += $mo;
            $totals['yearly'] += $yr;
        }

        $totalApr = $totals['value'] > 0 ? ($totals['yearly'] / $totals['value']) * 100 : 0;

        $footer = [
            'rung' => 'Total',
            'name' => '',
            'value' => $this->money($totals['value']),
            'fees_today' => $this->money($totals['fees']),
            'daily_income' => $this->money($totals['fees']),
            'weekly_income' => $this->money($totals['weekly']),
            'monthly_income' => $this->money($totals['monthly']),
            'yearly_income' => $this->money($totals['yearly']),
            'apr' => $this->pct($totalApr),
        ];

        return [
            'title' => 'LP Prediction — Latest Day',
            'subtitle' => 'Portfolio Forecast',
            'summary' => $summary,
            'table_title' => 'Rung Forecast',
            'columns' => $columns,
            'data' => $data,
            'footer' => $footer,
        ];
    }

    private function buildPredictionByDay(array $dailyMetrics, array $config): array
    {
        $days = $dailyMetrics['days'] ?? [];
        if (empty($days)) {
            return ['title' => 'LP Prediction — By Day', 'summary' => [], 'columns' => [], 'data' => [], 'error' => 'No data available'];
        }

        $totalDailyFees = 0;
        $portfolioValue = 0;
        $dayCount = count($days);

        foreach ($days as $day) {
            $portfolio = $day['portfolio'] ?? [];
            $totalDailyFees += (float) ($portfolio['total_fees'] ?? 0);
            $portfolioValue = (float) ($portfolio['portfolio_value'] ?? 0);
        }

        $avgDaily = $dayCount > 0 ? $totalDailyFees / $dayCount : 0;
        $weekly = $avgDaily * 7;
        $monthly = $avgDaily * 30;
        $yearly = $avgDaily * 365;
        $apr = $portfolioValue > 0 ? ($yearly / $portfolioValue) * 100 : 0;

        $summary = [
            ['label' => 'Days Analyzed', 'value' => (string) $dayCount],
            ['label' => 'Total Fees', 'value' => $this->money($totalDailyFees)],
            ['label' => 'Avg Daily Income', 'value' => $this->money($avgDaily)],
            ['label' => 'Weekly Income', 'value' => $this->money($weekly)],
            ['label' => 'Monthly Income', 'value' => $this->money($monthly)],
            ['label' => 'Yearly Income', 'value' => $this->money($yearly)],
            ['label' => 'APR', 'value' => $this->pct($apr)],
        ];

        $columns = [
            ['data' => 'date', 'title' => 'Date'],
            ['data' => 'daily_income', 'title' => 'Daily Income', 'className' => 'dt-right'],
            ['data' => 'weekly_income', 'title' => 'Weekly Income', 'className' => 'dt-right'],
            ['data' => 'monthly_income', 'title' => 'Monthly Income', 'className' => 'dt-right'],
            ['data' => 'yearly_income', 'title' => 'Yearly Income', 'className' => 'dt-right'],
            ['data' => 'apr', 'title' => 'APR', 'className' => 'dt-right'],
        ];

        $data = [];
        foreach ($days as $day) {
            $portfolio = $day['portfolio'] ?? [];
            $totalFees = (float) ($portfolio['total_fees'] ?? 0);
            $pv = (float) ($portfolio['portfolio_value'] ?? 0);
            $wk = $totalFees * 7;
            $mo = $totalFees * 30;
            $yr = $totalFees * 365;
            $dayApr = $pv > 0 ? ($yr / $pv) * 100 : 0;

            $data[] = [
                'date' => $day['date'],
                'daily_income' => $this->money($totalFees),
                'daily_raw' => $totalFees,
                'weekly_income' => $this->money($wk),
                'monthly_income' => $this->money($mo),
                'yearly_income' => $this->money($yr),
                'apr' => $this->pct($dayApr),
                'apr_raw' => $dayApr,
            ];
        }

        return [
            'title' => 'Daily Breakdown',
            'columns' => $columns,
            'data' => $data,
        ];
    }

    private function buildPredictionRungByDay(array $dailyMetrics, array $config): array
    {
        $days = $dailyMetrics['days'] ?? [];
        if (empty($days)) {
            return ['title' => 'LP Prediction — By Day', 'days' => [], 'error' => 'No data available'];
        }

        $dayTables = [];
        foreach ($days as $day) {
            if (!is_array($day)) {
                continue;
            }
            $dayTables[] = $this->buildPredictionDayTable($day, 'LP Prediction — By Day');
        }

        return [
            'title' => 'LP Prediction — By Day',
            'days' => $dayTables,
        ];
    }

    private function buildPredictionDayTable(array $day, string $title): array
    {
        $portfolio = $day['portfolio'] ?? [];
        $rungs = $day['rung_metrics'] ?? [];

        $totalFees = (float) ($portfolio['total_fees'] ?? 0);
        $portfolioValue = (float) ($portfolio['portfolio_value'] ?? 0);
        $weekly = $totalFees * 7;
        $monthly = $totalFees * 30;
        $yearly = $totalFees * 365;
        $apr = $portfolioValue > 0 ? ($yearly / $portfolioValue) * 100 : 0;

        $summary = [
            ['label' => 'Date', 'value' => $day['date'] ?? ''],
            ['label' => 'Daily Income', 'value' => $this->money($totalFees)],
            ['label' => 'Weekly Income', 'value' => $this->money($weekly)],
            ['label' => 'Monthly Income', 'value' => $this->money($monthly)],
            ['label' => 'Yearly Income', 'value' => $this->money($yearly)],
            ['label' => 'APR', 'value' => $this->pct($apr)],
        ];

        $columns = [
            ['data' => 'rung', 'title' => 'Rung'],
            ['data' => 'name', 'title' => 'Name'],
            ['data' => 'value', 'title' => 'Value', 'className' => 'dt-right'],
            ['data' => 'fees_today', 'title' => 'Fees Today', 'className' => 'dt-right'],
            ['data' => 'daily_income', 'title' => 'Daily Income', 'className' => 'dt-right'],
            ['data' => 'weekly_income', 'title' => 'Weekly Income', 'className' => 'dt-right'],
            ['data' => 'monthly_income', 'title' => 'Monthly Income', 'className' => 'dt-right'],
            ['data' => 'yearly_income', 'title' => 'Yearly Income', 'className' => 'dt-right'],
            ['data' => 'apr', 'title' => 'APR', 'className' => 'dt-right'],
        ];

        $data = [];
        $totals = ['value' => 0, 'fees' => 0, 'weekly' => 0, 'monthly' => 0, 'yearly' => 0];

        foreach ($rungs as $r) {
            $fees = (float) ($r['fees'] ?? 0);
            $value = (float) ($r['rung_value'] ?? 0);
            $wk = $fees * 7;
            $mo = $fees * 30;
            $yr = $fees * 365;
            $rungApr = $value > 0 ? ($yr / $value) * 100 : 0;

            $data[] = [
                'rung' => $r['rung'] ?? '',
                'name' => $r['name'] ?? '',
                'value' => $this->money($value),
                'value_raw' => $value,
                'fees_today' => $this->money($fees),
                'fees_raw' => $fees,
                'daily_income' => $this->money($fees),
                'weekly_income' => $this->money($wk),
                'monthly_income' => $this->money($mo),
                'yearly_income' => $this->money($yr),
                'apr' => $this->pct($rungApr),
                'apr_raw' => $rungApr,
            ];

            $totals['value'] += $value;
            $totals['fees'] += $fees;
            $totals['weekly'] += $wk;
            $totals['monthly'] += $mo;
            $totals['yearly'] += $yr;
        }

        $totalApr = $totals['value'] > 0 ? ($totals['yearly'] / $totals['value']) * 100 : 0;

        $footer = [
            'rung' => 'Total',
            'name' => '',
            'value' => $this->money($totals['value']),
            'fees_today' => $this->money($totals['fees']),
            'daily_income' => $this->money($totals['fees']),
            'weekly_income' => $this->money($totals['weekly']),
            'monthly_income' => $this->money($totals['monthly']),
            'yearly_income' => $this->money($totals['yearly']),
            'apr' => $this->pct($totalApr),
        ];

        return [
            'date' => $day['date'] ?? '',
            'title' => $title . ' — ' . ($day['date'] ?? ''),
            'subtitle' => 'Portfolio Forecast',
            'summary' => $summary,
            'table_title' => 'Rung Forecast',
            'columns' => $columns,
            'data' => $data,
            'footer' => $footer,
        ];
    }

    private function buildStatusDailyBreakdown(array $dailyMetrics, array $config): array
    {
        $days = $dailyMetrics['days'] ?? [];
        if (empty($days)) {
            return ['title' => 'Daily Breakdown', 'columns' => [], 'data' => [], 'error' => 'No data available'];
        }

        // Build revision timeline from config
        $rungs = $config['rungs'] ?? [];

        $columns = [
            ['data' => 'date', 'title' => 'Date'],
            ['data' => 'value', 'title' => 'Value', 'className' => 'dt-right'],
            ['data' => 'fees', 'title' => 'Fees', 'className' => 'dt-right'],
            ['data' => 'fees_pct', 'title' => 'Fees %', 'className' => 'dt-right'],
            ['data' => 'eligible_trs', 'title' => 'Eligible TRs', 'className' => 'dt-right'],
            ['data' => 'trs', 'title' => 'TRs', 'className' => 'dt-right'],
            ['data' => 'skipped', 'title' => 'Skipped', 'className' => 'dt-right'],
            ['data' => 'out_of_range', 'title' => 'Out of Range', 'className' => 'dt-right'],
            ['data' => 'depleted', 'title' => 'Depleted', 'className' => 'dt-right'],
            ['data' => 'utilization', 'title' => 'Utilization', 'className' => 'dt-right'],
        ];

        $data = [];
        foreach ($days as $day) {
            $portfolio = $day['portfolio'] ?? [];
            $meta = $day['meta'] ?? [];
            $rungMetrics = $day['rung_metrics'] ?? [];
            
            $totalFees = (float) ($portfolio['total_fees'] ?? 0);
            $trs = (int) ($meta['trs'] ?? 0);
            
            $totalEligible = 0;
            $totalTrades = 0;
            $totalSkipped = 0;
            $totalOutOfRange = 0;
            $totalDepleted = 0;
            
            foreach ($rungMetrics as $rm) {
                $totalEligible += (int) ($rm['eligible_trs'] ?? 0);
                $totalTrades += (int) ($rm['trades'] ?? 0);
                $totalSkipped += (int) ($rm['skipped_count'] ?? 0);
                $totalOutOfRange += (int) ($rm['out_of_range_count'] ?? 0);
                $totalDepleted += (int) ($rm['depleted_count'] ?? 0);
            }
            
            $utilization = $totalEligible > 0 ? ($totalTrades / $totalEligible) * 100 : 0;

            // Calculate portfolio value from config revisions active on this date
            $dayDate = $day['date'];
            $dayEnd = $dayDate . 'T23:59:59Z'; // End of day
            $portfolioValue = $this->getPortfolioValueAtDate($rungs, $dayEnd);
            
            $feesPct = $portfolioValue > 0 ? ($totalFees / $portfolioValue) * 100 : 0;
            
            $data[] = [
                'date' => $day['date'],
                'value' => $this->money($portfolioValue),
                'value_raw' => $portfolioValue,
                'fees' => $this->money($totalFees),
                'fees_raw' => $totalFees,
                'fees_pct' => number_format($feesPct, 4) . '%',
                'fees_pct_raw' => $feesPct,
                'eligible_trs' => (string) $totalEligible,
                'trs' => (string) $trs,
                'skipped' => (string) $totalSkipped,
                'out_of_range' => (string) $totalOutOfRange,
                'depleted' => (string) $totalDepleted,
                'utilization' => $this->pct($utilization),
                'utilization_raw' => $utilization,
            ];
        }

        return [
            'title' => 'Daily Breakdown',
            'columns' => $columns,
            'data' => $data,
        ];
    }

    private function getPortfolioValueAtDate(array $rungs, string $dateTime): float
    {
        $totalValue = 0;
        
        foreach ($rungs as $rung) {
            $revisions = $rung['revisions'] ?? [];
            $activeRevision = null;
            
            // Find the revision that was active at the given date/time
            foreach ($revisions as $revision) {
                $effectiveFrom = $revision['effective_from'] ?? null;
                $effectiveTo = $revision['effective_to'] ?? null;
                
                if ($effectiveFrom === null) {
                    continue;
                }
                
                // Check if this revision was active at the given time
                if ($dateTime >= $effectiveFrom) {
                    if ($effectiveTo === null || $dateTime < $effectiveTo) {
                        $activeRevision = $revision;
                    }
                }
            }
            
            if ($activeRevision !== null) {
                $totalValue += (float) ($activeRevision['initial_value']['total_usd'] ?? 0);
            }
        }
        
        return $totalValue;
    }

    private function buildScalpPerformance(array $digest, array $config): array
    {
        $rungs = $digest['active_rungs'] ?? [];
        $scalpRungs = array_filter($rungs, fn($r) => str_starts_with($r['rung'], 'S'));

        if (empty($scalpRungs)) {
            return ['title' => 'Scalp Performance', 'columns' => [], 'data' => [], 'error' => 'No scalp rungs found'];
        }

        $configRungs = [];
        foreach ($config['rungs'] ?? [] as $cr) {
            $configRungs[$cr['rung']] = $cr;
        }

        $asOfTimestamp = $digest['meta']['source_window']['end']
            ?? (($digest['meta']['as_of_date'] ?? null) !== null
                ? $digest['meta']['as_of_date'] . 'T23:59:59Z'
                : gmdate('Y-m-d\\TH:i:s\\Z'));

        $columns = [
            ['data' => 'rung', 'title' => 'Rung'],
            ['data' => 'value', 'title' => 'Value', 'className' => 'dt-right'],
            ['data' => 'range_lower', 'title' => 'Range Lower', 'className' => 'dt-right'],
            ['data' => 'range_upper', 'title' => 'Range Upper', 'className' => 'dt-right'],
            ['data' => 'range_width', 'title' => 'Range Width', 'className' => 'dt-right'],
            ['data' => 'fees', 'title' => 'Fees', 'className' => 'dt-right'],
            ['data' => 'fee_share', 'title' => 'Fee Share', 'className' => 'dt-right'],
            ['data' => 'eligible_trs', 'title' => 'Eligible TRs', 'className' => 'dt-right'],
            ['data' => 'trades', 'title' => 'Trades', 'className' => 'dt-right'],
            ['data' => 'skipped', 'title' => 'Skipped', 'className' => 'dt-right'],
            ['data' => 'out_of_range', 'title' => 'Out of Range', 'className' => 'dt-right'],
            ['data' => 'depleted', 'title' => 'Depleted', 'className' => 'dt-right'],
            ['data' => 'utilization', 'title' => 'Utilization', 'className' => 'dt-right'],
            ['data' => 'cap_efficiency', 'title' => 'Cap Efficiency', 'className' => 'dt-right'],
            ['data' => 'apr', 'title' => 'APR', 'className' => 'dt-right'],
        ];

        $data = [];
        foreach ($scalpRungs as $r) {
            $code = $r['rung'];
            $cr = $configRungs[$code] ?? [];
            $rev = get_rung_revision_at($cr, $asOfTimestamp);
            $lower = (float) ($rev['range_lower'] ?? $r['range_lower']);
            $upper = (float) ($rev['range_upper'] ?? $r['range_upper']);
            $width = $upper - $lower;
            $value = (float) $r['rung_value'];
            $fees = (float) $r['fees_to_date'];
            $capEff = $value > 0 ? $fees / $value : 0;
            $apr = (float) ($r['apr_gross'] ?? 0.0);

            $data[] = [
                'rung' => $code,
                'value' => $this->money($value),
                'value_raw' => $value,
                'range_lower' => number_format($lower, 4),
                'range_upper' => number_format($upper, 4),
                'range_width' => number_format($width, 4),
                'fees' => $this->money($fees),
                'fees_raw' => $fees,
                'fee_share' => $this->pct($r['fee_share_pct']),
                'eligible_trs' => (int) $r['eligible_trs'],
                'trades' => (int) $r['trades'],
                'skipped' => (int) $r['skipped_count'],
                'out_of_range' => (int) $r['out_of_range_count'],
                'depleted' => (int) $r['depleted_count'],
                'utilization' => $this->pct($r['utilization_pct']),
                'cap_efficiency' => number_format($capEff, 6),
                'apr' => $this->pct($apr * 100),
            ];
        }

        return [
            'title' => 'Scalp Performance',
            'columns' => $columns,
            'data' => $data,
        ];
    }

    private function buildStrategySummary(array $digest, array $config): array
    {
        $rungs = $digest['active_rungs'] ?? [];
        $meta = $digest['meta'] ?? [];
        
        // Calculate window days for proper APR annualization
        $analysisStart = $config['processing']['analysis_window_start'] ?? '2026-04-15T00:00:00Z';
        $asOfDate = $meta['as_of_date'] ?? date('Y-m-d');
        $startDate = new DateTime(substr($analysisStart, 0, 10));
        $endDate = new DateTime($asOfDate);
        $windowDays = max(1, (int) $endDate->diff($startDate)->days + 1);

        $groups = [
            'Core' => ['A', 'B', 'C'],
            'Signal' => ['D'],
            'Scalp' => ['S1', 'S2', 'S3'],
        ];

        $columns = [
            ['data' => 'group', 'title' => 'Group'],
            ['data' => 'total_value', 'title' => 'Total Value', 'className' => 'dt-right'],
            ['data' => 'total_fees', 'title' => 'Total Fees', 'className' => 'dt-right'],
            ['data' => 'fee_share', 'title' => 'Fee Share', 'className' => 'dt-right'],
            ['data' => 'eligible_trs', 'title' => 'Eligible TRs', 'className' => 'dt-right'],
            ['data' => 'trades', 'title' => 'Trades', 'className' => 'dt-right'],
            ['data' => 'skipped', 'title' => 'Skipped', 'className' => 'dt-right'],
            ['data' => 'out_of_range', 'title' => 'Out of Range', 'className' => 'dt-right'],
            ['data' => 'depleted', 'title' => 'Depleted', 'className' => 'dt-right'],
            ['data' => 'avg_utilization', 'title' => 'Avg Utilization', 'className' => 'dt-right'],
            ['data' => 'cap_efficiency', 'title' => 'Cap Efficiency', 'className' => 'dt-right'],
            ['data' => 'apr', 'title' => 'APR', 'className' => 'dt-right'],
        ];

        $rungMap = [];
        foreach ($rungs as $r) {
            $rungMap[$r['rung']] = $r;
        }

        $totalFees = array_sum(array_column($rungs, 'fees_to_date'));

        $data = [];
        foreach ($groups as $groupName => $codes) {
            $value = 0;
            $fees = 0;
            $eligible = 0;
            $trades = 0;
            $skipped = 0;
            $oor = 0;
            $depleted = 0;

            foreach ($codes as $code) {
                if (isset($rungMap[$code])) {
                    $r = $rungMap[$code];
                    $value += (float) $r['rung_value'];
                    $fees += (float) $r['fees_to_date'];
                    $eligible += (int) $r['eligible_trs'];
                    $trades += (int) $r['trades'];
                    $skipped += (int) $r['skipped_count'];
                    $oor += (int) $r['out_of_range_count'];
                    $depleted += (int) $r['depleted_count'];
                }
            }

            $feeShare = $totalFees > 0 ? ($fees / $totalFees) * 100 : 0;
            $avgUtil = $eligible > 0 ? ($trades / $eligible) * 100 : 0;
            $capEff = $value > 0 ? ($fees / $value) * 100 : 0;
            // APR = (fees / value / windowDays) * 365 * 100
            $dailyReturn = $value > 0 ? ($fees / $value) / $windowDays : 0;
            $apr = $dailyReturn * 365 * 100;

            $data[] = [
                'group' => $groupName,
                'total_value' => $this->money($value),
                'value_raw' => $value,
                'total_fees' => $this->money($fees),
                'fees_raw' => $fees,
                'fee_share' => $this->pct($feeShare),
                'eligible_trs' => $eligible,
                'trades' => $trades,
                'skipped' => $skipped,
                'out_of_range' => $oor,
                'depleted' => $depleted,
                'avg_utilization' => $this->pct($avgUtil),
                'cap_efficiency' => $this->pct($capEff),
                'apr' => $this->pct($apr),
            ];
        }

        return [
            'title' => 'Core vs Signal vs Scalp Summary',
            'columns' => $columns,
            'data' => $data,
        ];
    }

    private function buildBucketDistribution(array $dailyMetrics, array $config): array
    {
        $days = $dailyMetrics['days'] ?? [];
        if (empty($days)) {
            return ['title' => 'Bucket Distribution — Latest Day', 'columns' => [], 'data' => [], 'error' => 'No data available'];
        }

        $day = end($days);
        $buckets = $day['trade_buckets'] ?? [];

        if (empty($buckets)) {
            return ['title' => 'Bucket Distribution — Latest Day', 'columns' => [], 'data' => [], 'error' => "No bucket data for {$day['date']}"];
        }

        // Calculate totals for summary
        $totalVolume = 0;
        $totalTrs = 0;
        foreach ($buckets as $bucket) {
            $totalVolume += (float) ($bucket['total_volume_usd'] ?? 0);
            $totalTrs += (int) ($bucket['tr_count'] ?? 0);
        }

        $summary = [
            ['label' => 'Date', 'value' => $day['date']],
            ['label' => 'Volume', 'value' => $this->money($totalVolume)],
            ['label' => 'TRs', 'value' => (string) $totalTrs],
        ];

        $activeRungs = array_values(array_filter($config['rungs'] ?? [], fn($r) => !empty($r['active'])));
        $rungCodes = array_column($activeRungs, 'rung');

        $states = [
            ['key' => 'participating', 'label' => 'Participated', 'short' => 'P', 'class' => 'participating'],
            ['key' => 'out_of_range', 'label' => 'Out of range', 'short' => 'O', 'class' => 'out-of-range'],
            ['key' => 'skipped', 'label' => 'Skipped', 'short' => 'S', 'class' => 'skipped'],
        ];

        $columns = [
            ['data' => 'price_low', 'title' => 'Price Low', 'className' => 'dt-right'],
            ['data' => 'price_high', 'title' => 'Price High', 'className' => 'dt-right'],
            ['data' => 'trs', 'title' => 'TRs', 'className' => 'dt-right'],
            ['data' => 'volume', 'title' => 'Volume', 'className' => 'dt-right'],
        ];

        foreach ($rungCodes as $code) {
            $columns[] = [
                'data' => "rung_{$code}",
                'title' => $code,
                'className' => 'dt-center rung-status-cell',
                'render_as_html' => true,
            ];
        }

        usort($buckets, fn($a, $b) => $a['price_bucket_low'] <=> $b['price_bucket_low']);

        $data = [];
        foreach ($buckets as $bucket) {
            $row = [
                'price_low' => number_format($bucket['price_bucket_low'], 4),
                'price_high' => number_format($bucket['price_bucket_high'], 4),
                'trs' => (int) $bucket['tr_count'],
                'volume' => $this->money($bucket['total_volume_usd']),
                'volume_raw' => (float) $bucket['total_volume_usd'],
            ];

            foreach ($rungCodes as $code) {
                $counts = $bucket['rung_counts'][$code] ?? ['participating' => 0, 'skipped' => 0, 'out_of_range' => 0];
                $row["rung_{$code}"] = $this->rungStatusBadges($counts, $states);
            }

            $data[] = $row;
        }

        return [
            'title' => 'Bucket Distribution',
            'summary' => $summary,
            'columns' => $columns,
            'data' => $data,
        ];
    }

    private function rungStatusBadges(array $counts, array $states): string
    {
        $badges = [];

        foreach ($states as $state) {
            $count = (int) ($counts[$state['key']] ?? 0);
            if ($count <= 0) {
                continue;
            }

            $label = htmlspecialchars($state['label'], ENT_QUOTES, 'UTF-8');
            $class = htmlspecialchars($state['class'], ENT_QUOTES, 'UTF-8');
            $badges[] = sprintf(
                '<span class="rung-status-badge rung-status-badge--%s" title="%s" aria-label="%s %d">%d</span>',
                $class,
                $label,
                $label,
                $count,
                $count
            );
        }

        if ($badges === []) {
            return '';
        }

        return '<div class="rung-status-badges">' . implode('', $badges) . '</div>';
    }

    private function buildLatestTrades(array $digest, array $config): array
    {
        $trades = $digest['recent_tr_examples'] ?? [];

        if (empty($trades)) {
            return ['title' => 'Latest Trades', 'columns' => [], 'data' => [], 'error' => 'No recent trades available'];
        }

        $columns = [
            ['data' => 'timestamp', 'title' => 'Timestamp'],
            ['data' => 'price', 'title' => 'Price', 'className' => 'dt-right'],
            ['data' => 'volume', 'title' => 'Volume', 'className' => 'dt-right'],
            ['data' => 'fees', 'title' => 'Fees', 'className' => 'dt-right'],
            ['data' => 'participating', 'title' => 'Participating'],
            ['data' => 'skipped', 'title' => 'Skipped'],
            ['data' => 'out_of_range', 'title' => 'Out of Range'],
            ['data' => 'depleted', 'title' => 'Depleted'],
        ];

        $trades = array_reverse($trades);

        $data = [];
        foreach ($trades as $trade) {
            $ts = $trade['timestamp'] ?? '';
            $tsFormatted = $ts ? str_replace('T', ' ', substr($ts, 0, 19)) : '—';

            $data[] = [
                'timestamp' => $tsFormatted,
                'price' => number_format((float) ($trade['trade_price'] ?? 0), 4),
                'price_raw' => (float) ($trade['trade_price'] ?? 0),
                'volume' => $this->money((float) ($trade['volume_usd'] ?? 0)),
                'volume_raw' => (float) ($trade['volume_usd'] ?? 0),
                'fees' => $this->money((float) ($trade['fees_usd'] ?? 0)),
                'fees_raw' => (float) ($trade['fees_usd'] ?? 0),
                'participating' => implode(', ', $trade['participating_rungs'] ?? []) ?: '—',
                'skipped' => implode(', ', $trade['skipped_rungs'] ?? []) ?: '—',
                'out_of_range' => implode(', ', $trade['out_of_range_rungs'] ?? []) ?: '—',
                'depleted' => implode(', ', $trade['depleted_rungs'] ?? []) ?: '—',
            ];
        }

        return [
            'title' => 'Latest Trades',
            'columns' => $columns,
            'data' => $data,
        ];
    }

    private function buildTradesByDay(array $compactStore, array $config): array
    {
        $trades = $compactStore['trades'] ?? [];

        if (empty($trades)) {
            return ['title' => 'Trades by Day', 'days' => [], 'error' => 'No trades available'];
        }

        $analysisWindowStart = $config['processing']['analysis_window_start'] ?? '2026-04-15T00:00:00Z';
        $analysisWindowStartDate = substr($analysisWindowStart, 0, 10);

        $trades = array_filter($trades, fn($t) => ($t['date'] ?? '') >= $analysisWindowStartDate);

        $tradesByDate = [];
        foreach ($trades as $trade) {
            $date = $trade['date'] ?? '';
            if ($date) {
                $tradesByDate[$date][] = $trade;
            }
        }

        krsort($tradesByDate);

        $columns = [
            ['data' => 'timestamp', 'title' => 'Timestamp'],
            ['data' => 'price', 'title' => 'Price', 'className' => 'dt-right'],
            ['data' => 'volume', 'title' => 'Volume', 'className' => 'dt-right'],
            ['data' => 'fees', 'title' => 'Fees', 'className' => 'dt-right'],
            ['data' => 'participating', 'title' => 'Participating'],
            ['data' => 'skipped', 'title' => 'Skipped'],
            ['data' => 'out_of_range', 'title' => 'Out of Range'],
            ['data' => 'depleted', 'title' => 'Depleted'],
        ];

        $daysData = [];
        foreach ($tradesByDate as $date => $dayTrades) {
            usort($dayTrades, fn($a, $b) => ($b['timestamp'] ?? '') <=> ($a['timestamp'] ?? ''));

            $totalVolume = 0;
            $totalFees = 0;
            $minPrice = null;
            $maxPrice = null;
            $volumeWeightedPriceSum = 0;
            $data = [];

            foreach ($dayTrades as $trade) {
                $ts = $trade['timestamp'] ?? '';
                $tsFormatted = $ts ? substr($ts, 11, 8) : '—';

                $volume = (float) ($trade['totals']['filled_volume_usd'] ?? 0);
                $fees = (float) ($trade['totals']['fees_usd'] ?? 0);
                $price = (float) ($trade['trade_price'] ?? 0);

                $totalVolume += $volume;
                $totalFees += $fees;
                $volumeWeightedPriceSum += $price * $volume;
                
                if ($price > 0) {
                    if ($minPrice === null || $price < $minPrice) {
                        $minPrice = $price;
                    }
                    if ($maxPrice === null || $price > $maxPrice) {
                        $maxPrice = $price;
                    }
                }

                $data[] = [
                    'timestamp' => $tsFormatted,
                    'price' => number_format($price, 4),
                    'price_raw' => $price,
                    'volume' => $this->money($volume),
                    'volume_raw' => $volume,
                    'fees' => $this->money($fees),
                    'fees_raw' => $fees,
                    'participating' => implode(', ', $trade['totals']['participating_rungs'] ?? []) ?: '—',
                    'skipped' => implode(', ', $trade['totals']['skipped_rungs'] ?? []) ?: '—',
                    'out_of_range' => implode(', ', $trade['totals']['out_of_range_rungs'] ?? []) ?: '—',
                    'depleted' => implode(', ', $trade['totals']['depleted_rungs'] ?? []) ?: '—',
                ];
            }

            $vwap = $totalVolume > 0 ? $volumeWeightedPriceSum / $totalVolume : 0;
            $priceRange = ($minPrice !== null && $maxPrice !== null) 
                ? number_format($minPrice, 4) . ' – ' . number_format($maxPrice, 4) 
                : '—';

            // Build price distribution table
            $priceDistribution = [];
            foreach ($dayTrades as $trade) {
                $price = number_format((float) ($trade['trade_price'] ?? 0), 4);
                $volume = (float) ($trade['totals']['filled_volume_usd'] ?? 0);
                $fees = (float) ($trade['totals']['fees_usd'] ?? 0);
                
                if (!isset($priceDistribution[$price])) {
                    $priceDistribution[$price] = ['volume' => 0, 'fees' => 0];
                }
                $priceDistribution[$price]['volume'] += $volume;
                $priceDistribution[$price]['fees'] += $fees;
            }
            
            // Sort by price descending
            krsort($priceDistribution);
            
            $distributionColumns = [
                ['data' => 'price', 'title' => 'Price'],
                ['data' => 'volume', 'title' => 'Volume', 'className' => 'dt-right'],
                ['data' => 'volume_pct', 'title' => '% Volume', 'className' => 'dt-right'],
                ['data' => 'fees', 'title' => 'Fees', 'className' => 'dt-right'],
                ['data' => 'fees_pct', 'title' => '% Fees', 'className' => 'dt-right'],
            ];
            
            $distributionData = [];
            foreach ($priceDistribution as $price => $values) {
                $volPct = $totalVolume > 0 ? ($values['volume'] / $totalVolume) * 100 : 0;
                $feesPct = $totalFees > 0 ? ($values['fees'] / $totalFees) * 100 : 0;
                
                $distributionData[] = [
                    'price' => $price,
                    'volume' => $this->money($values['volume']),
                    'volume_raw' => $values['volume'],
                    'volume_pct' => '<div class="pct-bar"><div class="pct-bar-fill volume" style="width: ' . number_format($volPct * 4, 1) . 'px;"></div><span class="pct-bar-value">' . $this->pct($volPct) . '</span></div>',
                    'volume_pct_raw' => $volPct,
                    'fees' => $this->money($values['fees']),
                    'fees_raw' => $values['fees'],
                    'fees_pct' => '<div class="pct-bar"><div class="pct-bar-fill fees" style="width: ' . number_format($feesPct * 4, 1) . 'px;"></div><span class="pct-bar-value">' . $this->pct($feesPct) . '</span></div>',
                    'fees_pct_raw' => $feesPct,
                ];
            }

            $daysData[] = [
                'title' => "Date: {$date}",
                'date' => $date,
                'summary' => [
                    ['label' => 'Date', 'value' => $date],
                    ['label' => 'Trades', 'value' => (string) count($dayTrades)],
                    ['label' => 'Volume', 'value' => $this->money($totalVolume)],
                    ['label' => 'Fees', 'value' => $this->money($totalFees)],
                    ['label' => 'Price Range', 'value' => $priceRange],
                    ['label' => 'VWAP', 'value' => number_format($vwap, 4)],
                ],
                'hide_summary_title' => true,
                'trade_count' => count($dayTrades),
                'total_volume' => $this->money($totalVolume),
                'total_fees' => $this->money($totalFees),
                'columns' => $columns,
                'data' => $data,
                'distribution_columns' => $distributionColumns,
                'distribution_data' => $distributionData,
            ];
        }

        return [
            'title' => 'Trades by Day',
            'days' => $daysData,
        ];
    }

    private function buildRungPerformance(array $digest, array $dailyMetrics, array $config): array
    {
        $rungs = $digest['active_rungs'] ?? [];
        $days = $dailyMetrics['days'] ?? [];
        $activeConfigRungs = array_values(array_filter($config['rungs'] ?? [], fn($r) => !empty($r['active'])));

        if (empty($rungs) || empty($activeConfigRungs)) {
            return ['title' => 'Rung Performance', 'columns' => [], 'data' => [], 'error' => 'No active rungs found'];
        }

        $asOfTimestamp = strtotime($digest['meta']['source_window']['end'] ?? '');
        if ($asOfTimestamp === false) {
            $asOfDate = $digest['meta']['as_of_date'] ?? null;
            $asOfTimestamp = $asOfDate !== null ? strtotime($asOfDate . 'T23:59:59Z') : time();
        }
        $analysisWindowStart = (string) ($config['processing']['analysis_window_start'] ?? '');
        $windowStartTs = strtotime($analysisWindowStart);
        if ($windowStartTs === false) {
            $windowStartTs = strtotime(($digest['meta']['source_window']['start'] ?? '') ?: '');
        }
        if ($windowStartTs === false) {
            $windowStartTs = $asOfTimestamp;
        }

        $dayMetricsByDate = [];
        foreach ($days as $day) {
            if (!isset($day['date'])) {
                continue;
            }
            $dayMetricsByDate[(string) $day['date']] = $day;
        }

        $boundaries = [$windowStartTs, $asOfTimestamp + 1];
        foreach ($activeConfigRungs as $configRung) {
            foreach (($configRung['revisions'] ?? []) as $revision) {
                $effectiveFrom = (string) ($revision['effective_from'] ?? '');
                if ($effectiveFrom === '') {
                    continue;
                }
                $startTs = strtotime($effectiveFrom);
                if ($startTs === false) {
                    continue;
                }
                $effectiveTo = $revision['effective_to'] ?? null;
                $endTs = $effectiveTo !== null ? strtotime((string) $effectiveTo) : $asOfTimestamp + 1;
                if ($endTs === false) {
                    continue;
                }

                $clippedStart = max($startTs, $windowStartTs);
                $clippedEnd = min($endTs, $asOfTimestamp + 1);
                if ($clippedStart < $clippedEnd) {
                    $boundaries[] = $clippedStart;
                    $boundaries[] = $clippedEnd;
                }
            }
        }

        $boundaries = array_values(array_unique($boundaries));
        sort($boundaries);

        $columns = [
            ['data' => 'rung', 'title' => 'Rung'],
            ['data' => 'name', 'title' => 'Name'],
            ['data' => 'value', 'title' => 'Value', 'className' => 'dt-right'],
            ['data' => 'value_pct', 'title' => 'Value %', 'className' => 'dt-right'],
            ['data' => 'fees', 'title' => 'Fees', 'className' => 'dt-right'],
            ['data' => 'fee_pct', 'title' => 'Fee %', 'className' => 'dt-right'],
            ['data' => 'cap_eff', 'title' => 'Cap Eff', 'className' => 'dt-right'],
            ['data' => 'days', 'title' => 'Days', 'className' => 'dt-right'],
            ['data' => 'apr', 'title' => 'APR', 'className' => 'dt-right'],
            ['data' => 'utilization', 'title' => 'Util %', 'className' => 'dt-right'],
            ['data' => 'volume', 'title' => 'Volume', 'className' => 'dt-right'],
            ['data' => 'vol_per_day', 'title' => 'Vol/Day', 'className' => 'dt-right'],
        ];

        $periodTables = [];
        for ($i = count($boundaries) - 2; $i >= 0; $i--) {
            $periodStartTs = (int) $boundaries[$i];
            $periodEndTs = (int) $boundaries[$i + 1];
            if ($periodStartTs >= $periodEndTs) {
                continue;
            }

            $periodDays = max(1.0, ($periodEndTs - $periodStartTs) / 86400.0);
            $periodStartIso = gmdate('Y-m-d\TH:i:s\Z', $periodStartTs);
            $periodStartDate = gmdate('Y-m-d', $periodStartTs);
            $periodEndDateExclusive = gmdate('Y-m-d', $periodEndTs);
            $periodStartDateForDaily = gmdate('Y-m-d', $periodStartTs);
            $periodEndDateForDaily = gmdate('Y-m-d', $periodEndTs - 1);

            $rows = [];
            foreach ($activeConfigRungs as $configRung) {
                $revision = get_rung_revision_at($configRung, $periodStartIso);
                if ($revision === null) {
                    continue;
                }

                $code = (string) ($configRung['rung'] ?? '');
                $name = (string) ($configRung['name'] ?? '');
                $value = (float) ($revision['initial_value']['total_usd'] ?? 0);
                $fees = 0.0;
                $eligible = 0;
                $tradesCount = 0;
                $volume = 0.0;

                $cursor = $periodStartDateForDaily;
                while ($cursor <= $periodEndDateForDaily) {
                    $day = $dayMetricsByDate[$cursor] ?? null;
                    if (is_array($day)) {
                        foreach (($day['rung_metrics'] ?? []) as $metric) {
                            if ((string) ($metric['rung'] ?? '') !== $code) {
                                continue;
                            }
                            $fees += (float) ($metric['fees'] ?? 0);
                            $eligible += (int) ($metric['eligible_trs'] ?? 0);
                            $tradesCount += (int) ($metric['trades'] ?? 0);
                            $volume += (float) ($metric['filled_volume_usd'] ?? 0);
                            break;
                        }
                    }
                    $nextTs = strtotime($cursor . ' +1 day');
                    if ($nextTs === false) {
                        break;
                    }
                    $cursor = gmdate('Y-m-d', $nextTs);
                }

                $annualizedFees = $periodDays > 0 ? ($fees / $periodDays) * 365 : 0;
                $apr = $value > 0 ? ($annualizedFees / $value) * 100 : 0;
                $capEff = $value > 0 ? ($fees / $value) * 100 : 0;
                $utilization = $eligible > 0 ? ($tradesCount / $eligible) * 100 : 0;
                $volPerDay = $periodDays > 0 ? $volume / $periodDays : 0;

                $rows[] = [
                    'rung' => $code,
                    'name' => $name,
                    'value' => $this->money($value),
                    'value_raw' => $value,
                    'fees' => $this->money($fees),
                    'fees_raw' => $fees,
                    'cap_eff' => $this->pct($capEff),
                    'cap_eff_raw' => $capEff,
                    'days' => number_format($periodDays, 1),
                    'days_raw' => $periodDays,
                    'apr' => $this->pct($apr),
                    'apr_raw' => $apr,
                    'utilization' => $this->pct($utilization),
                    'utilization_raw' => $utilization,
                    'volume' => $this->money($volume),
                    'volume_raw' => $volume,
                    'vol_per_day' => $this->money($volPerDay),
                ];
            }

            if (empty($rows)) {
                continue;
            }

            $totalValue = array_sum(array_map(fn($row) => (float) $row['value_raw'], $rows));
            $totalFees = array_sum(array_map(fn($row) => (float) $row['fees_raw'], $rows));

            foreach ($rows as &$row) {
                $valueShare = $totalValue > 0 ? (((float) $row['value_raw'] / $totalValue) * 100) : 0;
                $feeShare = $totalFees > 0 ? (((float) $row['fees_raw'] / $totalFees) * 100) : 0;
                $row['value_pct'] = $this->pct($valueShare);
                $row['value_pct_raw'] = $valueShare;
                $row['fee_pct'] = $this->pct($feeShare);
                $row['fee_pct_raw'] = $feeShare;
            }
            unset($row);

            usort($rows, fn($a, $b) => $b['apr_raw'] <=> $a['apr_raw']);
            $totalCapEff = $totalValue > 0 ? ($totalFees / $totalValue) * 100 : 0;

            $periodStartLabel = gmdate('Y-m-d H:i', $periodStartTs) . ' UTC';
            $periodEndLabel = gmdate('Y-m-d H:i', $periodEndTs - 1) . ' UTC';
            $label = $periodStartLabel . ' to ' . $periodEndLabel;

            $periodTables[] = [
                'date' => $periodStartDate,
                'period_start' => $periodStartDate,
                'period_end' => $periodEndDateExclusive,
                'label' => $label,
                'title' => 'Rung Performance — ' . $label,
                'columns' => $columns,
                'data' => $rows,
                'footer' => [
                    'rung' => 'Total',
                    'name' => '',
                    'value' => $this->money($totalValue),
                    'value_pct' => '100.00%',
                    'fees' => $this->money($totalFees),
                    'fee_pct' => '100.00%',
                    'cap_eff' => $this->pct($totalCapEff),
                    'days' => '',
                    'apr' => '',
                    'utilization' => '',
                    'volume' => '',
                    'vol_per_day' => '',
                ],
                'note' => 'Filtered by revision period. Cap Eff = Fees/Value %. APR annualized from fees over period days.',
            ];
        }

        if (empty($periodTables)) {
            return ['title' => 'Rung Performance', 'days' => [], 'error' => 'No revision periods found in analysis window'];
        }

        return [
            'title' => 'Rung Performance',
            'days' => $periodTables,
        ];
    }

    private function buildRungs(array $config): array
    {
        $rungs = $config['rungs'] ?? [];
        $activeRungs = array_filter($rungs, fn($r) => !empty($r['active']));

        if (empty($activeRungs)) {
            return ['title' => 'Rungs', 'columns' => [], 'data' => [], 'error' => 'No active rungs configured'];
        }

        $columns = [
            ['data' => 'rung', 'title' => 'Rung'],
            ['data' => 'name', 'title' => 'Name'],
            ['data' => 'created', 'title' => 'Created'],
            ['data' => 'range_lower', 'title' => 'Range Lower', 'className' => 'dt-right'],
            ['data' => 'range_upper', 'title' => 'Range Upper', 'className' => 'dt-right'],
            ['data' => 'range_width', 'title' => 'Range Width', 'className' => 'dt-right'],
            ['data' => 'value', 'title' => 'Value', 'className' => 'dt-right'],
            ['data' => 'allocation', 'title' => 'Allocation', 'className' => 'dt-right'],
            ['data' => 'tags', 'title' => 'Tags'],
        ];

        $data = [];
        $totalValue = 0;

        foreach ($activeRungs as $r) {
            $rev = get_current_revision($r);
            $createdAt = get_rung_created_at($r);
            $lower = $rev !== null ? (float) $rev['range_lower'] : 0.0;
            $upper = $rev !== null ? (float) $rev['range_upper'] : 0.0;
            $width = $upper - $lower;
            $value = $rev !== null ? (float) ($rev['initial_value']['total_usd'] ?? 0) : 0.0;
            $allocation = $rev !== null ? (float) ($rev['target_allocation_pct'] ?? 0) : 0.0;
            $created = $createdAt !== null ? substr($createdAt, 0, 10) : '';
            $tags = implode(', ', $r['tags'] ?? []);

            $data[] = [
                'rung' => $r['rung'],
                'name' => $r['name'],
                'created' => $created,
                'range_lower' => number_format($lower, 4),
                'range_upper' => number_format($upper, 4),
                'range_width' => number_format($width, 4),
                'value' => $this->money($value),
                'value_raw' => $value,
                'allocation' => $this->pct($allocation),
                'tags' => $tags,
            ];

            $totalValue += $value;
        }

        $footer = [
            'rung' => 'Total',
            'name' => '',
            'created' => '',
            'range_lower' => '',
            'range_upper' => '',
            'range_width' => '',
            'value' => $this->money($totalValue),
            'allocation' => '',
            'tags' => '',
        ];

        return [
            'title' => 'Rungs',
            'columns' => $columns,
            'data' => $data,
            'footer' => $footer,
        ];
    }

    private function buildConfigBalances(array $digest, array $config): array
    {
        $activeRungs = array_filter($config['rungs'] ?? [], fn($r) => !empty($r['active']));

        // Load live balances/orders summary if available
        $summaryFile = $config['paths']['balances_orders_summary_file'] ?? null;
        $summary = null;
        if ($summaryFile !== null && file_exists($summaryFile)) {
            $decoded = json_decode(file_get_contents($summaryFile), true);
            if (is_array($decoded)) {
                $summary = $decoded;
            }
        }

        // Index USDT/USDC range orders by [tick_start, tick_end] from the first LP in the summary
        // Computes per-order USDT/USDC amounts using concentrated liquidity (Uniswap v3) math
        $ordersByTick = [];   // key: "t_lower:t_upper" => ['usdt' => float, 'usdc' => float]
        $poolPrice    = null;
        if ($summary !== null) {
            $lp = $summary['lps'][0] ?? null;
            if ($lp !== null) {
                $poolPrice = 0.0;
                foreach ($lp['open_orders']['pairs'] ?? [] as $pair) {
                    if (($pair['base_asset'] ?? '') === 'Usdt' && ($pair['quote_asset'] ?? '') === 'Usdc') {
                        $poolPrice = (float) ($pair['pool_range_order_price'] ?? 0);
                        break;
                    }
                }
                if ($poolPrice <= 0) {
                    $poolPrice = (float) ($lp['open_orders']['current_usdt_usdc_pool_ratio'] ?? 0);
                }
                if ($poolPrice > 0) {
                    $sqrtPc = sqrt($poolPrice);
                    foreach ($lp['open_orders']['pairs'] ?? [] as $pair) {
                        if (($pair['base_asset'] ?? '') !== 'Usdt') {
                            continue;
                        }
                        foreach ($pair['range_orders'] ?? [] as $order) {
                            $tl = (int) $order['range_start_tick'];
                            $tu = (int) $order['range_end_tick'];
                            $L  = (float) $order['liquidity'];
                            // Price at tick t = 1.0001^t
                            $pLower = pow(1.0001, $tl);
                            $pUpper = pow(1.0001, $tu);
                            $sqrtPl = sqrt($pLower);
                            $sqrtPu = sqrt($pUpper);
                            // Concentrated liquidity split (amounts in raw units, divide by 1e6 for USD)
                            if ($poolPrice >= $pUpper) {
                                $usdt = 0.0;
                                $usdc = $L * ($sqrtPu - $sqrtPl) / 1e6;
                            } elseif ($poolPrice <= $pLower) {
                                $usdt = $L * (1.0 / $sqrtPl - 1.0 / $sqrtPu) / 1e6;
                                $usdc = 0.0;
                            } else {
                                $usdt = $L * (1.0 / $sqrtPc - 1.0 / $sqrtPu) / 1e6;
                                $usdc = $L * ($sqrtPc - $sqrtPl) / 1e6;
                            }
                            $ordersByTick[$tl . ':' . $tu] = ['usdt' => $usdt, 'usdc' => $usdc];
                        }
                    }
                }
            }
        }

        $hasFeed = !empty($ordersByTick);
        $footnote = $hasFeed
            ? 'Current USDT/USDC amounts computed from on-chain liquidity at pool price ' . number_format((float) $poolPrice, 6) . '. Orig = inception values from config revisions.'
            : 'Current USDT/USDC amounts not available (balances feed missing). Showing inception values as fallback.';

        $columns = [
            ['data' => 'rung',          'title' => 'Rung'],
            ['data' => 'name',          'title' => 'Name'],
            ['data' => 'cur_usdt',      'title' => 'Cur USDT',      'className' => 'dt-right', 'render_as_html' => true],
            ['data' => 'cur_usdc',      'title' => 'Cur USDC',      'className' => 'dt-right', 'render_as_html' => true],
            ['data' => 'current_total', 'title' => 'Current Total', 'className' => 'dt-right'],
            ['data' => 'current_ratio', 'title' => 'USDT/USDC',     'className' => 'dt-right'],
            ['data' => 'share_pct',     'title' => '% of Total',    'className' => 'dt-right'],
        ];

        // First pass: collect raw values
        $rows = [];
        $totalOrigUsdt = 0.0;
        $totalOrigUsdc = 0.0;
        $totalCurUsdt  = 0.0;
        $totalCurUsdc  = 0.0;
        $totalCurrent  = 0.0;

        foreach ($activeRungs as $r) {
            $rev = get_current_revision($r);
            $origUsdt = $rev !== null ? (float) ($rev['initial_value']['USDT'] ?? 0) : 0.0;
            $origUsdc = $rev !== null ? (float) ($rev['initial_value']['USDC'] ?? 0) : 0.0;

            $curUsdt = $origUsdt;
            $curUsdc = $origUsdc;
            if ($hasFeed && $rev !== null) {
                $rangeLower = (float) ($rev['range_lower'] ?? 0);
                $rangeUpper = (float) ($rev['range_upper'] ?? 0);
                if ($rangeLower > 0 && $rangeUpper > 0) {
                    $tl  = (int) round(log($rangeLower) / log(1.0001));
                    $tu  = (int) round(log($rangeUpper) / log(1.0001));
                    $key = $tl . ':' . $tu;
                    if (isset($ordersByTick[$key])) {
                        $curUsdt = $ordersByTick[$key]['usdt'];
                        $curUsdc = $ordersByTick[$key]['usdc'];
                    }
                }
            }

            $currentTotal = $curUsdt + $curUsdc;
            $totalOrigUsdt += $origUsdt;
            $totalOrigUsdc += $origUsdc;
            $totalCurUsdt  += $curUsdt;
            $totalCurUsdc  += $curUsdc;
            $totalCurrent  += $currentTotal;

            $rows[] = [
                'rung'         => $r['rung'],
                'name'         => $r['name'],
                'origUsdt'     => $origUsdt,
                'origUsdc'     => $origUsdc,
                'curUsdt'      => $curUsdt,
                'curUsdc'      => $curUsdc,
                'currentTotal' => $currentTotal,
            ];
        }

        // Second pass: compute share and build output rows
        $data = [];
        foreach ($rows as $row) {
            $share     = $totalCurrent > 0 ? ($row['currentTotal'] / $totalCurrent) * 100 : 0.0;
            $curRatio  = $row['curUsdc'] > 0 ? number_format($row['curUsdt'] / $row['curUsdc'], 4) : 'N/A';
            $usdtDiff  = $row['curUsdt'] - $row['origUsdt'];
            $usdcDiff  = $row['curUsdc'] - $row['origUsdc'];
            $usdtClass = $usdtDiff > 0 ? 'delta-pos' : ($usdtDiff < 0 ? 'delta-neg' : 'delta-flat');
            $usdcClass = $usdcDiff > 0 ? 'delta-pos' : ($usdcDiff < 0 ? 'delta-neg' : 'delta-flat');
            $usdtSign  = $usdtDiff > 0 ? '+' : '';
            $usdcSign  = $usdcDiff > 0 ? '+' : '';
            $usdtText  = number_format($usdtDiff, 2, '.', ',');
            $usdcText  = number_format($usdcDiff, 2, '.', ',');

            $data[] = [
                'rung'          => $row['rung'],
                'name'          => $row['name'],
                'cur_usdt'      => $this->money($row['curUsdt']) . ' <span class="delta-tail ' . $usdtClass . '">(' . $usdtSign . $usdtText . ')</span>',
                'cur_usdc'      => $this->money($row['curUsdc']) . ' <span class="delta-tail ' . $usdcClass . '">(' . $usdcSign . $usdcText . ')</span>',
                'current_total' => $this->money($row['currentTotal']),
                'share_pct'     => $this->pct($share),
                'share_pct_raw' => $share,
                'current_ratio' => $curRatio,
            ];
        }

        $footer = [
            'rung'          => 'Total',
            'name'          => '',
            'cur_usdt'      => $this->money($totalCurUsdt),
            'cur_usdc'      => $this->money($totalCurUsdc),
            'current_total' => $this->money($totalCurrent),
            'current_ratio' => '',
            'share_pct'     => '100.00%',
        ];

        return [
            'title'    => 'Balances',
            'footnote' => $footnote,
            'columns'  => $columns,
            'data'     => $data,
            'footer'   => $footer,
        ];
    }

    private function buildConfigRebalance(array $config): array
    {
        $activeRungs = array_filter($config['rungs'] ?? [], fn($r) => !empty($r['active']));

        $summaryFile = $config['paths']['balances_orders_summary_file'] ?? null;
        $summary = null;
        if ($summaryFile !== null && file_exists($summaryFile)) {
            $decoded = json_decode(file_get_contents($summaryFile), true);
            if (is_array($decoded)) {
                $summary = $decoded;
            }
        }

        $ordersByTick = [];
        $poolPrice = null;
        if ($summary !== null) {
            $lp = $summary['lps'][0] ?? null;
            if ($lp !== null) {
                $poolPrice = (float) ($lp['open_orders']['current_usdt_usdc_pool_ratio'] ?? 0);
                if ($poolPrice > 0) {
                    $sqrtPc = sqrt($poolPrice);
                    foreach ($lp['open_orders']['pairs'] ?? [] as $pair) {
                        if (($pair['base_asset'] ?? '') !== 'Usdt') {
                            continue;
                        }
                        foreach ($pair['range_orders'] ?? [] as $order) {
                            $tl = (int) $order['range_start_tick'];
                            $tu = (int) $order['range_end_tick'];
                            $L = (float) $order['liquidity'];

                            $pLower = pow(1.0001, $tl);
                            $pUpper = pow(1.0001, $tu);
                            $sqrtPl = sqrt($pLower);
                            $sqrtPu = sqrt($pUpper);

                            if ($poolPrice >= $pUpper) {
                                $usdt = 0.0;
                                $usdc = $L * ($sqrtPu - $sqrtPl) / 1e6;
                            } elseif ($poolPrice <= $pLower) {
                                $usdt = $L * (1.0 / $sqrtPl - 1.0 / $sqrtPu) / 1e6;
                                $usdc = 0.0;
                            } else {
                                $usdt = $L * (1.0 / $sqrtPc - 1.0 / $sqrtPu) / 1e6;
                                $usdc = $L * ($sqrtPc - $sqrtPl) / 1e6;
                            }

                            $ordersByTick[$tl . ':' . $tu] = ['usdt' => $usdt, 'usdc' => $usdc];
                        }
                    }
                }
            }
        }

        $rows = [];
        $totalCurrent = 0.0;

        foreach ($activeRungs as $r) {
            $rev = get_current_revision($r);
            if ($rev === null) {
                continue;
            }

            $origUsdt = (float) ($rev['initial_value']['USDT'] ?? 0);
            $origUsdc = (float) ($rev['initial_value']['USDC'] ?? 0);

            $curUsdt = $origUsdt;
            $curUsdc = $origUsdc;

            $rangeLower = (float) ($rev['range_lower'] ?? 0);
            $rangeUpper = (float) ($rev['range_upper'] ?? 0);
            if (!empty($ordersByTick) && $rangeLower > 0 && $rangeUpper > 0) {
                $tl = (int) round(log($rangeLower) / log(1.0001));
                $tu = (int) round(log($rangeUpper) / log(1.0001));
                $key = $tl . ':' . $tu;
                if (isset($ordersByTick[$key])) {
                    $curUsdt = (float) $ordersByTick[$key]['usdt'];
                    $curUsdc = (float) $ordersByTick[$key]['usdc'];
                }
            }

            $currentTotal = $curUsdt + $curUsdc;
            $totalCurrent += $currentTotal;

            $rangeRatio = $this->calculateRangeUsdtUsdcRatio(
                $poolPrice,
                (float) ($rev['range_lower'] ?? 0),
                (float) ($rev['range_upper'] ?? 0),
                (string) ($config['pair']['price_definition'] ?? 'quote_per_base')
            );

            $rows[] = [
                'rung' => (string) ($r['rung'] ?? ''),
                'name' => (string) ($r['name'] ?? ''),
                'range_lower' => $this->precise((float) ($rev['range_lower'] ?? 0)),
                'range_upper' => $this->precise((float) ($rev['range_upper'] ?? 0)),
                'current_usdt' => $curUsdt,
                'current_usdc' => $curUsdc,
                'current_ratio' => $rangeRatio,
                'live_ratio' => $curUsdc > 0 ? ($curUsdt / $curUsdc) : 1.0,
                'current_total' => $currentTotal,
            ];
        }

        foreach ($rows as &$row) {
            $row['share_pct'] = $totalCurrent > 0 ? ($row['current_total'] / $totalCurrent) * 100 : 0.0;
        }
        unset($row);

        $footnote = $poolPrice !== null && $poolPrice > 0
            ? 'Re-balance uses current range ratio derived from live liquidity at pool price ' . number_format($poolPrice, 6) . '.'
            : 'Re-balance uses current values from config revisions because balances feed is unavailable.';

        return [
            'title' => 'Re-balance Calculator',
            'footnote' => $footnote,
            'pool_ratio' => $poolPrice,
            'total_current' => $totalCurrent,
            'rows' => $rows,
        ];
    }

    private function precise(float $value): string
    {
        if ($value === 0.0) {
            return '0';
        }
        return rtrim(rtrim(sprintf('%.16F', $value), '0'), '.');
    }

    private function calculateRangeUsdtUsdcRatio(?float $poolPrice, float $rangeLower, float $rangeUpper, string $priceDefinition): float
    {
        if ($poolPrice === null || $poolPrice <= 0 || $rangeLower <= 0 || $rangeUpper <= 0) {
            return 1.0;
        }

        $p = $poolPrice;
        $lower = min($rangeLower, $rangeUpper);
        $upper = max($rangeLower, $rangeUpper);

        // Rebalance expectations are in USDT/USDC. For quote_per_base configs,
        // convert into base_per_quote space for stable and intuitive range composition.
        if ($priceDefinition === 'quote_per_base') {
            $p = 1.0 / $poolPrice;
            $lower = 1.0 / max($rangeLower, $rangeUpper);
            $upper = 1.0 / min($rangeLower, $rangeUpper);
        }

        $sqrtP = sqrt($p);
        $sqrtL = sqrt($lower);
        $sqrtU = sqrt($upper);

        // token0 = USDC, token1 = USDT in this space
        if ($p <= $lower) {
            $usdt = 0.0;
            $usdc = max(0.0, (1.0 / $sqrtL) - (1.0 / $sqrtU));
        } elseif ($p >= $upper) {
            $usdt = max(0.0, $sqrtU - $sqrtL);
            $usdc = 0.0;
        } else {
            $usdt = max(0.0, $sqrtP - $sqrtL);
            $usdc = max(0.0, (1.0 / $sqrtP) - (1.0 / $sqrtU));
        }

        if ($usdc <= 0.0) {
            return 1.0;
        }

        return $usdt / $usdc;
    }

    private function money(float $value): string
    {
        return number_format($value, 2, '.', ',');
    }

    private function pct(float $value): string
    {
        return number_format($value, 2, '.', ',') . '%';
    }
}
