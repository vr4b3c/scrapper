#!/usr/bin/env node
const fs = require('fs');
const path = require('path');

const BASE_DIR = path.dirname(__filename);
const INPUT = process.env.INPUT_FILE || path.join(BASE_DIR, 'files', 'e_chalupy_candidates.csv');
const OUTPUT = process.env.OUTPUT_FILE || path.join(BASE_DIR, 'files', 'e_chalupy_contacts.csv');

const args = process.argv.slice(2);
const dryRun = args.includes('--dry-run');
const crawlIndex = args.indexOf('--crawl-category');
const crawlUrl = crawlIndex !== -1 ? args[crawlIndex+1] : null;
const maxPagesArgIndex = args.indexOf('--max-pages');
// default: 0 = unlimited; provide --max-pages N to cap
const maxPages = maxPagesArgIndex !== -1 ? parseInt(args[maxPagesArgIndex+1]||'0',10) : 0;
const categoriesArgIndex = args.indexOf('--categories');
const categoriesArg = categoriesArgIndex !== -1 ? args[categoriesArgIndex+1] : null;
const allCategories = args.includes('--all-categories');
const crawlAndScrape = args.includes('--crawl-and-scrape');
const samplePerCategoryIndex = args.indexOf('--sample-per-category');
const samplePerCategory = samplePerCategoryIndex !== -1 ? (parseInt(args[samplePerCategoryIndex+1],10) || null) : null;
const verbose = args.includes('--verbose');
const DEBUG_LOG = path.join(BASE_DIR, 'debug.log');

// Only these category slugs are allowed for crawling/scraping
const ALLOWED_CATEGORIES = ['chaty-chalupy','sruby-roubenky','glamping','apartmany','penziony','kempy','vinne-sklepy','farmy-statky'];

function logDebug(...parts) {
  const line = `[${new Date().toISOString()}] ` + parts.map(p => (typeof p==='string'?p:JSON.stringify(p))).join(' ')+"\n";
  try { fs.appendFileSync(DEBUG_LOG, line); } catch(e){}
  if (verbose) console.log(line.trim());
}

function sleep(ms){ return new Promise(r=>setTimeout(r,ms)); }

async function waitForSelectorPoll(page, selector, timeoutMs=2000, interval=50){
  const start = Date.now();
  while (Date.now() - start < timeoutMs){
    try{ const el = await page.$(selector); if (el) return el; }catch(e){}
    await sleep(interval);
  }
  return null;
}

