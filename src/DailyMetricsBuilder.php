<?php

declare(strict_types=1);

final class DailyMetricsBuilder
{
    public function build(array $compactStore, array $config): array
    {
        $days = [];
        $rungs = array_values(array_filter($config['rungs'], fn(array $r) => !empty($r['active'])));
        $portfolioValue = array_sum(array_map(function(array $r) {
            $rev = get_current_revision($r);
            return $rev !== null ? (float) ($rev['initial_value']['total_usd'] ?? 0) : 0.0;
        }, $rungs));
        $analysisWindowStart = $config['processing']['analysis_window_start'] ?? '2026-04-15T00:00:00Z';
        $analysisWindowStartDate = substr($analysisWindowStart, 0, 10);

        foreach (($compactStore['trades'] ?? []) as $trade) {
            $date = $trade['date'];

            if ($date < $analysisWindowStartDate) {
                continue;
            }

            if (!isset($days[$date])) {
                $days[$date] = [
                    'date' => $date,
                    'meta' => [
                        'trs' => 0,
                        'source_window' => [
                            'start' => $date . 'T00:00:00Z',
                            'end' => $date . 'T23:59:59Z',
                        ],
                    ],
                    'portfolio' => [
                        'portfolio_value' => $portfolioValue,
                        'total_fees' => 0.0,
                        'borrowed_usdt' => (float) ($config['portfolio']['borrowed']['USDT'] ?? 0),
                        'borrowed_usdc' => (float) ($config['portfolio']['borrowed']['USDC'] ?? 0),
                        'borrow_rate_usdt' => (float) ($config['portfolio']['borrow_rates_annual']['USDT'] ?? 0),
                        'borrow_rate_usdc' => (float) ($config['portfolio']['borrow_rates_annual']['USDC'] ?? 0),
                    ],
                    'rung_metrics' => [],
                    'trade_buckets' => [],
                    'totals' => [],
                ];

                foreach ($rungs as $rung) {
                    $rev = get_current_revision($rung);
                    $days[$date]['rung_metrics'][$rung['rung']] = [
                        'rung' => $rung['rung'],
                        'name' => $rung['name'],
                        'eligible_trs' => 0,
                        'trades' => 0,
                        'skipped_count' => 0,
                        'out_of_range_count' => 0,
                        'depleted_count' => 0,
                        'utilization_pct' => 0.0,
                        'skipped_pct' => 0.0,
                        'out_of_range_pct' => 0.0,
                        'depleted_pct' => 0.0,
                        'fees' => 0.0,
                        'fee_share_pct' => 0.0,
                        'filled_volume_usd' => 0.0,
                        'rung_value' => $rev !== null ? (float) ($rev['initial_value']['total_usd'] ?? 0) : 0.0,
                        'capital_efficiency' => 0.0,
                        'apy_gross' => 0.0,
                        'target_allocation_pct' => $rev !== null ? (float) ($rev['target_allocation_pct'] ?? 0) : 0.0,
                    ];
                }
            }

            $days[$date]['meta']['trs']++;

            $bucketSize = (float) ($config['processing']['bucket_size'] ?? 0.0005);
            $bucketKey = null;
            if ($trade['trade_price'] !== null && $bucketSize > 0) {
                $bucketLow = floor(((float) $trade['trade_price']) / $bucketSize) * $bucketSize;
                $bucketHigh = $bucketLow + $bucketSize;
                $bucketKey = number_format($bucketLow, 8, '.', '') . '|' . number_format($bucketHigh, 8, '.', '');

                if (!isset($days[$date]['trade_buckets'][$bucketKey])) {
                    $days[$date]['trade_buckets'][$bucketKey] = [
                        'price_bucket_low' => $bucketLow,
                        'price_bucket_high' => $bucketHigh,
                        'tr_count' => 0,
                        'total_volume_usd' => 0.0,
                        'rung_counts' => [],
                    ];
                }

                $days[$date]['trade_buckets'][$bucketKey]['tr_count']++;
            }

            foreach (($trade['rungs'] ?? []) as $rungCode => $state) {
                if (!isset($days[$date]['rung_metrics'][$rungCode])) {
                    continue;
                }

                if (!($state['eligible'] ?? false)) {
                    continue;
                }

                $days[$date]['rung_metrics'][$rungCode]['eligible_trs']++;

                if ($bucketKey !== null) {
                    if (!isset($days[$date]['trade_buckets'][$bucketKey]['rung_counts'][$rungCode])) {
                        $days[$date]['trade_buckets'][$bucketKey]['rung_counts'][$rungCode] = [
                            'participating' => 0,
                            'skipped' => 0,
                            'depleted' => 0,
                            'out_of_range' => 0,
                        ];
                    }
                }

                switch ($state['status']) {
                    case 'participating':
                        $days[$date]['rung_metrics'][$rungCode]['trades']++;
                        $days[$date]['rung_metrics'][$rungCode]['fees'] += (float) ($state['fees_usd'] ?? 0);
                        $days[$date]['rung_metrics'][$rungCode]['filled_volume_usd'] += (float) ($state['filled_volume_usd'] ?? 0);
                        $days[$date]['portfolio']['total_fees'] += (float) ($state['fees_usd'] ?? 0);
                        if ($bucketKey !== null) {
                            $days[$date]['trade_buckets'][$bucketKey]['rung_counts'][$rungCode]['participating']++;
                            $days[$date]['trade_buckets'][$bucketKey]['total_volume_usd'] += (float) ($state['filled_volume_usd'] ?? 0);
                        }
                        break;
                    case 'skipped':
                        $days[$date]['rung_metrics'][$rungCode]['skipped_count']++;
                        if ($bucketKey !== null) {
                            $days[$date]['trade_buckets'][$bucketKey]['rung_counts'][$rungCode]['skipped']++;
                        }
                        break;
                    case 'out_of_range':
                        $days[$date]['rung_metrics'][$rungCode]['out_of_range_count']++;
                        if ($bucketKey !== null) {
                            $days[$date]['trade_buckets'][$bucketKey]['rung_counts'][$rungCode]['out_of_range']++;
                        }
                        break;
                    case 'depleted':
                        $days[$date]['rung_metrics'][$rungCode]['depleted_count']++;
                        if ($bucketKey !== null) {
                            $days[$date]['trade_buckets'][$bucketKey]['rung_counts'][$rungCode]['depleted']++;
                        }
                        break;
                }
            }
        }

        foreach ($days as &$day) {
            $feesTotal = array_sum(array_map(fn(array $r) => (float) $r['fees'], $day['rung_metrics']));

            foreach ($day['rung_metrics'] as &$rm) {
                $eligible = (int) $rm['eligible_trs'];
                $rm['utilization_pct'] = $eligible > 0 ? ((float) $rm['trades'] / $eligible) * 100 : 0.0;
                $rm['skipped_pct'] = $eligible > 0 ? ((float) $rm['skipped_count'] / $eligible) * 100 : 0.0;
                $rm['out_of_range_pct'] = $eligible > 0 ? ((float) $rm['out_of_range_count'] / $eligible) * 100 : 0.0;
                $rm['depleted_pct'] = $eligible > 0 ? ((float) $rm['depleted_count'] / $eligible) * 100 : 0.0;
                $rm['fee_share_pct'] = $feesTotal > 0 ? ((float) $rm['fees'] / $feesTotal) * 100 : 0.0;
                $rm['capital_efficiency'] = (float) $rm['rung_value'] > 0 ? ((float) $rm['fees'] / (float) $rm['rung_value']) : 0.0;
                $rm['apy_gross'] = $rm['capital_efficiency'] * 365;
            }
            unset($rm);

            $day['rung_metrics'] = array_values($day['rung_metrics']);
            $day['trade_buckets'] = array_values($day['trade_buckets']);
            $day['totals'] = [
                'eligible_trs_sum' => array_sum(array_column($day['rung_metrics'], 'eligible_trs')),
                'trades_sum' => array_sum(array_column($day['rung_metrics'], 'trades')),
                'skipped_count_sum' => array_sum(array_column($day['rung_metrics'], 'skipped_count')),
                'out_of_range_count_sum' => array_sum(array_column($day['rung_metrics'], 'out_of_range_count')),
                'depleted_count_sum' => array_sum(array_column($day['rung_metrics'], 'depleted_count')),
                'rung_value_sum' => (float) $day['portfolio']['portfolio_value'],
                'fees_sum' => $feesTotal,
            ];
        }
        unset($day);

        ksort($days);

        return [
            'version' => 2,
            'pair' => $config['pair']['symbol'],
            'updated_at' => gmdate('c'),
            'analysis_window_start' => $analysisWindowStart,
            'days' => array_values($days),
        ];
    }
}
