#!/usr/bin/env bash
LOG_DIR="/home/vrabec/Projects/scrapper/scrapery2026/zoznam.sk"
RUN_LOG="$LOG_DIR/run_unlimited.log"
STATUS_LOG="$LOG_DIR/run_unlimited.status.log"
# Write header once
echo "=== Monitor started at $(date -u +'%Y-%m-%dT%H:%M:%SZ') ===" >> "$STATUS_LOG"
while true; do
  ts="$(date +'%Y-%m-%d %H:%M:%S')"
  echo "--- $ts ---" >> "$STATUS_LOG"
  if [ -f "$RUN_LOG" ]; then
    echo "Lines in run log: $(wc -l < "$RUN_LOG")" >> "$STATUS_LOG"
    # last found/collected messages
    grep -E "Found [0-9]+ detail URLs|Collected [0-9]+ unique records|Fetching detail:" "$RUN_LOG" | tail -n 20 >> "$STATUS_LOG"
    # last 15 lines as quick context
    echo "--- tail of run log ---" >> "$STATUS_LOG"
    tail -n 15 "$RUN_LOG" >> "$STATUS_LOG"
  else
    echo "run_unlimited.log not found" >> "$STATUS_LOG"
  fi
  echo "" >> "$STATUS_LOG"
  sleep 180
done
