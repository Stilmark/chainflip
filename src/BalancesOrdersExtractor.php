<?php

declare(strict_types=1);

final class BalancesOrdersExtractor
{
    public function extract(array $payload): array
    {
        $lps = $payload['data']['lps']['nodes'] ?? [];
        $poolNodes = $payload['data']['pools']['nodes'] ?? [];

        $poolIndex = $this->indexPools($poolNodes);
        $lpSummaries = [];

        foreach ($lps as $lp) {
            $lpSummaries[] = $this->extractLp($lp, $poolIndex);
        }

        $totals = [
            'lp_count' => count($lpSummaries),
            'earned_fees_usd_total' => array_sum(array_map(fn(array $lp) => (float) ($lp['earned_fees_usd'] ?? 0.0), $lpSummaries)),
            'borrow_amount_usd_total' => array_sum(array_map(fn(array $lp) => (float) ($lp['borrow_amount_usd_total'] ?? 0.0), $lpSummaries)),
            'orders_value_usd_total' => array_sum(array_map(fn(array $lp) => (float) ($lp['balances']['orders_value_usd_total'] ?? 0.0), $lpSummaries)),
            'total_balance_value_usd_total' => array_sum(array_map(fn(array $lp) => (float) ($lp['balances']['total_balance_value_usd_total'] ?? 0.0), $lpSummaries)),
            'usdt_total_balance_usd_total' => array_sum(array_map(fn(array $lp) => (float) ($lp['balances']['key_assets']['usdt']['total_balance_value_usd'] ?? 0.0), $lpSummaries)),
            'usdc_total_balance_usd_total' => array_sum(array_map(fn(array $lp) => (float) ($lp['balances']['key_assets']['usdc']['total_balance_value_usd'] ?? 0.0), $lpSummaries)),
            'range_orders_count_total' => array_sum(array_map(fn(array $lp) => (int) ($lp['open_orders']['range_orders_count'] ?? 0), $lpSummaries)),
            'range_order_liquidity_total' => array_sum(array_map(fn(array $lp) => (float) ($lp['open_orders']['range_order_liquidity_total'] ?? 0.0), $lpSummaries)),
        ];

        return [
            'version' => 1,
            'generated_at' => gmdate('c'),
            'source' => [
                'query' => 'GetFilteredLpAccountsWithBalances',
                'contains' => ['lps', 'pools', 'openOrders', 'balances', 'lpLoanAccountsByLpIdSs58'],
            ],
            'pool_prices' => [
                'count' => count($poolNodes),
                'pairs' => array_values(array_map(function(array $pool): array {
                    return [
                        'base_asset' => (string) ($pool['baseAsset'] ?? ''),
                        'quote_asset' => (string) ($pool['quoteAsset'] ?? ''),
                        'range_order_price' => to_float($pool['rangeOrderPrice'] ?? 0),
                    ];
                }, $poolNodes)),
            ],
            'lps' => $lpSummaries,
            'totals' => $totals,
        ];
    }

    private function extractLp(array $lp, array $poolIndex): array
    {
        $loanNodes = $lp['lpLoanAccountsByLpIdSs58']['nodes'] ?? [];
        $borrowTotal = array_sum(array_map(fn(array $loan) => to_float($loan['totalBorrowAmountUsd'] ?? 0), $loanNodes));

        $balanceNodes = $lp['balances']['nodes'] ?? [];
        $balances = $this->extractBalances($balanceNodes);

        $openOrders = $this->extractOpenOrders($lp['openOrders'] ?? [], $poolIndex);

        return [
            'id_ss58' => (string) ($lp['idSs58'] ?? ''),
            'alias' => $lp['alias'] ?? null,
            'flip_balance' => (string) ($lp['flipBalance'] ?? '0'),
            'earned_fees_usd' => to_float($lp['earnedFeesValueUsd'] ?? 0),
            'borrow_amount_usd_total' => $borrowTotal,
            'balances' => $balances,
            'open_orders' => $openOrders,
            'strategy_balance_usd' => [
                'quote' => to_float($lp['strategy']['aggregates']['sum']['quoteAssetBalanceUsd'] ?? 0),
                'base' => to_float($lp['strategy']['aggregates']['sum']['baseAssetBalanceUsd'] ?? 0),
            ],
            'refund_addresses' => [
                'ethereum' => $lp['ethereumRefundAddress'] ?? null,
                'bitcoin' => $lp['bitcoinRefundAddress'] ?? null,
                'solana' => $lp['solanaRefundAddress'] ?? null,
                'arbitrum' => $lp['arbitrumRefundAddress'] ?? null,
                'assethub' => $lp['assethubRefundAddress'] ?? null,
            ],
            'health' => [
                'borrow_to_total_balance_ratio' => ($balances['total_balance_value_usd_total'] ?? 0.0) > 0
                    ? $borrowTotal / (float) $balances['total_balance_value_usd_total']
                    : null,
                'orders_to_total_balance_ratio' => ($balances['total_balance_value_usd_total'] ?? 0.0) > 0
                    ? (float) ($balances['orders_value_usd_total'] ?? 0.0) / (float) $balances['total_balance_value_usd_total']
                    : null,
            ],
        ];
    }

