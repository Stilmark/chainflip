<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Helpers.php';
require_once __DIR__ . '/../src/BalancesOrdersExtractor.php';

function fetch_balances_orders_payload(string $curlCommandPath): array
{
    $command = trim((string) file_get_contents($curlCommandPath));
    if ($command === '') {
        throw new RuntimeException("Curl command file not found or empty: {$curlCommandPath}");
    }

    $process = proc_open(
        ['/bin/sh', '-c', $command . ' --silent --show-error'],
        [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes
    );

    if (!is_resource($process)) {
        throw new RuntimeException('Failed to start curl process');
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        $message = trim($stderr) !== '' ? trim($stderr) : 'curl command failed';
        throw new RuntimeException($message);
    }

    $payload = json_decode($stdout, true);
    if (!is_array($payload)) {
        throw new RuntimeException('Curl command returned invalid JSON');
    }

    return $payload;
}

$projectRoot = realpath(__DIR__ . '/..');
if ($projectRoot === false) {
    throw new RuntimeException('Failed to resolve project root');
}

$options = getopt('', ['input::', 'output::', 'curl-command::', 'help']);
if (isset($options['help'])) {
    echo "Usage: php bin/extract-balances-orders.php [--input=./data/balances-orders.json] [--output=./data/balances_orders_summary.json] [--curl-command=./txt/curl-balances-orders.txt]\n";
    exit(0);
}

$inputArg = (string) ($options['input'] ?? './data/balances-orders.json');
$outputArg = (string) ($options['output'] ?? './data/balances_orders_summary.json');
$curlCommandArg = (string) ($options['curl-command'] ?? './txt/curl-balances-orders.txt');

$inputPath = resolve_project_path($projectRoot, $inputArg);
$outputPath = resolve_project_path($projectRoot, $outputArg);
$curlCommandPath = resolve_project_path($projectRoot, $curlCommandArg);

$payload = fetch_balances_orders_payload($curlCommandPath);
save_json_file($inputPath, $payload);

$extractor = new BalancesOrdersExtractor();
$summary = $extractor->extract($payload);

save_json_file($outputPath, $summary);

echo "Balances/orders summary generated: {$outputPath}\n";
echo "LP count: " . ((int) ($summary['totals']['lp_count'] ?? 0)) . "\n";
echo "Range orders: " . ((int) ($summary['totals']['range_orders_count_total'] ?? 0)) . "\n";
