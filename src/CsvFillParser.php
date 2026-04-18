<?php

declare(strict_types=1);

require_once __DIR__ . '/Helpers.php';

final class CsvFillParser
{
    public function parseFile(string $path, array $config): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException("Cannot open CSV: {$path}");
        }

        $header = fgetcsv($handle, null, ',', '"', '\\');
        if (!is_array($header)) {
            fclose($handle);
            throw new RuntimeException("CSV header missing: {$path}");
        }

        $rows = [];
        $ignoreTypes = array_map('strtoupper', $config['processing']['ignore_types'] ?? []);
        $allowedAssets = array_map('strtoupper', $config['processing']['allowed_assets'] ?? ['USDT', 'USDC']);

        while (($data = fgetcsv($handle, null, ',', '"', '\\')) !== false) {
            if (count($data) !== count($header)) {
                continue;
            }

            $row = array_combine($header, $data);
            if (!is_array($row)) {
                continue;
            }

            $type = strtoupper(trim((string) ($row['Type'] ?? '')));
            if (in_array($type, $ignoreTypes, true)) {
                continue;
            }

            $filledAsset = normalize_asset((string) $row['Filled Asset']);
            $receivedAsset = normalize_asset((string) $row['Received Asset']);

            if (!in_array($filledAsset, $allowedAssets, true) || !in_array($receivedAsset, $allowedAssets, true)) {
                continue;
            }

            $range = parse_range_string((string) ($row['Price / Range'] ?? ''));

            $rows[] = [
                'fill_id' => make_fill_fingerprint($row),
                'timestamp' => trim((string) $row['Date']),
                'date' => iso_date(trim((string) $row['Date'])),
                'type' => $type,
                'filled_asset' => $filledAsset,
                'filled_amount' => to_float($row['Filled Amount']),
                'filled_value_usd' => to_float($row['Filled Value USD']),
                'received_asset' => $receivedAsset,
                'received_amount' => to_float($row['Received Amount']),
                'fee_asset' => normalize_asset((string) $row['Fee Asset']),
                'fee_amount' => to_float($row['Fee Amount']),
                'range_lower' => $range['lower'],
                'range_upper' => $range['upper'],
                'raw_range' => trim((string) $row['Price / Range']),
            ];
        }

        fclose($handle);

        return $rows;
    }
}
