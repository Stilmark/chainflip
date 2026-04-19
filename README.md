# LP Parser

CLI PHP parser for Chainflip USDT/USDC LP fill CSVs with static site generation.

## Features

### Data Processing
- Parses CSV fill exports from `input/`
- Excludes `LIMIT` fills
- Groups fills into Trade Requests (TRs) by identical timestamp and pair
- Infers TR trade price from participating fills
- Classifies each active rung as: `participating`, `out_of_range`, `skipped`, or `depleted`
- Tracks historical portfolio value using config revisions
- Rebuild mode can reconstruct state from archived CSVs

### Data Output
- `data/trades_compact.json` — Compiled trade data
- `data/trades_debug.json` — Detailed trade data for debugging
- `data/daily_metrics.json` — Daily aggregated metrics per rung
- `data/digest.json` — Current portfolio state and summary
- `data/tables/*.json` — JSON data for static site tables

### Static Site
- Generates a static site in `docs/` for GitHub Pages
- Interactive DataTables with sorting
- Pages: Dashboard, Status, Performance, Prediction, Trades, Distribution, Config

## Usage

### Incremental Run
```bash
php bin/run.php
```

### Rebuild from Archive
```bash
php bin/run.php --rebuild
```

## Configuration

Edit `config.json` to configure:
- **Rungs**: Define LP positions with ranges, allocations, and revision history
- **Portfolio**: Borrowed amounts and borrow rates
- **Processing**: Analysis window, bucket size, timezone

## Directory Structure

```
├── bin/run.php          # Main entry point
├── src/                 # PHP source files
├── config.json          # Configuration
├── data/                # Generated data files
├── docs/                # Static site (GitHub Pages)
├── input/               # CSV files to process
└── archive/             # Processed CSV files
```

## Notes

- New CSV files are archived after successful incremental processing
- Rebuild reads both `input/` and `archive/` when enabled in config
- Dedupe is based on fill fingerprints, so overlapping CSV coverage is safe
- Static site is served from `docs/` directory for GitHub Pages

