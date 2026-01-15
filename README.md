# scrappery

This repository contains a set of small scrapers and helper scripts for extracting contact
information (Name, Phone, Email) from several Czech listing sites. The primary script for
the e-chalupy project is in `scrapery2026/e-chalupy/e-chalupy-batch.js` and writes
per-category CSVs into the `scrapery2026/e-chalupy/files/` directory.

Quick start

1. Install dependencies:

```bash
npm install
```

2. Run the e-chalupy batch (example):

```bash
CONCURRENCY=2 node scrapery2026/e-chalupy/e-chalupy-batch.js files/e_chalupy_candidates_farmy-statky.csv --limit=10
```

Notes
- CSV outputs are semicolon-separated: `Name;Phone;Email` (master file adds Category).
- The scraper uses Puppeteer and expects network access and a Chromium binary.

License: MIT
