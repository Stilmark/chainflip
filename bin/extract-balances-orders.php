<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Helpers.php';
require_once __DIR__ . '/../src/BalancesOrdersExtractor.php';

$projectRoot = realpath(__DIR__ . '/..');
if ($projectRoot === false) {
    throw new RuntimeException('Failed to resolve project root');
}

$options = getopt('', ['input::', 'output::', 'help']);
if (isset($options['help'])) {
    echo "Usage: php bin/extract-balances-orders.php [--input=./data/balances-orders.json] [--output=./data/balances_orders_summary.json]\n";
    exit(0);
}

$inputArg = (string) ($options['input'] ?? './data/balances-orders.json');
$outputArg = (string) ($options['output'] ?? './data/balances_orders_summary.json');

$inputPath = resolve_project_path($projectRoot, $inputArg);
$outputPath = resolve_project_path($projectRoot, $outputArg);

$payload = load_json_file($inputPath);
if ($payload === []) {
    throw new RuntimeException("Input file not found or empty: {$inputPath}");
}

$extractor = new BalancesOrdersExtractor();
$summary = $extractor->extract($payload);

save_json_file($outputPath, $summary);

echo "Balances/orders summary generated: {$outputPath}\n";
echo "LP count: " . ((int) ($summary['totals']['lp_count'] ?? 0)) . "\n";
echo "Range orders: " . ((int) ($summary['totals']['range_orders_count_total'] ?? 0)) . "\n";