    private function extractBalances(array $balanceNodes): array
    {
        $ordersValueTotal = 0.0;
        $totalBalanceValueTotal = 0.0;
        $byAsset = [];

        foreach ($balanceNodes as $node) {
            $asset = strtolower((string) ($node['asset'] ?? ''));
            if ($asset === '') {
                continue;
            }

            $ordersValueUsd = to_float($node['ordersValueUsd'] ?? 0);
            $totalBalanceValueUsd = to_float($node['totalBalanceValueUsd'] ?? 0);
            $amount = to_float($node['totalBalance'] ?? $node['amount'] ?? 0);

            $ordersValueTotal += $ordersValueUsd;
            $totalBalanceValueTotal += $totalBalanceValueUsd;

            $byAsset[$asset] = [
                'chain' => (string) ($node['chain'] ?? ''),
                'asset' => (string) ($node['asset'] ?? ''),
                'total_balance' => $amount,
                'total_balance_value_usd' => $totalBalanceValueUsd,
                'orders_value_usd' => $ordersValueUsd,
                'wallet_value_usd_estimate' => max(0.0, $totalBalanceValueUsd - $ordersValueUsd),
            ];
        }

        return [
            'total_balance_value_usd_total' => $totalBalanceValueTotal,
            'orders_value_usd_total' => $ordersValueTotal,
            'wallet_value_usd_estimate_total' => max(0.0, $totalBalanceValueTotal - $ordersValueTotal),
            'key_assets' => [
                'usdt' => $byAsset['usdt'] ?? null,
                'usdc' => $byAsset['usdc'] ?? null,
            ],
            'assets' => array_values($byAsset),
        ];
    }

    private function extractOpenOrders(array $openOrders, array $poolIndex): array
    {
        $pairs = [];
        $rangeOrdersCount = 0;
        $rangeOrderLiquidityTotal = 0.0;
        $rangeFeesBaseTotal = 0.0;
        $rangeFeesQuoteTotal = 0.0;
        $usdtUsdcPoolPrice = null;

        foreach ($openOrders as $entry) {
            $base = (string) ($entry['baseAsset'] ?? '');
            $quote = (string) ($entry['quoteAsset'] ?? '');
            $pairKey = strtolower($base . '/' . $quote);
            $poolRangeOrderPrice = to_float($entry['poolRangeOrderPrice'] ?? 0);
            $poolPrice = $poolIndex[$pairKey]['range_order_price'] ?? null;
            $rangeOrders = $entry['orders']['range_orders'] ?? [];

            if (strtolower($base) === 'usdt' && strtolower($quote) === 'usdc') {
                $usdtUsdcPoolPrice = $poolRangeOrderPrice;
            }

            $ordersForPair = [];
            foreach ($rangeOrders as $order) {
                $start = (int) ($order['range']['start'] ?? 0);
                $end = (int) ($order['range']['end'] ?? 0);
                $liquidity = to_float($order['liquidity'] ?? 0);
                $feesBase = to_float($order['fees_earned']['base'] ?? 0);
                $feesQuote = to_float($order['fees_earned']['quote'] ?? 0);

                $rangeOrdersCount++;
                $rangeOrderLiquidityTotal += $liquidity;
                $rangeFeesBaseTotal += $feesBase;
                $rangeFeesQuoteTotal += $feesQuote;

                $ordersForPair[] = [
                    'id' => (string) ($order['id'] ?? ''),
                    'range_start_tick' => $start,
                    'range_end_tick' => $end,
                    'range_width_ticks' => $end - $start,
                    'liquidity' => $liquidity,
                    'fees_earned' => [
                        'base' => $feesBase,
                        'quote' => $feesQuote,
                    ],
                ];
            }

            $pairs[] = [
                'base_asset' => $base,
                'quote_asset' => $quote,
                'pool_range_order_price' => $poolRangeOrderPrice,
                'pool_range_order_price_from_pools_query' => $poolPrice,
                'price_delta_vs_pools_query' => $poolPrice !== null ? $poolRangeOrderPrice - (float) $poolPrice : null,
                'has_active_range_orders' => count($rangeOrders) > 0,
                'range_orders_count' => count($rangeOrders),
                'range_orders' => $ordersForPair,
            ];
        }

        return [
            'pairs_count' => count($pairs),
            'pairs_with_active_range_orders_count' => count(array_filter($pairs, fn(array $p) => $p['has_active_range_orders'])),
            'current_usdt_usdc_pool_ratio' => $usdtUsdcPoolPrice,
            'range_orders_count' => $rangeOrdersCount,
            'range_order_liquidity_total' => $rangeOrderLiquidityTotal,
            'range_fees_earned_total' => [
                'base' => $rangeFeesBaseTotal,
                'quote' => $rangeFeesQuoteTotal,
            ],
            'pairs' => $pairs,
        ];
    }

    private function indexPools(array $poolNodes): array
    {
        $index = [];

        foreach ($poolNodes as $pool) {
            $base = strtolower((string) ($pool['baseAsset'] ?? ''));
            $quote = strtolower((string) ($pool['quoteAsset'] ?? ''));
            if ($base === '' || $quote === '') {
                continue;
            }

            $index[$base . '/' . $quote] = [
                'base_asset' => (string) ($pool['baseAsset'] ?? ''),
                'quote_asset' => (string) ($pool['quoteAsset'] ?? ''),
                'range_order_price' => to_float($pool['rangeOrderPrice'] ?? 0),
            ];
        }

        return $index;
    }
}
