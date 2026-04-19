#!/bin/bash

# Navigate to project root
cd "$(dirname "$0")/.." || exit 1

# Run the PHP parser
php bin/run.php

# Check if there are any changes to commit
if git diff --quiet && git diff --cached --quiet; then
    echo "No changes to commit."
    exit 0
fi

# Stage all changes
git add -A

# Create commit with timestamp
TIMESTAMP=$(date '+%Y-%m-%d %H:%M:%S')
git commit -m "Trade update $TIMESTAMP"

# Push to remote
git push

echo "Changes committed and pushed successfully."
