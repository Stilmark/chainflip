<?php

declare(strict_types=1);

require_once __DIR__ . '/Helpers.php';

final class TableBuilder
{
    public function generateAll(array $digest, array $dailyMetrics, array $config, string $outputDir): array
    {
        $outputDir = rtrim($outputDir, '/');

        $subDirs = ['config', 'status', 'prediction', 'performance', 'distribution'];
        foreach ($subDirs as $sub) {
            ensure_dir($outputDir . '/' . $sub);
        }

        $files = [];

        $files['config/rungs.md'] = $this->buildRungs($config);
        $files['status/current.md'] = $this->buildStatusCurrent($digest, $config);
        $files['status/latestDay.md'] = $this->buildStatusLatestDay($dailyMetrics, $config);
        $files['status/byDay.md'] = $this->buildStatusByDay($dailyMetrics, $config);
        $files['prediction/current.md'] = $this->buildPredictionCurrent($digest, $config);
        $files['prediction/latestDay.md'] = $this->buildPredictionLatestDay($dailyMetrics, $config);
        $files['prediction/byDay.md'] = $this->buildPredictionByDay($dailyMetrics, $config);
        $files['performance/scalp.md'] = $this->buildScalpPerformance($digest, $config);
        $files['performance/strategy.md'] = $this->buildStrategySummary($digest, $config);
        $files['distribution/bucketLatestDay.md'] = $this->buildBucketDistribution($dailyMetrics, $config);

        foreach ($files as $filename => $content) {
            file_put_contents($outputDir . '/' . $filename, $content);
        }

        return array_keys($files);
    }

    private function buildStatusCurrent(array $digest, array $config): string
    {
        $meta = $digest['meta'] ?? [];
        $portfolio = $digest['portfolio'] ?? [];
        $rungs = $digest['active_rungs'] ?? [];

        $out = "# LP Status — Current Window\n\n";
        $out .= "| Field | Value |\n";
        $out .= "|-------|------:|\n";
        $out .= "| Portfolio Value | " . $this->money($portfolio['portfolio_value'] ?? 0) . " |\n";
        $out .= "| Total Fees | " . $this->money($portfolio['total_fees_to_date'] ?? 0) . " |\n";
        $out .= "| Total TRs | " . ($meta['total_trs_in_scope'] ?? 0) . " |\n";
        $out .= "| Analysis Window Start | " . ($meta['analysis_window_start'] ?? '') . " |\n";
        $out .= "| As Of Date | " . ($meta['as_of_date'] ?? '') . " |\n";
        $out .= "\n";

        $out .= "| Rung | Name | Value | Fees | Fee Share | Eligible TRs | Trades | Skipped | Out of Range | Depleted | Utilization |\n";
        $out .= "|------|------|------:|-----:|----------:|-------------:|-------:|--------:|-------------:|---------:|------------:|\n";

        $totals = [
            'value' => 0, 'fees' => 0, 'eligible_trs' => 0, 'trades' => 0,
            'skipped' => 0, 'out_of_range' => 0, 'depleted' => 0
        ];

        foreach ($rungs as $r) {
            $out .= "| {$r['rung']} | {$r['name']} | " . $this->money($r['rung_value']) . " | " . $this->money($r['fees_to_date']) . " | " . $this->pct($r['fee_share_pct']) . " | {$r['eligible_trs']} | {$r['trades']} | {$r['skipped_count']} | {$r['out_of_range_count']} | {$r['depleted_count']} | " . $this->pct($r['utilization_pct']) . " |\n";

            $totals['value'] += $r['rung_value'];
            $totals['fees'] += $r['fees_to_date'];
            $totals['eligible_trs'] += $r['eligible_trs'];
            $totals['trades'] += $r['trades'];
            $totals['skipped'] += $r['skipped_count'];
            $totals['out_of_range'] += $r['out_of_range_count'];
            $totals['depleted'] += $r['depleted_count'];
        }

        $totalUtil = $totals['eligible_trs'] > 0 ? ($totals['trades'] / $totals['eligible_trs']) * 100 : 0;
        $out .= "| **Total** | | " . $this->money($totals['value']) . " | " . $this->money($totals['fees']) . " | 100.00% | {$totals['eligible_trs']} | {$totals['trades']} | {$totals['skipped']} | {$totals['out_of_range']} | {$totals['depleted']} | " . $this->pct($totalUtil) . " |\n";

        return $out;
    }

