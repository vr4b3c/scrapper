#!/usr/bin/env bash
# run_generate_all.sh
# Run render_and_extract.js for six category URLs and store outputs in /tmp/dokempu_cat_urls

OUTDIR=/tmp/dokempu_cat_urls
mkdir -p "$OUTDIR"

node render_and_extract.js --url="https://www.dokempu.cz/chatky--seg-11098" --outdir="$OUTDIR" --max-wait=15000
node render_and_extract.js --url="https://www.dokempu.cz/stany--seg-11147" --outdir="$OUTDIR" --max-wait=15000
node render_and_extract.js --url="https://www.dokempu.cz/pro-karavany--seg-11981" --outdir="$OUTDIR" --max-wait=15000
node render_and_extract.js --url="https://www.dokempu.cz/mobilni-domy--seg-11197" --outdir="$OUTDIR" --max-wait=15000
node render_and_extract.js --url="https://www.dokempu.cz/glamping--seg-4969" --outdir="$OUTDIR" --max-wait=15000
node render_and_extract.js --url="https://www.dokempu.cz/pokoje--seg-11243" --outdir="$OUTDIR" --max-wait=15000

echo "Done. Check $OUTDIR"
