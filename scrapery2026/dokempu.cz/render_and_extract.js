#!/usr/bin/env node
// render_and_extract.js
// Uses Puppeteer to render a Dokempu category page and extract detail links.
// Usage: node render_and_extract.js --url=URL [--outdir=/tmp/dokempu_cat_urls] [--max-wait=10000]

const fs = require('fs');
const path = require('path');
const argv = require('minimist')(process.argv.slice(2));
const url = argv.url;
const outdir = argv.outdir || 'scrapery2026/dokempu.cz/files';
const maxWait = parseInt(argv['max-wait'] || '10000', 10);
const maxPagesForced = parseInt(argv['max-pages'] || argv['max_pages'] || '0', 10);
const retryCount = parseInt(argv['retries'] || '3', 10);
const forceQuery = argv['force-query'] || argv['force_query'] || false;

if (!url) {
  console.error('Usage: node render_and_extract.js --url=URL [--outdir=/tmp/dokempu_cat_urls]');
  process.exit(2);
}
// stray characters removed
async function main() {
  const puppeteer = require('puppeteer');
  if (!fs.existsSync(outdir)) fs.mkdirSync(outdir, { recursive: true });

  const browser = await puppeteer.launch({ args: ['--no-sandbox', '--disable-setuid-sandbox'] });
  const page = await browser.newPage();
  await page.setUserAgent('Mozilla/5.0 (compatible)');
  page.setDefaultNavigationTimeout(maxWait + 5000);

  try {
    await page.goto(url, { waitUntil: 'networkidle2', timeout: maxWait });
  } catch (e) {
    // ignore navigation timeout, attempt to continue
  }

  // wait for a likely container
  try { await page.waitForSelector('#carItemsWraper', { timeout: 3000 }); } catch (e) {}

  // helper to extract links from current page DOM
  async function extractLinksFromPage(p) {
    return await p.evaluate(() => {
      const out = new Set();
      const add = (href) => {
        if (!href) return;
        if (!href.startsWith('http')) href = 'https://www.dokempu.cz/' + href.replace(/^\//, '');
        if (/--seg-/i.test(href)) return;
        if (/--kemp-\d+/i.test(href) || /\/detail\//i.test(href) || /\/objekt\//i.test(href)) out.add(href.split('?')[0]);
      };
      const container = document.querySelector('#carItemsWraper');
      if (container) {
        container.querySelectorAll('a[href]').forEach(a => add(a.getAttribute('href')));
      }
      if (out.size === 0) document.querySelectorAll('a[href]').forEach(a => add(a.getAttribute('href')));
      return Array.from(out);
    });
  }

  // detect last page from paginator (penultimate <li>), fallback to scanning numeric page links
  const detected = await page.evaluate(() => {
    function parseIntSafe(s){ const m = (s||'').match(/\d+/); return m?parseInt(m[0],10):null; }
    // search for obvious pagination containers
    const selectors = ['.pagination','nav[aria-label]','.paginator','ul.pagination','div.pagination','nav'];
    for (const sel of selectors) {
      const el = document.querySelector(sel);
      if (!el) continue;
      const lis = Array.from(el.querySelectorAll('li'));
      if (lis.length >= 2) {
        const pen = lis[lis.length-2];
        const v = parseIntSafe(pen && pen.textContent);
        if (v) return {pages: v, sampleHref: (pen.querySelector('a')||{}).href || null};
      }
    }
    // fallback: find any nav/ul near carItemsWraper
    const wrapper = document.querySelector('#carItemsWraper');
    if (wrapper) {
      const parentNav = wrapper.parentElement && wrapper.parentElement.querySelector('ul');
      if (parentNav) {
        const lis = Array.from(parentNav.querySelectorAll('li'));
        if (lis.length >= 2) {
          const pen = lis[lis.length-2];
          const v = parseIntSafe(pen && pen.textContent);
          if (v) return {pages: v, sampleHref: (pen.querySelector('a')||{}).href || null};
        }
      }
    }
    // last resort: scan anchors for page numbers
    const anchors = Array.from(document.querySelectorAll('a[href]'));
    let max = 1; let sample = null;
    anchors.forEach(a=>{
      const n = parseIntSafe(a.textContent) || parseIntSafe(a.href);
      if (n && n>max) { max = n; sample = a.href; }
    });
    return {pages: max, sampleHref: sample};
  });

  const results = new Set(await extractLinksFromPage(page));

  const doPaginate = argv.paginate || argv.p;
  if (doPaginate && detected) {
    let maxPage = detected.pages || 1;
    if (maxPagesForced && maxPagesForced > 0) maxPage = maxPagesForced;
    // try to build page URL template: try sampleHref if available, else use ?page=N
    let template = null;
    if (detected.sampleHref && !forceQuery) template = detected.sampleHref;
    // helper to fetch a page with retries/backoff
    async function fetchPageWithRetries(pageUrl, pageNumber) {
      for (let attempt = 1; attempt <= retryCount; attempt++) {
        try {
          const to = Math.min(maxWait + attempt * 2000, 60000);
          await page.goto(pageUrl, { waitUntil: 'networkidle2', timeout: to });
        } catch (e) {
          // navigation may timeout but page could still be usable
        }
        try { await page.waitForSelector('#carItemsWraper', { timeout: 2000 * attempt }); } catch (e) {}
        // check sentinel
        const noResults = await page.evaluate(() => {
          try {
            const txt = document.body && document.body.innerText || '';
            return txt.indexOf('Takové kempy u nás nemáme.') !== -1 || txt.indexOf('Takové kempy u nás nemáme') !== -1;
          } catch (e) { return false; }
        });
        if (noResults) {
          console.log('page', pageNumber, 'has no results marker; stopping pagination');
          return { links: [], noResults: true };
        }
        const L = await extractLinksFromPage(page);
        if (L && L.length > 0) {
          return { links: L, noResults: false };
        }
        console.log('page', pageNumber, 'attempt', attempt, 'returned 0 links, retrying...');
        try { await page.reload({ waitUntil: 'networkidle2', timeout: 5000 + attempt*2000 }); } catch(e){}
        await new Promise(r => setTimeout(r, 500 * attempt));
      }
      // last attempt: return whatever we have
      const finalL = await extractLinksFromPage(page);
      return { links: finalL || [], noResults: false };
    }

    for (let n=2; n<=maxPage; n++) {
      let pageUrl;
      if (template) {
        pageUrl = template.replace(/(\d+)/, String(n));
      } else {
        pageUrl = url + (url.includes('?') ? '&' : '?') + 'page=' + n;
      }
      const res = await fetchPageWithRetries(pageUrl, n);
      if (res.noResults) break;
      res.links.forEach(u => results.add(u));
      console.log('page', n, 'links:', res.links.length);
    }
    // navigate back to original url for neatness
    try { await page.goto(url, { waitUntil: 'networkidle2', timeout: 3000 }); } catch(e){}
  }

  const links = Array.from(results);

  await browser.close();

  const safe = url.replace(/https?:\/\//, '').replace(/[^a-z0-9\-]/gi, '-').replace(/--+/g, '-').replace(/^-|-$/g, '');
  const outFile = path.join(outdir, safe + '_urls.txt');
  fs.writeFileSync(outFile, links.join('\n') + (links.length ? '\n' : ''));
  console.log('Wrote', outFile, 'entries:', links.length);
}

main().catch(err => { console.error(err); process.exit(1); });
