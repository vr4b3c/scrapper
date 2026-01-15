const fs = require('fs');
const path = require('path');
const puppeteer = require('puppeteer');

const DEFAULT_INPUT = path.join(__dirname,'files','e_chalupy_candidates_chaty-chalupy.csv');
const OUT_FILE = path.join(__dirname,'files','echalupysk.csv');
const MASTER_OUT = path.join(__dirname,'files','echalupy.csv');
const CONCURRENCY = parseInt(process.env.CONCURRENCY || '4', 10);

function ensureCsvHeader(filePath, header){
  try{
    if(!fs.existsSync(filePath)){
      fs.writeFileSync(filePath, header, 'utf8');
      return;
    }
    const stat = fs.statSync(filePath);
    if(stat.size === 0){ fs.writeFileSync(filePath, header, 'utf8'); return; }
    const sample = fs.readFileSync(filePath, 'utf8').split(/\r?\n/)[0] || '';
    const firstCol = (header.split(';')[0]||'').trim();
    if(!sample || !sample.startsWith(firstCol)){
      const content = fs.readFileSync(filePath, 'utf8');
      fs.writeFileSync(filePath, header + content, 'utf8');
    }
  }catch(e){ try{ fs.writeFileSync(filePath, header, 'utf8'); }catch(_){} }
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

// Wait until a phone is revealed: either a tel: anchor appears or visible text with digits
async function waitForPhoneReveal(page, timeoutMs=5000, interval=100){
  const start = Date.now();
  while(Date.now() - start < timeoutMs){
    try{
      const anchors = await page.$$eval('a[href^="tel:"]', els => els.map(a=>a.getAttribute('href')||''));
      if(Array.isArray(anchors) && anchors.some(h=>/\d/.test(h))) return true;
      const texts = await page.$$eval('.property-detail-popup .item.phone, .item.phone', els => els.map(e=> (e.innerText||e.textContent||'').trim()));
      if(Array.isArray(texts) && texts.some(t=> (t.match(/\d/g)||[]).length >= 6)) return true;
    }catch(e){}
    await sleep(interval);
  }
  return false;
}

// Wait until a clicked button element's parent contains an anchor.phone (button -> a transform)
async function waitForPhoneSwitch(page, buttonHandle, timeoutMs=5000, interval=100){
  try{
    const start = Date.now();
    const parentHandle = await (await buttonHandle.getProperty('parentElement')).asElement();
    if(!parentHandle){ console.log('  [debug] waitForPhoneSwitch: parentElement not found'); return false; }
    while(Date.now() - start < timeoutMs){
      try{
        const a = await parentHandle.$('a.item.phone');
        if(a){ console.log('  [debug] waitForPhoneSwitch: anchor.item.phone appeared in parent'); try{ await a.dispose(); }catch(e){}; try{ await parentHandle.dispose(); }catch(e){}; return true; }
      }catch(e){}
      await sleep(interval);
    }
    try{ await parentHandle.dispose(); }catch(e){}
  }catch(e){}
  return false;
}

// Simple concurrent pool for async tasks
async function processInPool(items, workerFn, concurrency=4){
  let i = 0;
  const runners = new Array(Math.max(1, concurrency)).fill(0).map(async ()=>{
    while(true){
      const idx = i++; if (idx >= items.length) break;
      await workerFn(items[idx], idx);
    }
  });
  await Promise.all(runners);
}

async function extractFromPage(page, url){
  const result = { url, name:'', phone:'', email:'', status:'error' };
  try{
    await page.goto(url, { waitUntil:'domcontentloaded', timeout:30000 });
    await waitForSelectorPoll(page, 'h1', 1000, 50);
    try{ result.name = (await page.$eval('h1', el=>el.innerText.trim())).toString(); }catch(e){}
    let phonesFromClicks = [];
    // contact modal flow: click the contact button, then click each button.item.phone
    try{
      const contactBtn = await page.$('.property-detail-order .btn2.btn-contact');
      if(contactBtn){

        await contactBtn.click().catch(()=>{});

        await waitForSelectorPoll(page, '.property-detail-popup', 3000, 100);

        await sleep(50);
        // find button.item.phone inside the popup and click each; after click wait for a.item.phone in the popup
        try{
          const phoneButtons = await page.$$('.property-detail-popup button.item.phone');
          if(phoneButtons && phoneButtons.length){
            let idx = 0;
            for(const pb of phoneButtons){
              idx++;
              try{ await pb.click().catch(()=>{}); }catch(e){}
              // immediately collect anchors/items inside popup after click
              try{
                const anchorsNow = await page.$$eval('.property-detail-popup a[href^="tel:"]', els => els.map(a=> (a.getAttribute('href')||'').replace(/^tel:\s*/i,'').trim()));
                const popupNow = await page.$$eval('.property-detail-popup .item.phone', els => els.map(e=> (e.innerText||e.textContent||'').trim()));
                console.log('  [debug] IMMEDIATE RAW after phone clicks - anchors:', anchorsNow, 'popup:', popupNow);
                for(const v of [...(anchorsNow||[]), ...(popupNow||[] )]){ if(v && !phonesFromClicks.includes(v)) phonesFromClicks.push(v); }
                if(phonesFromClicks.length) console.log('  [debug] collected phonesFromClicks now:', phonesFromClicks);
              }catch(e){  }
              // prefer to detect parent transform (button -> anchor) if possible
              try{
                const switched = await waitForPhoneSwitch(page, pb, 3000, 100);
               
                if(!switched){
             
                  await waitForSelectorPoll(page, '.property-detail-popup a.item.phone', 3000, 100);
                }
              }catch(e){ }
              await sleep(50);
            }
          } else {
            console.log('  [debug] no phone buttons found in popup');
          }
        }catch(e){}
      }
    }catch(e){/* ignore */}

    // click any element that contains text like "Zobrazit telefon" to reveal phone
    try{
      const clicked = await page.evaluate(()=>{
        const re = /zobrazit\s+telefon/i;
        const els = Array.from(document.querySelectorAll('a,button,span,div'));
        let c = 0;
        for(const el of els){
          try{ if(el.innerText && re.test(el.innerText)){ el.click(); c++; } }catch(e){}
        }
        return c;
      });

      // wait for phone to be revealed (fast polling)
      await waitForPhoneReveal(page, 5000, 100);
      await sleep(50);
    }catch(e){ console.log('  [debug] evaluate reveal threw', e && e.message); }

    // fallback generic click selectors
    const contactSelectors = ['.btn2.btn-contact','button.item.contact','.item.contact','a[href^="mailto:"]','a[href^="tel:"]'];
    for(const sel of contactSelectors){
      try{ const el = await page.$(sel); if(el){ console.log('  [debug] clicking fallback selector', sel); await el.click().catch(()=>{}); await sleep(50); } }catch(e){ console.log('  [debug] fallback selector click failed', sel, e && e.message); }
    }

      // also click any element with text like "Kontakt" / "Kontaktovat" to trigger contact reveal
      try{
        const kc = await page.evaluate(()=>{
          const re = /kontaktovat|kontakt/i;
          const els = Array.from(document.querySelectorAll('a,button,span,div'));
          let c = 0;
          for(const el of els){ try{ if(el.innerText && re.test(el.innerText)){ el.click(); c++; } }catch(e){} }
          return c;
        });
        await sleep(250);
      }catch(e){ console.log('  [debug] contact-text evaluate threw', e && e.message); }

    // Click the first visible "reveal phone" control (if any). Clicking one often reveals all phones.
    try{
      const clicked = await page.evaluate(()=>{
        const re = /zobrazit\s+telefon|telefon/i;
        const els = Array.from(document.querySelectorAll('button, a, span, div'));
        for(const el of els){
          try{ if(el.innerText && re.test(el.innerText)){ el.click(); return 1; } }catch(e){}
        }
        return 0;
      });

      await waitForSelectorPoll(page, 'a[href^="tel:"], .property-detail-popup .item.phone', 1000, 50);
      await sleep(50);
    }catch(e){  }

    // collect phones, prioritizing phones discovered immediately after clicks
    let phones = [];
    try{
      if(typeof phonesFromClicks !== 'undefined' && phonesFromClicks && phonesFromClicks.length){
        phones = phonesFromClicks.slice();

      } else {
        const popupEl = await page.$('.property-detail-popup');
        if(popupEl){

          const anchors = await page.$$eval('.property-detail-popup a[href^="tel:"]', els => els.map(a=> (a.getAttribute('href')||'').replace(/^tel:\s*/i,'').trim()));
          const popup = await page.$$eval('.property-detail-popup .item.phone', els => els.map(e=> (e.innerText||e.textContent||'').trim()));
          phones = [...(anchors||[]).filter(Boolean), ...(popup||[]).filter(Boolean)];
        } else {
   
          const anchors = await page.$$eval('a[href^="tel:"]', els => els.map(a=> (a.getAttribute('href')||'').replace(/^tel:\s*/i,'').trim()));
          const direct = await page.$$eval('.item.phone', els => els.map(e=> (e.innerText||e.textContent||'').trim()));
          phones = [...(anchors||[]).filter(Boolean), ...(direct||[]).filter(Boolean)];
        }

      }
    }catch(e){ phones = phones || []; console.log('  [debug] phone collection failed', e && e.message); }

    // normalize, dedupe (preserve order), and filter plausible phones
    try{
      const seen = new Set(); const normalized = [];
      for(let p of phones){
        if(!p) continue;
        let s = p.toString().replace(/^tel:\s*/i,'').trim();
        const hasPlus = s.startsWith('+');
        s = s.replace(/[\s\-()\.]+/g,'');
        if(!hasPlus) s = s.replace(/^0+(?=\d)/,'');
        if(!s) continue;
        const digits = (s.match(/\d/g)||[]).length;
        if(digits < 6 || digits > 13) continue; // prefer plausible phone lengths
        if(!seen.has(s)){ seen.add(s); normalized.push(s); }
      }
      phones = normalized;
      console.log('  [debug] normalized phones:', phones);
    }catch(e){ phones = phones || []; }

    // Final fallback: check explicit tel anchors inside contact-links or popup only (no regex)
    try{
      if(!phones || phones.length === 0){
        const anchors = await page.$$eval('.contact-links a[href^="tel:"], .property-detail-popup a[href^="tel:"]', els => els.map(a=> (a.getAttribute('href')||'').replace(/^tel:\s*/i,'').trim()));
        phones = Array.from(new Set((anchors||[]).filter(Boolean)));
 
      }
    }catch(e){}

    // emails: collect and dedupe (from contact-links)
    const emails = await page.$$eval('.contact-links a[href^="mailto:"]', els => els.map(a=>a.getAttribute('href').replace(/^mailto:\s*/i,'').trim())).catch(()=>[]);
    const emailSet = Array.from(new Set((emails||[])));

    if(phones.length) result.phone = phones.join(' | ');
    if(emailSet.length) result.email = emailSet.join(' | ');

    result.status = 'ok';
  }catch(err){
    result.status = 'error';
    result._error = (err && err.message) ? err.message : String(err);
  }
  return result;
}

// Helper: process urls with a pool of workers reusing pages to reduce overhead
async function processQueueWithPool(browser, urls, handler, concurrency){
  let idx = 0;
  const workers = new Array(Math.max(1, concurrency)).fill(0).map(async ()=>{
    const page = await browser.newPage();
    page.setDefaultNavigationTimeout(0);
    page.setDefaultTimeout(0);
    try{
      await page.setRequestInterception(true);
      page.on('request', req => {
        const r = req.resourceType();
        if(r === 'image' || r === 'stylesheet' || r === 'font' || r === 'media') return req.abort();
        req.continue();
      });
    }catch(e){}
    while(true){
      const i = idx++;
      if(i >= urls.length) break;
      const url = urls[i];
      try{ await handler(page, url, i); }catch(e){ console.error('worker error', e && e.message); }
    }
    try{ await page.close(); }catch(e){}
  });
  await Promise.all(workers);
}

function gatherUrlsFromFileOrArg(arg){
  if(arg){
    if(/^https?:\/\//.test(arg)) return [arg];
    if(fs.existsSync(arg)){
      const txt = fs.readFileSync(arg,'utf8');
      const urls = Array.from(new Set((txt.match(/https?:\/\/[^\s;\r\n,]+/g)||[])));
      return urls;
    }
  }
  if(fs.existsSync(DEFAULT_INPUT)){
    const txt = fs.readFileSync(DEFAULT_INPUT,'utf8');
    const urls = Array.from(new Set((txt.match(/https?:\/\/[^\s;\r\n,]+/g)||[])));
    return urls;
  }
  return [];
}

async function main(){
  const argv = process.argv.slice(2);
  const input = argv[0];
  // parse limit from args: --limit N or --limit=N (default: unlimited)
  let limit = Infinity;
  // parse start from args: --start N or --start=N (1-based, default: 1)
  let startIdx = 1;
  for(let i=0;i<argv.length;i++){
    const a = argv[i];
    if(a === '--limit' && argv[i+1]){ limit = parseInt(argv[i+1],10) || limit; }
    else if(a.startsWith('--limit=')){ limit = parseInt(a.split('=')[1],10) || limit; }
    if(a === '--start' && argv[i+1]){ startIdx = parseInt(argv[i+1],10) || startIdx; }
    else if(a.startsWith('--start=')){ startIdx = parseInt(a.split('=')[1],10) || startIdx; }
  }

  const perCategory = argv.includes('--per-category');

  if(perCategory){
    const candidatesDir = path.join(__dirname,'files');
    // Only process candidate files for the allowed category slugs
    const ALLOWED = ['chaty-chalupy','sruby-roubenky','glamping','apartmany','penziony','kempy','vinne-sklepy','farmy-statky'];
    const candidateFiles = fs.readdirSync(candidatesDir).filter(f=>{
      if(!f.startsWith('e_chalupy_candidates_') || !f.endsWith('.csv') || f === 'e_chalupy_candidates_all.csv') return false;
      const slug = f.replace('e_chalupy_candidates_','').replace('.csv','');
      return ALLOWED.includes(slug);
    });
    if(candidateFiles.length === 0){
      console.error('No candidate files found in', candidatesDir);
      process.exit(1);
    }
    const masterHeader = 'Name;Phone;Email;Category\n';
    // prepare master dedupe set (preserve existing master if present) using Name+Phone key
    let masterSeen = new Set();
    if(!fs.existsSync(MASTER_OUT)){
      fs.writeFileSync(MASTER_OUT, masterHeader, 'utf8');
    } else {
      try{
        const existing = fs.readFileSync(MASTER_OUT, 'utf8').split(/\r?\n/).slice(1);
        for(const l of existing){ if(!l) continue; const parts = l.split(';'); const name = (parts[0]||'').trim(); const phone = (parts[1]||'').trim(); if(name||phone) masterSeen.add(`${name}|||${phone}`); }
      }catch(e){}
    }

    const browser = await puppeteer.launch({ headless: true, args:['--no-sandbox','--disable-setuid-sandbox'] });
    let foundCount = 0;

    for(const fname of candidateFiles){
      const cat = fname.replace('e_chalupy_candidates_','').replace('.csv','');
      const inPath = path.join(candidatesDir, fname);
      let urls = gatherUrlsFromFileOrArg(inPath);
      // apply 1-based start index
      try{ const s = Math.max(1, parseInt(startIdx,10)||1); urls = urls.slice(Math.max(0, s-1)); }catch(e){}
      const catSlugSanitized = (cat||'cat').replace(/[\/\?=&]/g,'_').replace(/-/g,'_');
      const outPath = path.join(candidatesDir, `echalupy_${catSlugSanitized}.csv`);
      ensureCsvHeader(outPath, 'Name;Phone;Email\n');
      // build per-category seen set to avoid writing duplicate Name+Phone rows
      let perCategorySeen = new Set();
      try{
        if(fs.existsSync(outPath)){
          const existing = fs.readFileSync(outPath, 'utf8').split(/\r?\n/).slice(1);
          for(const l of existing){ if(!l) continue; const parts = l.split(';'); const name=(parts[0]||'').trim(); const phone=(parts[1]||'').trim(); if(name||phone) perCategorySeen.add(`${name}|||${phone}`); }
        }
      }catch(e){}
      console.log('Processing category', cat, '- urls:', urls.length);

      await processQueueWithPool(browser, urls, async (page, url, i) => {
        console.log('  Fetching', url);
        const res = await extractFromPage(page, url);
        const perLine = `${(res.name||'').replace(/;/g,',')};${(res.phone||'').replace(/;/g,',')};${(res.email||'').replace(/;/g,',')}\n`;
        try{
          const perKey = `${(res.name||'').toString().trim()}|||${(res.phone||'').toString().trim()}`;
          if(!perCategorySeen.has(perKey)){
            fs.appendFileSync(outPath, perLine, 'utf8');
            perCategorySeen.add(perKey);
          } else {
            console.log('  [debug] skipping duplicate per-category entry for', perKey);
          }
        }catch(e){ fs.appendFileSync(outPath, perLine, 'utf8'); }
        // append to master only if Name+Phone not already present
        try{
          const masterKey = `${(res.name||'').toString().trim()}|||${(res.phone||'').toString().trim()}`;
          if(!masterSeen.has(masterKey)){
            const masterLine = `${(res.name||'').replace(/;/g,',')};${(res.phone||'').replace(/;/g,',')};${(res.email||'').replace(/;/g,',')};${cat}\n`;
            fs.appendFileSync(MASTER_OUT, masterLine, 'utf8');
            masterSeen.add(masterKey);
          }
        }catch(e){}
        await sleep(50);
        if((res.phone && res.phone.trim()) || (res.email && res.email.trim())){
          foundCount++;
          if(isFinite(limit)) console.log('  Found contacts:', foundCount, `/${limit}`);
          else console.log('  Found contacts:', foundCount);
        }
      }, CONCURRENCY);
    }

    await browser.close();
    console.log('Wrote per-category files and master:', MASTER_OUT);
    return;
  }

  // single-file mode
  const urls = gatherUrlsFromFileOrArg(input);
  if(!urls || urls.length===0){
    console.error('No URLs found. Pass a URL or a file path, or ensure', DEFAULT_INPUT, 'exists.');
    process.exit(1);
  }

  // apply 1-based start index for single-file mode
  try{ const s = Math.max(1, parseInt(startIdx,10)||1); urls = urls.slice(Math.max(0, s-1)); }catch(e){}

  // Determine output filename and header. If input looks like a candidates file
  // with a category slug (e_chalupy_candidates_<slug>.csv) then write a per-category
  // CSV named `echalupy_<slug>.csv` and include Category column; also append to master.
  let outPathSingle = OUT_FILE;
  let outHeader = 'Name;Phone;Email\n';
  let inferredCategory = '';
  try{
    if (input && typeof input === 'string'){
      const bn = path.basename(input);
      const m = bn.match(/^e_chalupy_candidates_(.+)\.csv$/);
      if (m){
        inferredCategory = m[1].replace(/[\/?=&]/g,'_').replace(/-/g,'_');
        outPathSingle = path.join(__dirname,'files', `echalupy_${inferredCategory}.csv`);
        outHeader = 'Name;Phone;Email\n';
      }
    }
  }catch(e){}

  const browser = await puppeteer.launch({ headless: true, args:['--no-sandbox','--disable-setuid-sandbox'] });
  ensureCsvHeader(outPathSingle, outHeader);
  // ensure master file header exists and build masterSeen by Name+Phone
  let masterSeen = new Set();
  try{
    if(!fs.existsSync(MASTER_OUT)) fs.writeFileSync(MASTER_OUT, 'Name;Phone;Email;Category\n', 'utf8');
    else{
      const existing = fs.readFileSync(MASTER_OUT, 'utf8').split(/\r?\n/).slice(1);
      for(const l of existing){ if(!l) continue; const parts = l.split(';'); const name=(parts[0]||'').trim(); const phone=(parts[1]||'').trim(); if(name||phone) masterSeen.add(`${name}|||${phone}`); }
    }
  }catch(e){}

  let foundCount = 0;
  await processQueueWithPool(browser, urls, async (page, url, i) => {
    console.log('Fetching', url);
    const res = await extractFromPage(page, url);
    const perLine = `${(res.name||'').replace(/;/g,',')};${(res.phone||'').replace(/;/g,',')};${(res.email||'').replace(/;/g,',')}\n`;
    fs.appendFileSync(outPathSingle, perLine, 'utf8');
    // append to master (include inferred category if available), dedupe by Name+Phone
    try{
      const masterKey = `${(res.name||'').toString().trim()}|||${(res.phone||'').toString().trim()}`;
      if(!masterSeen.has(masterKey)){
        const masterLine = `${(res.name||'').replace(/;/g,',')};${(res.phone||'').replace(/;/g,',')};${(res.email||'').replace(/;/g,',')};${inferredCategory || ''}\n`;
        fs.appendFileSync(MASTER_OUT, masterLine, 'utf8');
        masterSeen.add(masterKey);
      }
    }catch(e){}
    await sleep(50);
    if((res.phone && res.phone.trim()) || (res.email && res.email.trim())){
      foundCount++;
      if(isFinite(limit)) console.log('Found contacts:', foundCount, `/${limit}`);
      else console.log('Found contacts:', foundCount);
    }
  }, CONCURRENCY);

  await browser.close();
  console.log('Wrote', outPathSingle);
}

if(require.main === module){
  main().catch(e=>{ console.error(e); process.exit(1); });
}
