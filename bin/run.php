<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Helpers.php';
require_once __DIR__ . '/../src/CsvFillParser.php';
require_once __DIR__ . '/../src/TradeCompiler.php';
require_once __DIR__ . '/../src/DailyMetricsBuilder.php';
require_once __DIR__ . '/../src/DigestBuilder.php';
require_once __DIR__ . '/../src/TableBuilder.php';

$projectRoot = realpath(__DIR__ . '/..');
if ($projectRoot === false) {
    throw new RuntimeException('Failed to resolve project root');
}

$options = getopt('', ['config::', 'rebuild', 'help']);
if (isset($options['help'])) {
    echo "Usage: php bin/run.php [--config=/path/to/config.json] [--rebuild]\n";
    echo "  --rebuild   Rebuild trades_compact.json, trades_debug.json, daily_metrics.json, and digest.json from input and archive CSVs\n";
    exit(0);
}

$configArg = $options['config'] ?? ($projectRoot . '/config.json');
$configPath = resolve_project_path($projectRoot, (string) $configArg);
$config = load_json_file($configPath);
if ($config === []) {
    throw new RuntimeException("Config file not found or empty: {$configPath}");
}

$paths = $config['paths'] ?? [];
$processing = $config['processing'] ?? [];

$inputDir = resolve_project_path($projectRoot, (string) ($paths['input_dir'] ?? './input'));
$archiveDir = resolve_project_path($projectRoot, (string) ($paths['archive_dir'] ?? './archive'));
$tradesCompactFile = resolve_project_path($projectRoot, (string) ($paths['trades_compact_file'] ?? './data/trades_compact.json'));
$tradesDebugFile = resolve_project_path($projectRoot, (string) ($paths['trades_debug_file'] ?? './data/trades_debug.json'));
$dailyMetricsFile = resolve_project_path($projectRoot, (string) ($paths['daily_metrics_file'] ?? './data/daily_metrics.json'));
$digestFile = resolve_project_path($projectRoot, (string) ($paths['digest_file'] ?? './data/digest.json'));
$lockFile = resolve_project_path($projectRoot, (string) ($paths['run_lock_file'] ?? './data/run.lock'));
$fileGlob = (string) ($processing['file_glob'] ?? '*.csv');
$archiveOnSuccess = (bool) ($processing['archive_on_success'] ?? true);
$includeArchiveOnRebuild = (bool) ($processing['include_archive_on_rebuild'] ?? true);
$emitDebugTrades = (bool) ($processing['emit_debug_trades'] ?? true);
$allowedAssets = array_map('strtoupper', $processing['allowed_assets'] ?? ['USDT', 'USDC']);
$analysisWindowStart = $processing['analysis_window_start'] ?? '2026-04-15T00:00:00Z';
$analysisWindowStartDate = substr($analysisWindowStart, 0, 10);
$rebuild = isset($options['rebuild']);

ensure_dir(dirname($tradesCompactFile));
ensure_dir($inputDir);
ensure_dir($archiveDir);
ensure_dir(dirname($lockFile));

$lockHandle = fopen($lockFile, 'c');
if ($lockHandle === false) {
    throw new RuntimeException("Failed to open lock file: {$lockFile}");
}

if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Another run is already in progress.\n");
    exit(1);
}

$parser = new CsvFillParser();
$compiler = new TradeCompiler();
$metricsBuilder = new DailyMetricsBuilder();
$digestBuilder = new DigestBuilder();

$pair = $config['pair']['symbol'];

$emptyCompactStore = [
    'version' => 2,
    'pair' => $pair,
    'updated_at' => null,
    'source_files' => [],
    'trades' => [],
];

$emptyDebugStore = [
    'version' => 2,
    'pair' => $pair,
    'updated_at' => null,
    'trades' => [],
];

$compactStore = $rebuild ? $emptyCompactStore : load_json_file($tradesCompactFile, $emptyCompactStore);
$debugStore = $rebuild ? $emptyDebugStore : load_json_file($tradesDebugFile, $emptyDebugStore);

