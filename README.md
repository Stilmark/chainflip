# LP Parser

CLI PHP parser for USDT/USDC LP fill CSVs.

## Features

- Parses all CSV files from `input/`
- Excludes `LIMIT` fills
- Groups fills into TRs by identical timestamp and pair
- Infers TR trade price from participating fills
- Classifies each active rung as:
  - `participating`
  - `out_of_range`
  - `depleted`
- Appends new compiled trades to `data/trades.json`
- Rebuild mode can reconstruct state from archived CSVs
- Builds:
  - `data/trades.json`
  - `data/daily_metrics.json`
  - `data/digest.json`

## Run

```bash
php bin/run.php
```

## Rebuild from input + archive

```bash
php bin/run.php --rebuild
```

## Notes

- New CSV files are archived after successful incremental processing.
- Rebuild reads both `input/` and `archive/` when enabled in `config.json`.
- Dedupe is based on fill fingerprints, so overlapping CSV coverage is safe.
# chainflip
