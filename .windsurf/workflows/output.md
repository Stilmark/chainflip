---
description: Generate LP summary tables after parsing
---

# Output Workflow

After each successful parse/rebuild, the parser automatically generates markdown summary tables in the `tables/` directory.

## Generated Tables

### config/
- **rungs.md** - Rung configuration reference

### status/
- **current.md** - Main snapshot for the active analysis window
- **latestDay.md** - One-day operational status
- **byDay.md** - Daily breakdown across all in-scope days

### prediction/
- **current.md** - Forecast using realized fees over full window
- **latestDay.md** - Forecast using only latest-day fees
- **byDay.md** - Per-day forecast table

### performance/
- **scalp.md** - Focused evaluation of S1/S2/S3
- **strategy.md** - Core vs Signal vs Scalp comparison

### distribution/
- **bucketLatestDay.md** - Price-bucket participation summary

## Manual Generation

// turbo
```bash
php bin/run.php --rebuild
```

Tables are generated automatically after JSON outputs are saved.