<?php

declare(strict_types=1);

require_once __DIR__ . '/Helpers.php';

final class TradeCompiler
{
    public function compile(array $existingCompact, array $existingDebug, array $newFills, array $config): array
    {
        $pair = $config['pair']['symbol'];
        $base = strtoupper($config['pair']['base']);
        $quote = strtoupper($config['pair']['quote']);
        $activeRungs = array_filter($config['rungs'] ?? [], fn(array $r) => !empty($r['active']));
        $analysisWindowStart = $config['processing']['analysis_window_start'] ?? '2026-04-15T00:00:00Z';

        $existingCompactTrades = $existingCompact['trades'] ?? [];
        $existingDebugTrades = $existingDebug['trades'] ?? [];
        $existingFillIds = [];

        foreach ($existingDebugTrades as $trade) {
            foreach (($trade['fills'] ?? []) as $fill) {
                $existingFillIds[$fill['fill_id']] = true;
            }
        }

        $freshFills = [];
        foreach ($newFills as $fill) {
            if (!isset($existingFillIds[$fill['fill_id']])) {
                $fill['matched_rung'] = $this->matchRungTolerant($fill, $activeRungs);
                $freshFills[] = $fill;
            }
        }

        if ($freshFills === []) {
            return [
                'compact' => $existingCompact,
                'debug' => $existingDebug,
            ];
        }

        $compactMap = [];
        foreach ($existingCompactTrades as $trade) {
            $compactMap[$trade['tr_id']] = $trade;
        }

        $debugMap = [];
        foreach ($existingDebugTrades as $trade) {
            $debugMap[$trade['tr_id']] = $trade;
        }

        foreach ($freshFills as $fill) {
            $trId = $fill['timestamp'] . '|' . $pair;

            if (!isset($debugMap[$trId])) {
                $debugMap[$trId] = [
                    'tr_id' => $trId,
                    'timestamp' => $fill['timestamp'],
                    'date' => $fill['date'],
                    'pair' => $pair,
                    'fills' => [],
                ];
            }

            $debugMap[$trId]['fills'][] = $fill;
        }

        foreach ($debugMap as $trId => &$debugTrade) {
            usort($debugTrade['fills'], fn(array $a, array $b) => strcmp($a['fill_id'], $b['fill_id']));

            [$price, $priceSource] = $this->inferTradePrice($debugTrade['fills'], $activeRungs, $base, $quote);

            $rungClassification = $this->classifyRungsForTrade($debugTrade, $price, $activeRungs);

            $totals = $this->computeTradeTotals($debugTrade['fills'], $rungClassification);

            $compactMap[$trId] = [
                'tr_id' => $trId,
                'timestamp' => $debugTrade['timestamp'],
                'date' => $debugTrade['date'],
                'pair' => $pair,
                'trade_price' => $price,
                'price_source' => $priceSource,
                'fills_count' => count($debugTrade['fills']),
                'rungs' => $rungClassification,
                'totals' => $totals,
            ];
        }
        unset($debugTrade);

        $compactTrades = array_values(array_filter(
            $compactMap,
            fn(array $t) => strcmp($t['timestamp'], $analysisWindowStart) >= 0
        ));
        usort($compactTrades, fn(array $a, array $b) => strcmp($a['timestamp'], $b['timestamp']));

        $debugTrades = array_values($debugMap);
        usort($debugTrades, fn(array $a, array $b) => strcmp($a['timestamp'], $b['timestamp']));

        return [
            'compact' => [
                'version' => 2,
                'pair' => $pair,
                'updated_at' => gmdate('c'),
                'analysis_window_start' => $analysisWindowStart,
                'source_files' => $existingCompact['source_files'] ?? [],
                'trades' => $compactTrades,
            ],
            'debug' => [
                'version' => 2,
                'pair' => $pair,
                'updated_at' => gmdate('c'),
                'trades' => $debugTrades,
            ],
        ];
    }

    private function matchRungTolerant(array $fill, array $rungs): ?string
    {
        $fillLower = (float) $fill['range_lower'];
        $fillUpper = (float) $fill['range_upper'];
        $timestamp = $fill['timestamp'] ?? '';

        foreach ($rungs as $rung) {
            $rev = get_rung_revision_at($rung, $timestamp);
            if ($rev === null) {
                continue;
            }

            if (ranges_match_tolerant($fillLower, $fillUpper, (float) $rev['range_lower'], (float) $rev['range_upper'])) {
                return $rung['rung'];
            }
        }

        return null;
    }