    private function buildStatusLatestDay(array $dailyMetrics, array $config): string
    {
        $days = $dailyMetrics['days'] ?? [];
        if (empty($days)) {
            return "# LP Status — Latest Day\n\nNo data available.\n";
        }

        $day = end($days);
        return $this->buildDayStatusTable($day, "LP Status — Latest Day");
    }

    private function buildStatusByDay(array $dailyMetrics, array $config): string
    {
        $days = $dailyMetrics['days'] ?? [];
        if (empty($days)) {
            return "# LP Status — By Day\n\nNo data available.\n";
        }

        $out = "# LP Status — By Day\n\n";

        foreach ($days as $day) {
            $out .= $this->buildDayStatusTable($day, "Date: {$day['date']}");
            $out .= "\n---\n\n";
        }

        return $out;
    }

    private function buildDayStatusTable(array $day, string $title): string
    {
        $meta = $day['meta'] ?? [];
        $portfolio = $day['portfolio'] ?? [];
        $rungs = $day['rung_metrics'] ?? [];

        $out = "## {$title}\n\n";
        $out .= "| Field | Value |\n";
        $out .= "|-------|------:|\n";
        $out .= "| Date | " . ($day['date'] ?? '') . " |\n";
        $out .= "| Portfolio Value | " . $this->money($portfolio['portfolio_value'] ?? 0) . " |\n";
        $out .= "| Total Fees | " . $this->money($portfolio['total_fees'] ?? 0) . " |\n";
        $out .= "| TRs | " . ($meta['trs'] ?? 0) . " |\n";
        $out .= "\n";

        $out .= "| Rung | Name | Value | Fees | Fee Share | Eligible TRs | Trades | Skipped | Out of Range | Depleted | Utilization |\n";
        $out .= "|------|------|------:|-----:|----------:|-------------:|-------:|--------:|-------------:|---------:|------------:|\n";

        $totals = [
            'value' => 0, 'fees' => 0, 'eligible_trs' => 0, 'trades' => 0,
            'skipped' => 0, 'out_of_range' => 0, 'depleted' => 0
        ];

        foreach ($rungs as $r) {
            $out .= "| {$r['rung']} | {$r['name']} | " . $this->money($r['rung_value']) . " | " . $this->money($r['fees']) . " | " . $this->pct($r['fee_share_pct']) . " | {$r['eligible_trs']} | {$r['trades']} | {$r['skipped_count']} | {$r['out_of_range_count']} | {$r['depleted_count']} | " . $this->pct($r['utilization_pct']) . " |\n";

            $totals['value'] += $r['rung_value'];
            $totals['fees'] += $r['fees'];
            $totals['eligible_trs'] += $r['eligible_trs'];
            $totals['trades'] += $r['trades'];
            $totals['skipped'] += $r['skipped_count'];
            $totals['out_of_range'] += $r['out_of_range_count'];
            $totals['depleted'] += $r['depleted_count'];
        }

        $totalUtil = $totals['eligible_trs'] > 0 ? ($totals['trades'] / $totals['eligible_trs']) * 100 : 0;
        $out .= "| **Total** | | " . $this->money($totals['value']) . " | " . $this->money($totals['fees']) . " | 100.00% | {$totals['eligible_trs']} | {$totals['trades']} | {$totals['skipped']} | {$totals['out_of_range']} | {$totals['depleted']} | " . $this->pct($totalUtil) . " |\n";

        return $out;
    }

    private function buildPredictionCurrent(array $digest, array $config): string
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
        $apy = $portfolioValue > 0 ? ($yearly / $portfolioValue) * 100 : 0;

        $out = "# LP Prediction — Current Window\n\n";
        $out .= "## Portfolio Forecast\n\n";
        $out .= "| Field | Value |\n";
        $out .= "|-------|------:|\n";
        $out .= "| Window Days | {$windowDays} |\n";
        $out .= "| Total Fees | " . $this->money($totalFees) . " |\n";
        $out .= "| Avg Daily Income | " . $this->money($avgDaily) . " |\n";
        $out .= "| Weekly Income | " . $this->money($weekly) . " |\n";
        $out .= "| Monthly Income | " . $this->money($monthly) . " |\n";
        $out .= "| Yearly Income | " . $this->money($yearly) . " |\n";
        $out .= "| APY | " . $this->pct($apy) . " |\n";
        $out .= "\n";