$inputFiles = glob(rtrim($inputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $fileGlob) ?: [];
sort($inputFiles);

$archiveFiles = [];
if ($rebuild && $includeArchiveOnRebuild) {
    $archiveFiles = glob(rtrim($archiveDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $fileGlob) ?: [];
    sort($archiveFiles);
}

$filesToParse = array_values(array_unique(array_merge($archiveFiles, $inputFiles)));
$allNewFills = [];
$processedFiles = [];

foreach ($filesToParse as $file) {
    if (!is_file($file)) {
        continue;
    }

    $rows = $parser->parseFile($file, $config);
    $allNewFills = array_merge($allNewFills, $rows);

    $processedFiles[] = [
        'path' => $file,
        'file_name' => basename($file),
        'sha1' => sha1_file_safe($file),
        'from_archive' => str_starts_with($file, $archiveDir . DIRECTORY_SEPARATOR),
    ];
}

$compiled = $compiler->compile($compactStore, $debugStore, $allNewFills, $config);
$compactStore = $compiled['compact'];
$debugStore = $compiled['debug'];

if ($rebuild) {
    $compactStore['source_files'] = [];
}

$seenSourceKeys = [];
foreach (($compactStore['source_files'] ?? []) as $sf) {
    $seenSourceKeys[$sf['file_name'] . '|' . $sf['sha1']] = true;
}

foreach ($processedFiles as $pf) {
    $dest = rtrim($archiveDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $pf['file_name'];
    $sourceKey = $pf['file_name'] . '|' . $pf['sha1'];

    if (!isset($seenSourceKeys[$sourceKey])) {
        $compactStore['source_files'][] = [
            'file_name' => $pf['file_name'],
            'sha1' => $pf['sha1'],
            'processed_at' => gmdate('c'),
            'archived_to' => $dest,
        ];
        $seenSourceKeys[$sourceKey] = true;
    }

    if ($archiveOnSuccess && !$rebuild && !$pf['from_archive']) {
        rename($pf['path'], $dest);
    }
}

$dailyMetrics = $metricsBuilder->build($compactStore, $config);
$digest = $digestBuilder->build($dailyMetrics, $compactStore, $config);

$activeRungs = array_values(array_filter($config['rungs'] ?? [], fn(array $r) => !empty($r['active'])));
$activeRungCount = count($activeRungs);

$validationErrors = [];

foreach (($compactStore['trades'] ?? []) as $trade) {
    if ($trade['date'] < $analysisWindowStartDate) {
        continue;
    }

    if ($trade['trade_price'] === null) {
        $validationErrors[] = "TR {$trade['tr_id']} has unresolved trade_price";
    }

    foreach (($trade['rungs'] ?? []) as $rungCode => $state) {
        if (($state['eligible'] ?? false) && !in_array($state['status'] ?? '', ['participating', 'skipped', 'depleted', 'out_of_range'], true)) {
            $validationErrors[] = "TR {$trade['tr_id']} rung {$rungCode} has invalid status: " . ($state['status'] ?? 'null');
        }
    }
}

foreach (($dailyMetrics['days'] ?? []) as $day) {
    $summedRungFees = array_sum(array_map(fn(array $rm) => (float) $rm['fees'], $day['rung_metrics'] ?? []));
    $portfolioFees = (float) ($day['portfolio']['total_fees'] ?? 0);

    if (abs($summedRungFees - $portfolioFees) > 1e-8) {
        $validationErrors[] = "Day {$day['date']} fee mismatch: summed rung fees ({$summedRungFees}) != portfolio total ({$portfolioFees})";
    }
}

if (($digest['meta']['active_rung_count'] ?? 0) !== $activeRungCount) {
    $validationErrors[] = "Digest active_rung_count ({$digest['meta']['active_rung_count']}) != config active rungs ({$activeRungCount})";
}

$digestStartDate = isset($digest['meta']['source_window']['start'])
    ? substr($digest['meta']['source_window']['start'], 0, 10)
    : null;
if ($digestStartDate !== null && $digestStartDate < $analysisWindowStartDate) {
    $validationErrors[] = "Digest source_window starts before analysis_window_start: {$digestStartDate} < {$analysisWindowStartDate}";
}

if ($validationErrors !== []) {
    fwrite(STDERR, "Validation failed:\n");
    foreach ($validationErrors as $err) {
        fwrite(STDERR, "  - {$err}\n");
    }
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    exit(1);
}

save_json_file($tradesCompactFile, $compactStore);

if ($emitDebugTrades) {
    save_json_file($tradesDebugFile, $debugStore);
}

save_json_file($dailyMetricsFile, $dailyMetrics);
save_json_file($digestFile, $digest);

$tablesDir = resolve_project_path($projectRoot, (string) ($paths['tables_dir'] ?? './tables'));
$tableBuilder = new TableBuilder();
$generatedTables = $tableBuilder->generateAll($digest, $dailyMetrics, $config, $tablesDir);

flock($lockHandle, LOCK_UN);
fclose($lockHandle);

echo $rebuild ? "Rebuild complete.\n" : "Incremental run complete.\n";
echo 'Parsed files: ' . count($processedFiles) . "\n";
echo "Trades compact: {$tradesCompactFile}\n";
if ($emitDebugTrades) {
    echo "Trades debug: {$tradesDebugFile}\n";
}
echo "Daily metrics: {$dailyMetricsFile}\n";
echo "Digest: {$digestFile}\n";
echo "Tables generated: " . count($generatedTables) . " files in {$tablesDir}\n";
