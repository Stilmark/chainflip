<?php

declare(strict_types=1);

function load_json_file(string $path, array $default = []): array
{
    if (!file_exists($path)) {
        return $default;
    }

    $json = file_get_contents($path);
    if ($json === false || trim($json) === '') {
        return $default;
    }

    $data = json_decode($json, true);
    if (!is_array($data)) {
        throw new RuntimeException("Invalid JSON in {$path}");
    }

    return $data;
}

function save_json_file(string $path, array $data): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException("Failed to create directory {$dir}");
    }

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException("Failed to encode JSON for {$path}");
    }

    if (file_put_contents($path, $json . PHP_EOL) === false) {
        throw new RuntimeException("Failed to write {$path}");
    }
}

function normalize_asset(string $asset): string
{
    return strtoupper(trim($asset));
}

function to_float(mixed $value): float
{
    return (float) str_replace(',', '', (string) $value);
}

function iso_date(string $timestamp): string
{
    return substr($timestamp, 0, 10);
}

function sha1_file_safe(string $path): string
{
    $hash = sha1_file($path);
    if ($hash === false) {
        throw new RuntimeException("Failed to hash file {$path}");
    }
    return $hash;
}

function make_fill_fingerprint(array $row): string
{
    return sha1(json_encode([
        $row['Date'] ?? null,
        $row['Type'] ?? null,
        $row['Filled Asset'] ?? null,
        $row['Filled Amount'] ?? null,
        $row['Filled Value USD'] ?? null,
        $row['Received Asset'] ?? null,
        $row['Received Amount'] ?? null,
        $row['Fee Asset'] ?? null,
        $row['Fee Amount'] ?? null,
        $row['Price / Range'] ?? null,
    ], JSON_UNESCAPED_SLASHES));
}

function parse_range_string(string $range): array
{
    $parts = array_map('trim', explode('-', $range));
    if (count($parts) !== 2) {
        throw new RuntimeException("Invalid range string: {$range}");
    }

    return [
        'lower' => (float) trim($parts[0]),
        'upper' => (float) trim($parts[1]),
    ];
}

function price_quote_per_base(
    string $baseAsset,
    string $quoteAsset,
    string $filledAsset,
    float $filledAmount,
    string $receivedAsset,
    float $receivedAmount
): ?float {
    $filledAsset = normalize_asset($filledAsset);
    $receivedAsset = normalize_asset($receivedAsset);

    if ($filledAmount <= 0 || $receivedAmount <= 0) {
        return null;
    }

    if ($filledAsset === $quoteAsset && $receivedAsset === $baseAsset) {
        return $filledAmount / $receivedAmount;
    }

    if ($filledAsset === $baseAsset && $receivedAsset === $quoteAsset) {
        return $receivedAmount / $filledAmount;
    }

    return null;
}

function mean(array $values): ?float
{
    if ($values === []) {
        return null;
    }

    return array_sum($values) / count($values);
}

function resolve_project_path(string $baseDir, string $path): string
{
    if ($path === '') {
        return $baseDir;
    }

    if ($path[0] === '/' || preg_match('/^[A-Za-z]:\\\\/', $path) === 1) {
        return $path;
    }

    return rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
}

function ensure_dir(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
        throw new RuntimeException("Failed to create directory {$path}");
    }
}

function ranges_match_tolerant(float $lower1, float $upper1, float $lower2, float $upper2, float $tolerance = 1e-12): bool
{
    return abs($lower1 - $lower2) <= $tolerance && abs($upper1 - $upper2) <= $tolerance;
}

function vwap(array $fills, string $base, string $quote): ?float
{
    $totalValue = 0.0;
    $totalVolume = 0.0;

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

        $volume = (float) ($fill['filled_value_usd'] ?? 0);
        if ($volume <= 0) {
            $volume = (float) $fill['filled_amount'];
        }

        $totalValue += $price * $volume;
        $totalVolume += $volume;
    }

    if ($totalVolume <= 0) {
        return null;
    }

    return $totalValue / $totalVolume;
}

function is_valid_pair_fill(array $fill, array $allowedAssets): bool
{
    $filled = strtoupper(trim((string) ($fill['filled_asset'] ?? '')));
    $received = strtoupper(trim((string) ($fill['received_asset'] ?? '')));

    return in_array($filled, $allowedAssets, true) && in_array($received, $allowedAssets, true);
}

function get_rung_revision_at(array $rung, string $timestamp): ?array
{
    $revisions = $rung['revisions'] ?? [];
    if (empty($revisions)) {
        return null;
    }

    $ts = strtotime($timestamp);
    if ($ts === false) {
        return null;
    }

    foreach ($revisions as $rev) {
        $from = strtotime($rev['effective_from'] ?? '');
        $to = isset($rev['effective_to']) && $rev['effective_to'] !== null
            ? strtotime($rev['effective_to'])
            : null;

        if ($from === false) {
            continue;
        }

        if ($ts >= $from && ($to === null || $ts < $to)) {
            return $rev;
        }
    }

    return null;
}

function get_current_revision(array $rung): ?array
{
    $revisions = $rung['revisions'] ?? [];
    if (empty($revisions)) {
        return null;
    }

    foreach ($revisions as $rev) {
        if (!isset($rev['effective_to']) || $rev['effective_to'] === null) {
            return $rev;
        }
    }

    return end($revisions) ?: null;
}

function get_rung_created_at(array $rung): ?string
{
    $revisions = $rung['revisions'] ?? [];
    if (empty($revisions)) {
        return null;
    }

    $earliest = null;
    foreach ($revisions as $rev) {
        $from = $rev['effective_from'] ?? null;
        if ($from !== null && ($earliest === null || $from < $earliest)) {
            $earliest = $from;
        }
    }

    return $earliest;
}