        $out .= "## Rung Forecast\n\n";
        $out .= "| Rung | Name | Value | Days Active | Fees to Date | Daily Income | Weekly Income | Monthly Income | Yearly Income | APY |\n";
        $out .= "|------|------|------:|------------:|-------------:|-------------:|--------------:|---------------:|--------------:|----:|\n";

        $configRungs = [];
        foreach ($config['rungs'] ?? [] as $cr) {
            $configRungs[$cr['rung']] = $cr;
        }

        foreach ($rungs as $r) {
            $code = $r['rung'];
            $createdAt = $configRungs[$code]['created_at'] ?? $analysisStart;
            $rungStart = new DateTime(substr($createdAt, 0, 10));
            $effectiveStart = $rungStart > $startDate ? $rungStart : $startDate;
            $daysActive = max(1, (int) $endDate->diff($effectiveStart)->days + 1);

            $fees = (float) $r['fees_to_date'];
            $value = (float) $r['rung_value'];
            $daily = $fees / $daysActive;
            $wk = $daily * 7;
            $mo = $daily * 30;
            $yr = $daily * 365;
            $rungApy = $value > 0 ? ($yr / $value) * 100 : 0;

            $out .= "| {$code} | {$r['name']} | " . $this->money($value) . " | {$daysActive} | " . $this->money($fees) . " | " . $this->money($daily) . " | " . $this->money($wk) . " | " . $this->money($mo) . " | " . $this->money($yr) . " | " . $this->pct($rungApy) . " |\n";
        }