    private function inferTradePrice(array $fills, array $rungs, string $base, string $quote): array
    {
        $rungsByCode = [];
        foreach ($rungs as $rung) {
            $rungsByCode[$rung['rung']] = $rung;
        }

        $validFills = [];
        $candidates = [];

        foreach ($fills as $fill) {
            $price = price_quote_per_base(
                $base,
                $quote,
                $fill['filled_asset'],
                (float) $fill['filled_amount'],
                $fill['received_asset'],
                (float) $fill['received_amount']
            );

            if ($price === null) {
                continue;
            }

            $validFills[] = ['fill' => $fill, 'price' => $price];
            $rungCode = $fill['matched_rung'];
            $timestamp = $fill['timestamp'] ?? '';

            if ($rungCode !== null && isset($rungsByCode[$rungCode])) {
                $rung = $rungsByCode[$rungCode];
                $rev = get_rung_revision_at($rung, $timestamp);
                if ($rev !== null) {
                    $width = (float) $rev['range_upper'] - (float) $rev['range_lower'];
                    $candidates[] = [
                        'rung' => $rungCode,
                        'width' => $width,
                        'price' => $price,
                        'fill' => $fill,
                    ];
                }
            }
        }

        if ($candidates !== []) {
            usort($candidates, fn(array $a, array $b) => $b['width'] <=> $a['width']);
            return [
                $candidates[0]['price'],
                [
                    'method' => 'widest_matching_rung_fill',
                    'rung' => $candidates[0]['rung'],
                ],
            ];
        }

        if (count($validFills) > 1) {
            $vwapPrice = vwap(array_column($validFills, 'fill'), $base, $quote);
            if ($vwapPrice !== null) {
                return [
                    $vwapPrice,
                    [
                        'method' => 'vwap_fill_price',
                        'rung' => null,
                    ],
                ];
            }
        }

        if (count($validFills) === 1) {
            return [
                $validFills[0]['price'],
                [
                    'method' => 'single_fill_price',
                    'rung' => null,
                ],
            ];
        }

        return [
            null,
            [
                'method' => 'unresolved',
                'rung' => null,
            ],
        ];
    }

    private function classifyRungsForTrade(array $debugTrade, ?float $tradePrice, array $rungs): array
    {
        $byRung = [];
        $fillsByRung = [];
        $timestamp = $debugTrade['timestamp'];

        foreach ($debugTrade['fills'] as $fill) {
            if (!empty($fill['matched_rung'])) {
                $fillsByRung[$fill['matched_rung']][] = $fill;
            }
        }

        $anyRungParticipated = !empty($fillsByRung);

        foreach ($rungs as $rung) {
            $code = $rung['rung'];
            $rev = get_rung_revision_at($rung, $timestamp);
            $createdAt = get_rung_created_at($rung);
            $eligible = !empty($rung['active']) && $rev !== null && $createdAt !== null && strcmp($timestamp, $createdAt) >= 0;

            if (!$eligible) {
                $byRung[$code] = [
                    'eligible' => false,
                    'status' => 'not_started',
                    'fees_usd' => 0.0,
                    'filled_volume_usd' => 0.0,
                ];
                continue;
            }

            if (isset($fillsByRung[$code])) {
                $feesUsd = 0.0;
                $volumeUsd = 0.0;

                foreach ($fillsByRung[$code] as $fill) {
                    $feesUsd += (float) ($fill['fee_amount'] ?? 0);
                    $volumeUsd += (float) ($fill['filled_value_usd'] ?? 0);
                }

                $byRung[$code] = [
                    'eligible' => true,
                    'status' => 'participating',
                    'fees_usd' => $feesUsd,
                    'filled_volume_usd' => $volumeUsd,
                ];
                continue;
            }

            $inRange = $tradePrice !== null
                && $tradePrice >= (float) $rev['range_lower']
                && $tradePrice <= (float) $rev['range_upper'];

            if (!$inRange) {
                $byRung[$code] = [
                    'eligible' => true,
                    'status' => 'out_of_range',
                    'fees_usd' => 0.0,
                    'filled_volume_usd' => 0.0,
                ];
                continue;
            }

            $byRung[$code] = [
                'eligible' => true,
                'status' => $anyRungParticipated ? 'skipped' : 'depleted',
                'fees_usd' => 0.0,
                'filled_volume_usd' => 0.0,
            ];
        }

        return $byRung;
    }

    private function computeTradeTotals(array $fills, array $rungClassification): array
    {
        $totalFees = 0.0;
        $totalVolume = 0.0;
        $participatingRungs = [];
        $skippedRungs = [];
        $depletedRungs = [];
        $outOfRangeRungs = [];

        foreach ($rungClassification as $code => $state) {
            $totalFees += (float) ($state['fees_usd'] ?? 0);
            $totalVolume += (float) ($state['filled_volume_usd'] ?? 0);

            switch ($state['status'] ?? '') {
                case 'participating':
                    $participatingRungs[] = $code;
                    break;
                case 'skipped':
                    $skippedRungs[] = $code;
                    break;
                case 'depleted':
                    $depletedRungs[] = $code;
                    break;
                case 'out_of_range':
                    $outOfRangeRungs[] = $code;
                    break;
            }
        }

        return [
            'fees_usd' => $totalFees,
            'filled_volume_usd' => $totalVolume,
            'fills_count' => count($fills),
            'participating_rungs' => $participatingRungs,
            'skipped_rungs' => $skippedRungs,
            'depleted_rungs' => $depletedRungs,
            'out_of_range_rungs' => $outOfRangeRungs,
        ];
    }
}
