#!/usr/bin/env bash
# Runner: keep running e-chalupy-batch.js in batches until all URLs processed.
# It detects total URLs in the candidate file, resumes from the output file,
# runs batches with --start/--limit, and kills leftover Chromium processes
# between batches so memory is reclaimed.

set -euo pipefail

INPUT="/home/vrabec/Projects/scrapper/scrapery2026/e-chalupy/files/e_chalupy_candidates_chaty-chalupy.csv"
SCRIPT="/home/vrabec/Projects/scrapper/scrapery2026/e-chalupy/e-chalupy-batch.js"
CONCURRENCY=2
BATCH_SIZE=50

# derive output filename (replace e_chalupy_candidates_ -> echalupy_)
DIR=$(dirname "${INPUT}")
BN=$(basename "${INPUT}")
OUTFILE="$DIR/${BN/e_chalupy_candidates_/echalupy_}"

echo "Input: ${INPUT}"
echo "Output: ${OUTFILE}"

if [ ! -f "${INPUT}" ]; then
  echo "Input file not found: ${INPUT}" >&2
  exit 1
fi

# count total URLs (fallback to non-empty lines if no URLs found)
TOTAL=$(grep -Eo 'https?://[^[:space:];,]+' "${INPUT}" | wc -l)
if [ "${TOTAL}" -eq 0 ]; then
  TOTAL=$(tail -n +2 "${INPUT}" | awk 'NF' | wc -l)
fi

echo "Total items to process: ${TOTAL}"

mkdir -p "$(dirname "${OUTFILE}")"

while true; do
  # current processed count (exclude header)
  if [ -f "${OUTFILE}" ]; then
    PROCESSED=$(tail -n +2 "${OUTFILE}" | awk 'NF' | wc -l)
  else
    PROCESSED=0
  fi

  if [ "${PROCESSED}" -ge "${TOTAL}" ]; then
    echo "All done: processed=${PROCESSED}, total=${TOTAL}"
    break
  fi

  START=$((PROCESSED + 1))
  echo "Running batch: start=${START} limit=${BATCH_SIZE} (processed=${PROCESSED}/${TOTAL})"

  # run batch; preserve CONCURRENCY env
  CONCURRENCY=${CONCURRENCY} node "${SCRIPT}" "${INPUT}" --start=${START} --limit=${BATCH_SIZE} || {
    echo "Batch starting at ${START} failed; aborting." >&2
    exit 1
  }

  # brief pause, then kill stray Chromium/Chrome processes to free memory
  sleep 1
  pkill -f "(chrome|chromium|chrome-headless|HeadlessChromium)" || true
  sleep 1

  # loop will recompute PROCESSED and continue
done

echo "Runner finished."
echo "Runner finished."