        return $out;
    }

    private function buildPredictionLatestDay(array $dailyMetrics, array $config): string
    {
        $days = $dailyMetrics['days'] ?? [];
        if (empty($days)) {
            return "# LP Prediction — Latest Day\n\nNo data available.\n";
        }

        $day = end($days);
        $portfolio = $day['portfolio'] ?? [];
        $rungs = $day['rung_metrics'] ?? [];

        $totalFees = (float) ($portfolio['total_fees'] ?? 0);
        $portfolioValue = (float) ($portfolio['portfolio_value'] ?? 0);
        $weekly = $totalFees * 7;
        $monthly = $totalFees * 30;
        $yearly = $totalFees * 365;
        $apy = $portfolioValue > 0 ? ($yearly / $portfolioValue) * 100 : 0;

        $out = "# LP Prediction — Latest Day\n\n";
        $out .= "## Portfolio Forecast\n\n";
        $out .= "| Field | Value |\n";
        $out .= "|-------|------:|\n";
        $out .= "| Date | " . ($day['date'] ?? '') . " |\n";
        $out .= "| Daily Income | " . $this->money($totalFees) . " |\n";
        $out .= "| Weekly Income | " . $this->money($weekly) . " |\n";
        $out .= "| Monthly Income | " . $this->money($monthly) . " |\n";
        $out .= "| Yearly Income | " . $this->money($yearly) . " |\n";
        $out .= "| APY | " . $this->pct($apy) . " |\n";
        $out .= "\n";

        $out .= "## Rung Forecast\n\n";
        $out .= "| Rung | Name | Value | Fees Today | Daily Income | Weekly Income | Monthly Income | Yearly Income | APY |\n";
        $out .= "|------|------|------:|-----------:|-------------:|--------------:|---------------:|--------------:|----:|\n";

        $totals = ['value' => 0, 'fees' => 0, 'weekly' => 0, 'monthly' => 0, 'yearly' => 0];

        foreach ($rungs as $r) {
            $fees = (float) $r['fees'];
            $value = (float) $r['rung_value'];
            $wk = $fees * 7;
            $mo = $fees * 30;
            $yr = $fees * 365;
            $rungApy = $value > 0 ? ($yr / $value) * 100 : 0;

            $out .= "| {$r['rung']} | {$r['name']} | " . $this->money($value) . " | " . $this->money($fees) . " | " . $this->money($fees) . " | " . $this->money($wk) . " | " . $this->money($mo) . " | " . $this->money($yr) . " | " . $this->pct($rungApy) . " |\n";

            $totals['value'] += $value;
            $totals['fees'] += $fees;
            $totals['weekly'] += $wk;
            $totals['monthly'] += $mo;
            $totals['yearly'] += $yr;
        }

        $totalApy = $totals['value'] > 0 ? ($totals['yearly'] / $totals['value']) * 100 : 0;
        $out .= "| **Total** | | " . $this->money($totals['value']) . " | " . $this->money($totals['fees']) . " | " . $this->money($totals['fees']) . " | " . $this->money($totals['weekly']) . " | " . $this->money($totals['monthly']) . " | " . $this->money($totals['yearly']) . " | " . $this->pct($totalApy) . " |\n";

        return $out;
    }

    private function buildPredictionByDay(array $dailyMetrics, array $config): string
    {
        $days = $dailyMetrics['days'] ?? [];
        if (empty($days)) {
            return "# LP Prediction — By Day\n\nNo data available.\n";
        }

        $out = "# LP Prediction — By Day\n\n";
        $out .= "| Date | Daily Income | Weekly Income | Monthly Income | Yearly Income | APY |\n";
        $out .= "|------|-------------:|--------------:|---------------:|--------------:|----:|\n";

        foreach ($days as $day) {
            $portfolio = $day['portfolio'] ?? [];
            $totalFees = (float) ($portfolio['total_fees'] ?? 0);
            $portfolioValue = (float) ($portfolio['portfolio_value'] ?? 0);
            $weekly = $totalFees * 7;
            $monthly = $totalFees * 30;
            $yearly = $totalFees * 365;
            $apy = $portfolioValue > 0 ? ($yearly / $portfolioValue) * 100 : 0;

            $out .= "| {$day['date']} | " . $this->money($totalFees) . " | " . $this->money($weekly) . " | " . $this->money($monthly) . " | " . $this->money($yearly) . " | " . $this->pct($apy) . " |\n";
        }

        return $out;
    }

    private function buildScalpPerformance(array $digest, array $config): string
    {
        $rungs = $digest['active_rungs'] ?? [];
        $scalpRungs = array_filter($rungs, fn($r) => str_starts_with($r['rung'], 'S'));

        if (empty($scalpRungs)) {
            return "# Scalp Performance Table\n\nNo scalp rungs (S1/S2/S3) found.\n";
        }

        $configRungs = [];
        foreach ($config['rungs'] ?? [] as $cr) {
            $configRungs[$cr['rung']] = $cr;
        }

        $out = "# Scalp Performance Table\n\n";
        $out .= "| Rung | Value | Range Lower | Range Upper | Range Width | Fees | Fee Share | Eligible TRs | Trades | Skipped | Out of Range | Depleted | Utilization | Capital Efficiency | APY |\n";
        $out .= "|------|------:|------------:|------------:|------------:|-----:|----------:|-------------:|-------:|--------:|-------------:|---------:|------------:|-------------------:|----:|\n";

        foreach ($scalpRungs as $r) {
            $code = $r['rung'];
            $cr = $configRungs[$code] ?? [];
            $lower = (float) ($cr['range_lower'] ?? $r['range_lower']);
            $upper = (float) ($cr['range_upper'] ?? $r['range_upper']);
            $width = $upper - $lower;
            $value = (float) $r['rung_value'];
            $fees = (float) $r['fees_to_date'];
            $capEff = $value > 0 ? $fees / $value : 0;

            $out .= "| {$code} | " . $this->money($value) . " | " . number_format($lower, 4) . " | " . number_format($upper, 4) . " | " . number_format($width, 4) . " | " . $this->money($fees) . " | " . $this->pct($r['fee_share_pct']) . " | {$r['eligible_trs']} | {$r['trades']} | {$r['skipped_count']} | {$r['out_of_range_count']} | {$r['depleted_count']} | " . $this->pct($r['utilization_pct']) . " | " . number_format($capEff, 6) . " | " . $this->pct($r['apy_gross'] * 100) . " |\n";
        }

        return $out;
    }

    private function buildStrategySummary(array $digest, array $config): string
    {
        $rungs = $digest['active_rungs'] ?? [];

        $groups = [
            'Core' => ['A', 'B', 'C'],
            'Signal' => ['D'],
            'Scalp' => ['S1', 'S2', 'S3'],
        ];

        $out = "# Core vs Signal vs Scalp Summary\n\n";
        $out .= "| Group | Total Value | Total Fees | Fee Share | Eligible TRs | Trades | Skipped | Out of Range | Depleted | Avg Utilization | Capital Efficiency | APY |\n";
        $out .= "|-------|------------:|-----------:|----------:|-------------:|-------:|--------:|-------------:|---------:|----------------:|-------------------:|----:|\n";

        $rungMap = [];
        foreach ($rungs as $r) {
            $rungMap[$r['rung']] = $r;
        }

        $totalFees = array_sum(array_column($rungs, 'fees_to_date'));

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
            $capEff = $value > 0 ? $fees / $value : 0;
            $apy = $capEff * 365 * 100;

            $out .= "| {$groupName} | " . $this->money($value) . " | " . $this->money($fees) . " | " . $this->pct($feeShare) . " | {$eligible} | {$trades} | {$skipped} | {$oor} | {$depleted} | " . $this->pct($avgUtil) . " | " . number_format($capEff, 6) . " | " . $this->pct($apy) . " |\n";
        }

        return $out;
    }

    private function buildBucketDistribution(array $dailyMetrics, array $config): string
    {
        $days = $dailyMetrics['days'] ?? [];
        if (empty($days)) {
            return "# Bucket Distribution — Latest Day\n\nNo data available.\n";
        }

        $day = end($days);
        $buckets = $day['trade_buckets'] ?? [];

        if (empty($buckets)) {
            return "# Bucket Distribution — Latest Day\n\nNo bucket data for {$day['date']}.\n";
        }

        $activeRungs = array_values(array_filter($config['rungs'] ?? [], fn($r) => !empty($r['active'])));
        $rungCodes = array_column($activeRungs, 'rung');

        $out = "# Bucket Distribution — Latest Day ({$day['date']})\n\n";

        $header = "| Price Low | Price High | TRs | Volume |";
        $divider = "|----------:|-----------:|----:|-------:|";

        foreach ($rungCodes as $code) {
            $header .= " {$code} P | {$code} S | {$code} D | {$code} O |";
            $divider .= "-----:|-----:|-----:|-----:|";
        }

        $out .= $header . "\n";
        $out .= $divider . "\n";

        usort($buckets, fn($a, $b) => $a['price_bucket_low'] <=> $b['price_bucket_low']);

        foreach ($buckets as $bucket) {
            $row = "| " . number_format($bucket['price_bucket_low'], 4) . " | " . number_format($bucket['price_bucket_high'], 4) . " | {$bucket['tr_count']} | " . $this->money($bucket['total_volume_usd']) . " |";

            foreach ($rungCodes as $code) {
                $counts = $bucket['rung_counts'][$code] ?? ['participating' => 0, 'skipped' => 0, 'depleted' => 0, 'out_of_range' => 0];
                $p = $counts['participating'] ?: '';
                $s = $counts['skipped'] ?: '';
                $d = $counts['depleted'] ?: '';
                $o = $counts['out_of_range'] ?: '';
                $row .= " {$p} | {$s} | {$d} | {$o} |";
            }

            $out .= $row . "\n";
        }

        $out .= "\n_Legend: P=Participating, S=Skipped, D=Depleted, O=Out of Range_\n";

        return $out;
    }

    private function buildRungs(array $config): string
    {
        $rungs = $config['rungs'] ?? [];
        $activeRungs = array_filter($rungs, fn($r) => !empty($r['active']));

        if (empty($activeRungs)) {
            return "# Rungs\n\nNo active rungs configured.\n";
        }

        $out = "# Rungs\n\n";
        $out .= "| Rung | Name | Created | Range Lower | Range Upper | Range Width | Value | Allocation | Tags |\n";
        $out .= "|------|------|---------|------------:|------------:|------------:|------:|-----------:|------|\n";

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

            $out .= "| {$r['rung']} | {$r['name']} | {$created} | " . number_format($lower, 4) . " | " . number_format($upper, 4) . " | " . number_format($width, 4) . " | " . $this->money($value) . " | " . $this->pct($allocation) . " | {$tags} |\n";

            $totalValue += $value;
        }

        $out .= "| **Total** | | | | | | " . $this->money($totalValue) . " | | |\n";

        return $out;
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