function parseUrlsFromCsv(csvText) {
  const lines = csvText.split(/\r?\n/).map(l=>l.trim()).filter(Boolean);
  const urls = [];
  for (const line of lines) {
    const m = line.match(/https?:\/\/[^\s,\"]+/);
    if (m) urls.push(m[0]);
  }
  return urls;
}

async function writeCsv(rows) {
  const header = 'Name;URL;Phone;Email;Status\n';
  const lines = rows.map(r => [r.name||'', r.url||'', r.phone||'', r.email||'', r.status||''].map(v => String(v).replace(/\r?\n/g,' ')).join(';'));
  fs.writeFileSync(OUTPUT, header + lines.join('\n'));
  console.log('Wrote', OUTPUT);
}

async function dryRunMain() {
  if (!fs.existsSync(INPUT)) {
    console.error('Input file not found:', INPUT);
    process.exit(2);
  }
  const csv = fs.readFileSync(INPUT,'utf8');
  const urls = parseUrlsFromCsv(csv);
  console.log('Found', urls.length, 'candidate URLs');
  const rows = urls.map(u => ({name:'', url:u, phone:'', email:'', status:'dry-run'}));
  await writeCsv(rows);
}

async function realMain(urlsArg) {
  const puppeteer = require('puppeteer');
  let urls = [];
  if (Array.isArray(urlsArg) && urlsArg.length) {
    urls = urlsArg.slice();
  } else {
    if (!fs.existsSync(INPUT)) {
      console.error('Input file not found:', INPUT);
      process.exit(2);
    }
    const csv = fs.readFileSync(INPUT,'utf8');
    urls = parseUrlsFromCsv(csv);
  }
  console.log('Processing', urls.length, 'URLs');

  const browser = await puppeteer.launch({headless:true, args:['--no-sandbox','--disable-setuid-sandbox']});

  const rows = [];
  for (const url of urls) {
    console.log('Fetching', url);
    logDebug('START_URL', url);
    const result = {name:'', url, phone:'', email:'', status:''};
    let page = null;
    const t0 = Date.now();
    try {
      page = await browser.newPage();
      await page.setUserAgent('Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0 Safari/537.36');
      page.setDefaultNavigationTimeout(0);
      page.setDefaultTimeout(0);

      if (verbose) {
        page.on('console', msg => logDebug('PAGE_CONSOLE', url, msg.type(), msg.text()));
        page.on('pageerror', err => logDebug('PAGE_ERROR', url, err && err.stack?err.stack:err));
        page.on('response', resp => logDebug('RESPONSE', url, resp.status(), resp.url()));
      }

      logDebug('GOTO_START', url);
      await page.goto(url, {waitUntil:'networkidle2', timeout:30000});
      logDebug('GOTO_DONE', url, 'elapsed_ms', Date.now()-t0);

      // Try to click the contact reveal button if present, handling possible navigation
      try {
        const btn = await page.$('.btn2.btn-contact');
        if (btn) {
          logDebug('CLICK_BTN_CONTACT', url);
          await btn.click({button:'left'}).catch(()=>{});
          // poll for popup or tel anchors instead of relying on long waits
          await waitForSelectorPoll(page, '.property-detail-popup, a[href^="tel:"]', 1500, 50);
          logDebug('CLICK_DONE', url);
        }
      } catch(e){ logDebug('CLICK_ERR', url, e && e.stack?e.stack:e); }

      // Try to reveal phone by clicking only contact-related buttons/links (avoid galleries)
      try {
        const buttons = await page.$$('button, a');
        let clickCount = 0;
        const CLICK_LIMIT = 6;
        for (const b of buttons) {
          if (clickCount >= CLICK_LIMIT) break;
          const rawTxt = (await (await b.getProperty('innerText')).jsonValue() || '').toString();
          const txt = rawTxt.toLowerCase();
          const cls = (await (await b.getProperty('className')).jsonValue() || '').toString().toLowerCase();
          const href = (await (await b.getProperty('href')).jsonValue() || '') || '';

          // include if clearly contact-related
          const include = /telefon|telefonn|show phone|zobrazit telefon|kontakt|kontaktovat|phone/i.test(txt) || /item phone|phone|contact|btn-contact/.test(cls) || href.startsWith('tel:');
          // exclude gallery/map/photo controls
          const exclude = /fot|foto|obráz|map|zobrazit víc|zobrazit dalších|víc fotek|více fotek|gallery|photo/i.test(txt) || /gallery|photo|map|lightbox/.test(cls);
          if (include && !exclude) {
            logDebug('CLICK_CAND', url, txt || cls || href);
            await b.click().catch(()=>{});
            await waitForSelectorPoll(page, '.property-detail-popup, a[href^="tel:"]', 1500, 50);
            await sleep(50);
            logDebug('CLICK_CAND_DONE', url, txt || cls || href);
            clickCount++;
          }
        }
      } catch(e){ logDebug('CLICK_LOOP_ERR', url, e && e.stack?e.stack:e); }

      // Extract name, phone, email (collect multiple phones, normalize, filter coords)
      const data = await page.evaluate(() => {
        function pickText(sel){ const el=document.querySelector(sel); return el?el.innerText.trim():'' }
        const name = pickText('h1') || pickText('.product-title') || pickText('.title');
        const candidates = new Set();
        // tel anchors
        document.querySelectorAll('a[href^="tel:"]').forEach(a=>{
          try{ const v = a.getAttribute('href').replace(/^tel:\s*/,'').trim(); if (v) candidates.add(v); }catch(e){}
        });
        // email
        let email = '';
        const mail = document.querySelector('a[href^="mailto:"]');
        if (mail) email = mail.getAttribute('href').replace(/^mailto:\s*/,'').trim();
        // find phone-like visible text
        const els = Array.from(document.querySelectorAll('a,span,div,p,li,strong'));
        const phoneRegex = /[0-9+][0-9\s().\/\-]{6,}/;
        for (const el of els) {
          const t = (el.innerText||'').trim();
          if (!t) continue;
          if (phoneRegex.test(t)) candidates.add(t);
        }
        // normalize and filter
        function normalizePhone(s){
          s = (s||'').trim(); if (!s) return '';
          const onlyDigitsDots = s.replace(/[^0-9.]/g,'');
          if (s.includes('.') && !s.includes(' ') && /^[0-9.]+$/.test(onlyDigitsDots) && onlyDigitsDots.length>5) return '';
          const plus = s.trim().startsWith('+') ? '+' : '';
          const digits = s.replace(/[^0-9]/g,'');
          if (!digits) return '';
          return plus + digits;
        }
        const res = [];
        candidates.forEach(c=>{ const n = normalizePhone(c); if (n) res.push(n); });
        const uniq = Array.from(new Set(res));
        return {name, phone: uniq.join(' | '), email};
      });

      result.name = data.name;
      result.phone = data.phone;
      result.email = data.email;
      result.status = (result.phone||result.email)?'ok':'not-found';
      logDebug('EXTRACT', url, {name:result.name, phone:result.phone? 'FOUND':'', email:result.email? 'FOUND':''});
    } catch (err) {
      console.error('Error for', url, err && err.message); logDebug('ERROR', url, err && err.stack?err.stack:err);
      result.status = 'error';
    } finally {
      if (page) try { await page.close(); } catch(e){}
      rows.push(result);
      logDebug('FINISH_URL', url, 'elapsed_ms', Date.now()-t0, 'status', result.status);
    }
  }

  await browser.close();
  await writeCsv(rows);
}

(async ()=>{
  if (dryRun) return await dryRunMain();

  // If user requested simple crawl-only for a single category
  if (crawlUrl && !crawlAndScrape) {
    const puppeteer = require('puppeteer');
    const browser = await puppeteer.launch({headless:true, args:['--no-sandbox','--disable-setuid-sandbox']});
    const page = await browser.newPage();
    await page.setUserAgent('Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0 Safari/537.36');
    const found = new Set();
    let p = 1;
    let emptyStreak = 0;
    while (true) {
      if (maxPages > 0 && p > maxPages) break;
      const url = crawlUrl.includes('?') ? crawlUrl.replace(/\?p=\d+/, `?p=${p}`) : `${crawlUrl}?p=${p}`;
      console.log('Crawling page', url);
      let links = [];
      let newCount = 0;
      try {
        await page.goto(url, {waitUntil:'networkidle2', timeout:0});
        // extract detail links from .property-list and .images-link
        links = await page.evaluate(() => {
          const out = [];
          const containers = document.querySelectorAll('.property-list');
          containers.forEach(c => {
            c.querySelectorAll('a.images-link').forEach(a => {
              const href = a.href || a.getAttribute('href');
              if (href && /-o[0-9]{1,5}/.test(href)) out.push(href);
            });
            // also anchor tags inside property-list: only include links that look like detail pages (contain -o<digits>)
            c.querySelectorAll('a').forEach(a=>{
              const href=a.href||a.getAttribute('href');
              if (href && /-o[0-9]{1,5}/.test(href)) out.push(href);
            });
          });
          return out;
        });
        let newCount = 0;
        for (const l of links) {
          try{
            const norm = new URL(l, page.url()).toString();
            if (!found.has(norm)) { found.add(norm); newCount++; }
          }catch(e){}
        }
      } catch(e){ console.error('Page error', e.message); }
      await sleep(50);
      // break if page returned no new links repeatedly
      if (newCount === 0) {
        emptyStreak++;
        if (emptyStreak >= 3) break;
      } else {
        emptyStreak = 0;
      }
      p++;
    }
    await page.close();
    await browser.close();
    const rows = Array.from(found).map(u=>({url:u}));
    const header = 'URL\n';
    const csv = header + rows.map(r=>r.url).join('\n') + '\n';
    const outPath = path.join(BASE_DIR, 'files', 'e_chalupy_candidates_category.csv');
    require('fs').writeFileSync(outPath, csv);
    console.log('Wrote', outPath, 'with', rows.length, 'candidates');
    process.exit(0);
  }

  // Crawl multiple categories (discover or provided) and then run detail scraping
  if (crawlAndScrape || allCategories || categoriesArg) {
    const puppeteer = require('puppeteer');
    const browser = await puppeteer.launch({headless:true, args:['--no-sandbox','--disable-setuid-sandbox']});
    const page = await browser.newPage();
    await page.setUserAgent('Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0 Safari/537.36');

    let categories = [];
    if (allCategories) {
      const home = 'https://www.e-chalupy.cz/';
      await page.goto(home, {waitUntil:'networkidle2', timeout:20000});
      categories = await page.evaluate(() => {
        const out = [];
        document.querySelectorAll('.submenu-box .menu-types a').forEach(a=>{
          const href = a.getAttribute('href'); if (href) out.push(href);
        });
        return out;
      });
      categories = categories.map(s=> s.startsWith('http')?s:`https://www.e-chalupy.cz/${s.replace(/^\//,'')}`);
      // filter to only allowed category slugs
      categories = categories.filter(u => {
        try{ const slug = u.replace(/https?:\/\/[^/]+\//,'').split(/[\/?#]/)[0]; return ALLOWED_CATEGORIES.includes(slug); }catch(e){return false}
      });
    } else if (categoriesArg) {
      // only accept provided categories if they are in the allowed list
      categories = categoriesArg.split(',').map(s=>s.trim()).filter(Boolean).map(s => s.replace(/^\//,''));
      categories = categories.filter(s => ALLOWED_CATEGORIES.includes(s)).map(s => `https://www.e-chalupy.cz/${s}`);
    }

    const allDetailUrls = [];
    for (const cat of categories) {
      const found = new Set();
      console.log('Crawling category', cat);
      let p = 1;
      let emptyStreak = 0;
      while (true) {
        if (maxPages > 0 && p > maxPages) break;
        const url = cat.includes('?') ? cat.replace(/\?p=\d+/, `?p=${p}`) : `${cat}?p=${p}`;
        let links = [];
        let newCount = 0;
        try {
          await page.goto(url, {waitUntil:'networkidle2', timeout:0});
          links = await page.evaluate(() => {
            const out = [];
            const containers = document.querySelectorAll('.property-list');
            containers.forEach(c => {
              c.querySelectorAll('a.images-link').forEach(a => {
                const href = a.href || a.getAttribute('href');
                if (href && /-o[0-9]{1,5}/.test(href)) out.push(href);
              });
              c.querySelectorAll('a').forEach(a=>{
                const href=a.href||a.getAttribute('href');
                if (href && /-o[0-9]{1,5}/.test(href)) out.push(href);
              });
            });
            return out;
          });
          let newCount = 0;
          for (const l of links) {
            try{
              const norm = new URL(l, page.url()).toString();
              if (!found.has(norm)) { found.add(norm); newCount++; }
            }catch(e){}
          }
        } catch(e){ console.error('Page error', e.message); }
        await sleep(50);
        if (newCount === 0) {
          emptyStreak++;
          if (emptyStreak >= 3) break;
        } else {
          emptyStreak = 0;
        }
        p++;
      }
      const catSlug = cat.replace(/https?:\/\/[^/]+/,'').replace(/^\//,'').replace(/[\/?=&]/g,'_').replace(/-/g,'_') || 'cat';
      const outPath = path.join(BASE_DIR, 'files', `e_chalupy_candidates_${catSlug}.csv`);
      require('fs').writeFileSync(outPath, 'URL\n' + Array.from(found).join('\n') + '\n');
      console.log('Wrote', outPath, 'with', found.size, 'candidates');
      const list = Array.from(found);
      if (samplePerCategory) allDetailUrls.push(...list.slice(0, samplePerCategory)); else allDetailUrls.push(...list);
    }
    await page.close();
    await browser.close();

    if (allDetailUrls.length === 0) { console.log('No detail URLs found; exiting'); process.exit(0);} 
    console.log('Running detail scraper on', allDetailUrls.length, 'URLs');
    try { await realMain(allDetailUrls); } catch(e){ console.error('Detail scraping failed', e.message); process.exit(1);} 
    process.exit(0);
  }

  // if not dry-run or crawl-category, run real fetching
  try { await realMain(); } catch(e) { console.error('Fatal:', e.message); process.exit(1);} 
})();
