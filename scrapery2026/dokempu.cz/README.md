# Dokempu scrapers (cleaned)

Directory contains the minimal, current tools to generate candidate detail URLs and to extract details:

- `generate_candidates.php` — PHP enumerator that attempts to extract the embedded `items` payload and falls back to DOM/regex; writes per-category `*_urls.txt` into an output directory (default `/tmp/dokempu_cat_urls`).
- `dokempu_batch.php` — reads `*_urls.txt` lists and produces per-category CSVs with `Name;Phone;Email` in `scrapery2026/dokempu.cz/files` by default.
- `render_and_extract.js` — Puppeteer script to render JavaScript-heavy category pages and extract links; used when pages require JS.
- `run_generate_all.sh` — convenience script that runs `render_and_extract.js` for the six main categories.
- `archive/` — older/duplicated scripts moved here (kept for reference).

Quick usage

1) Generate candidates (PHP, fast but may miss JS-only pages):

```bash
php generate_candidates.php --category="https://www.dokempu.cz/stany--seg-11147" --outdir=/tmp/dokempu_cat_urls --max-pages=5 --verbose
```

2) If the page requires JS, install Node deps and use the renderer:

```bash
cd scrapery2026/dokempu.cz
npm init -y
npm install puppeteer minimist
node render_and_extract.js --url="https://www.dokempu.cz/stany--seg-11147" --outdir=/tmp/dokempu_cat_urls --max-wait=15000
```

Or run all six with `./run_generate_all.sh` after installing deps.

3) Convert candidate lists to CSVs:

```bash
php dokempu_batch.php --indir=/tmp/dokempu_cat_urls --outdir=scrapery2026/dokempu.cz/files --force --verbose
```

If you want, I can now (a) run the Node renderer here (it requires Chromium; I can install npm deps and attempt it), or (b) generate the six candidate lists using the renderer if you prefer. Tell me which.
