# e-chalupy scraper

This Node.js script uses Puppeteer to visit e-chalupy.cz detail pages and reveal JS-only contact info (clicks the contact button and extracts phone/email).

Quick start

1. Install dependencies:

```bash
cd scrapery2026/e-chalupy
npm install
```

2. Dry-run (no Puppeteer, quick validation that candidate file is parsed and CSV is produced):

```bash
node e-chalupy.js --dry-run
```

3. Full run (requires `npm install` to fetch Puppeteer):

```bash
npm install
node e-chalupy.js
```

Input/Output

- Input: `scrapery2026/e-chalupy/files/e_chalupy_candidates.csv` (script will try to extract URLs from the file)
- Output: `scrapery2026/e-chalupy/files/e_chalupy_contacts.csv` (semicolon-separated: Name;URL;Phone;Email;Status)

Notes

- If the site uses delayed JS, network timeouts may need to be increased.
- The script attempts several heuristics: clicking `.btn2.btn-contact`, then trying to click buttons containing 'telefon'/'zobrazit', and finally scraping `tel:` links or phone-like text.
