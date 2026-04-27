<?php

declare(strict_types=1);

final class DigestBuilder
{
    private const MAX_RECENT_DAILY = 7;
    private const MAX_TR_EXAMPLES = 50;

    public function build(array $dailyMetrics, array $compactStore, array $config): array
    {
        $days = $dailyMetrics['days'] ?? [];
        $analysisWindowStart = $config['processing']['analysis_window_start'] ?? '2026-04-15T00:00:00Z';
        $analysisWindowStartDate = substr($analysisWindowStart, 0, 10);

        $inScopeDays = array_filter($days, fn(array $d) => $d['date'] >= $analysisWindowStartDate);
        $inScopeDays = array_values($inScopeDays);

        $latestDay = $inScopeDays !== [] ? end($inScopeDays) : null;
        $activeRungs = array_values(array_filter($config['rungs'], fn(array $r) => !empty($r['active'])));
        $asOfTimestamp = $latestDay['meta']['source_window']['end']
            ?? (($latestDay['date'] ?? null) !== null ? $latestDay['date'] . 'T23:59:59Z' : gmdate('Y-m-d\\TH:i:s\\Z'));

        $feesToDateByRung = [];
        $eligibleToDateByRung = [];
        $tradesToDateByRung = [];
        $oorToDateByRung = [];
        $depletedToDateByRung = [];
        $volumeToDateByRung = [];

        foreach ($inScopeDays as $day) {
            foreach (($day['rung_metrics'] ?? []) as $rm) {
                $code = $rm['rung'];
                $feesToDateByRung[$code] = ($feesToDateByRung[$code] ?? 0) + (float) $rm['fees'];
                $eligibleToDateByRung[$code] = ($eligibleToDateByRung[$code] ?? 0) + (int) $rm['eligible_trs'];
                $tradesToDateByRung[$code] = ($tradesToDateByRung[$code] ?? 0) + (int) $rm['trades'];
                $skippedToDateByRung[$code] = ($skippedToDateByRung[$code] ?? 0) + (int) ($rm['skipped_count'] ?? 0);
                $oorToDateByRung[$code] = ($oorToDateByRung[$code] ?? 0) + (int) $rm['out_of_range_count'];
                $depletedToDateByRung[$code] = ($depletedToDateByRung[$code] ?? 0) + (int) $rm['depleted_count'];
                $volumeToDateByRung[$code] = ($volumeToDateByRung[$code] ?? 0) + (float) $rm['filled_volume_usd'];
            }
        }

        $totalFeesToDate = array_sum($feesToDateByRung);
        $rungPayload = [];

        foreach ($activeRungs as $rung) {
            $code = $rung['rung'];
            $rev = get_rung_revision_at($rung, $asOfTimestamp) ?? get_current_revision($rung);
            $createdAt = get_rung_created_at($rung);
            $rungValue = $rev !== null ? (float) ($rev['initial_value']['total_usd'] ?? 0) : 0.0;
            $fees = (float) ($feesToDateByRung[$code] ?? 0);
            $eligible = (int) ($eligibleToDateByRung[$code] ?? 0);
            $trades = (int) ($tradesToDateByRung[$code] ?? 0);
            $skipped = (int) ($skippedToDateByRung[$code] ?? 0);
            $oor = (int) ($oorToDateByRung[$code] ?? 0);
            $depleted = (int) ($depletedToDateByRung[$code] ?? 0);

            $capitalDays = 0.0;
            $activeDays = 0;
            foreach ($inScopeDays as $day) {
                $dayEnd = $day['date'] . 'T23:59:59Z';
                $dayRev = get_rung_revision_at($rung, $dayEnd);
                if ($dayRev === null) {
                    continue;
                }

                $capitalDays += (float) ($dayRev['initial_value']['total_usd'] ?? 0.0);
                $activeDays++;
            }

            $avgActiveCapital = $activeDays > 0 ? $capitalDays / $activeDays : $rungValue;
            $apr = annualized_return_from_capital_days($fees, $capitalDays);
            $yearlyIncome = annualized_simple_income($fees, (float) max(1, $activeDays));
            $targetAlloc = $rev !== null ? (float) ($rev['target_allocation_pct'] ?? 0) : 0.0;

            $rungPayload[] = [
                'rung' => $code,
                'name' => $rung['name'],
                'created_at' => $createdAt,
                'range_lower' => $rev !== null ? (float) $rev['range_lower'] : 0.0,
                'range_upper' => $rev !== null ? (float) $rev['range_upper'] : 0.0,
                'target_allocation_pct' => $targetAlloc,
                'rung_value' => $rungValue,
                'fees_to_date' => $fees,
                'fee_share_pct' => $totalFeesToDate > 0 ? ($fees / $totalFeesToDate) * 100 : 0.0,
                'efficiency_ratio' => ($targetAlloc > 0 && $totalFeesToDate > 0)
                    ? (($fees / $totalFeesToDate) * 100) / $targetAlloc
                    : 0.0,
                'eligible_trs' => $eligible,
                'trades' => $trades,
                'skipped_count' => $skipped,
                'out_of_range_count' => $oor,
                'depleted_count' => $depleted,
                'utilization_pct' => $eligible > 0 ? ($trades / $eligible) * 100 : 0.0,
                'skipped_pct' => $eligible > 0 ? ($skipped / $eligible) * 100 : 0.0,
                'out_of_range_pct' => $eligible > 0 ? ($oor / $eligible) * 100 : 0.0,
                'depleted_pct' => $eligible > 0 ? ($depleted / $eligible) * 100 : 0.0,
                'filled_volume_usd' => (float) ($volumeToDateByRung[$code] ?? 0),
                'active_days' => $activeDays,
                'average_active_capital' => $avgActiveCapital,
                'capital_days' => $capitalDays,
                'apr_gross' => $apr,
                'predicted_daily_income' => $yearlyIncome / 365,
                'predicted_monthly_income' => $yearlyIncome / 12,
                'predicted_yearly_income' => $yearlyIncome,
                'stability_label' => $eligible < 50 ? 'early' : 'normal',
            ];
        }

        $recentDaily = [];
        $recentDays = array_slice($inScopeDays, -self::MAX_RECENT_DAILY);
        foreach ($recentDays as $day) {
            $recentDaily[] = [
                'date' => $day['date'],
                'total_fees' => (float) ($day['portfolio']['total_fees'] ?? 0),
                'trs' => (int) ($day['meta']['trs'] ?? 0),
            ];
        }

        $inScopeTrades = array_filter(
            $compactStore['trades'] ?? [],
            fn(array $t) => $t['date'] >= $analysisWindowStartDate
        );
        $recentTrades = array_slice(array_values($inScopeTrades), -self::MAX_TR_EXAMPLES);

        $recentTrExamples = [];
        foreach ($recentTrades as $trade) {
            $recentTrExamples[] = [
                'timestamp' => $trade['timestamp'],
                'trade_price' => $trade['trade_price'],
                'price_source' => $trade['price_source']['method'] ?? null,
                'volume_usd' => $trade['totals']['filled_volume_usd'] ?? 0,
                'fees_usd' => $trade['totals']['fees_usd'] ?? 0,
                'participating_rungs' => $trade['totals']['participating_rungs'] ?? [],
                'skipped_rungs' => $trade['totals']['skipped_rungs'] ?? [],
                'depleted_rungs' => $trade['totals']['depleted_rungs'] ?? [],
                'out_of_range_rungs' => $trade['totals']['out_of_range_rungs'] ?? [],
            ];
        }

        return [
            'version' => 2,
            'generated_at' => gmdate('c'),
            'meta' => [
                'as_of_date' => $latestDay['date'] ?? null,
                'pair' => $config['pair']['symbol'],
                'analysis_window_start' => $analysisWindowStart,
                'source_window' => [
                    'start' => $inScopeDays !== [] ? $inScopeDays[0]['meta']['source_window']['start'] : null,
                    'end' => $latestDay['meta']['source_window']['end'] ?? null,
                ],
                'active_rung_count' => count($activeRungs),
                'total_trs_in_scope' => count($inScopeTrades),
            ],
            'portfolio' => [
                'portfolio_value' => array_sum(array_map(
                    fn(array $r) => get_rung_value_at($r, $asOfTimestamp),
                    $activeRungs
                )),
                'borrowed' => $config['portfolio']['borrowed'],
                'borrow_rates_annual' => $config['portfolio']['borrow_rates_annual'],
                'total_fees_to_date' => $totalFeesToDate,
            ],
            'active_rungs' => $rungPayload,
            'recent_daily' => $recentDaily,
            'bucket_summary' => $latestDay['trade_buckets'] ?? [],
            'alerts' => [],
            'recent_tr_examples' => $recentTrExamples,
        ];
    }
}
