#!/usr/bin/env bash
set -euo pipefail

# rename_to_dokempucz.sh
# Dry-run by default; pass --apply to perform renames.

DIR="scrapery2026/dokempu.cz/files"
APPLY=0
if [ "${1:-}" = "--apply" ]; then
  APPLY=1
fi

shopt -s nullglob
for f in "$DIR"/www-dokempu-cz-*; do
  [ -e "$f" ] || continue
  base=$(basename "$f")
  rest=${base#www-dokempu-cz-}
  if [[ "$rest" == *_urls.txt ]]; then
    slug=${rest%_urls.txt}
    new="dokempucz_${slug}_candidates.txt"
  elif [[ "$rest" == *.csv ]]; then
    slug=${rest%.csv}
    new="dokempucz_${slug}.csv"
  else
    # unknown pattern, skip
    continue
  fi
  src="$f"
  dst="$DIR/$new"
  if [ $APPLY -eq 0 ]; then
    printf "DRY: %s -> %s\n" "$src" "$dst"
  else
    if [ -e "$dst" ]; then
      printf "SKIP (exists): %s -> %s\n" "$src" "$dst"
    else
      printf "RENAME: %s -> %s\n" "$src" "$dst"
      mv -- "$src" "$dst"
    fi
  fi
done

printf "Done. Use --apply to rename files."\n
