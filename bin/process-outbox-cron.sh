#!/bin/bash
# Process notification outbox — run via cron every 2 minutes
# Example crontab entry:
#   */2 * * * * /path/to/project/bin/process-outbox-cron.sh >> /var/log/outbox-cron.log 2>&1

DIR="$(cd "$(dirname "$0")" && pwd)"
LOG="${DIR}/../storage/logs/outbox.log"

# Create log directory if missing
mkdir -p "$(dirname "$LOG")"

echo "[$(date '+Y-m-d H:i:s')] Running process-outbox..."
php "${DIR}/process-outbox.php" >> "$LOG" 2>&1
EXIT=$?

if [ $EXIT -ne 0 ]; then
  echo "[$(date '+Y-m-d H:i:s')] ERROR: process-outbox exited with code $EXIT" >> "$LOG"
fi
