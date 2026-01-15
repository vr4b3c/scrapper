const fs = require('fs');
const path = require('path');
const puppeteer = require('puppeteer');

const DEFAULT_URL = 'https://www.e-chalupy.cz/ubytovani-orlicke-zahori-apartman-orlicke-hory-o21556';

function sleep(ms){ return new Promise(r=>setTimeout(r,ms)); }

async function waitForSelectorPoll(page, selector, timeoutMs=2000, interval=50){
  const start = Date.now();
  while (Date.now() - start < timeoutMs){
    try{ const el = await page.$(selector); if (el) return el; }catch(e){}
    await sleep(interval);
  }
  return null;
}

async function extractFromPage(page, url){
  const result = { url, name:'', phone:'', email:'', status:'error' };
  try{
    await page.goto(url, { waitUntil:'domcontentloaded', timeout:30000 });
    await waitForSelectorPoll(page, 'h1', 1000, 50);

    // try the specific contact-reveal flow used on e-chalupy:
    // click .property-detail-order .btn2.btn-contact -> wait for .property-detail-popup -> click .item.phone inside popup
    try{
      const contactBtn = await page.$('.property-detail-order .btn2.btn-contact');
      if(contactBtn){
        await contactBtn.click().catch(()=>{});
        await waitForSelectorPoll(page, '.property-detail-popup', 1500, 50);
        await sleep(50);
        const phoneButton = await page.$('.property-detail-popup .item.phone');
        if(phoneButton){
          await phoneButton.click().catch(()=>{});
          await sleep(50);
        }
      }
    }catch(e){/* ignore */}

    // fallback: try a few generic contact selectors if the above didn't reveal anything
    const contactSelectors = [
      '.btn2.btn-contact',
      'button.item.contact',
      '.contact-links .item.contact',
      'a[href^="mailto:"]',
      'a[href^="tel:"]'
    ];

    for(const sel of contactSelectors){
      try{
        const el = await page.$(sel);
        if(el){
          await el.click().catch(()=>{});
          await sleep(800);
        }
      }catch(e){/* ignore */}
    }

    // extract name
    const name = await page.$$eval('h1', els => els.map(e => e.innerText.trim()).filter(Boolean)[0] || document.title || '');
    result.name = name || '';

    // look for mailto/tel anchors
    const phones = await page.$$eval('a[href^="tel:"]', els => els.map(a=>a.getAttribute('href').replace(/^tel:\s*/i,'')));
    const emails = await page.$$eval('a[href^="mailto:"]', els => els.map(a=>a.getAttribute('href').replace(/^mailto:\s*/i,'')));

    // also search page text for phone-like patterns if anchors missing
    if(!phones || phones.length===0){
      const text = await page.evaluate(()=>document.body.innerText || '');
      const phoneRegex = /((?:\+|00)?[0-9][0-9\s\-()]{6,}[0-9])/g;
      const found = (text.match(phoneRegex)||[]).map(s=>s.trim());
      if(found.length) phones.push(...found);
    }

    if(phones && phones.length) result.phone = phones[0].replace(/\s+/g,' ');
    if(emails && emails.length) result.email = emails[0];

    // fallback: some contact links are buttons that open a small panel with items
    if(!result.phone){
      // check for elements containing tel: within attribute or data
      const telFromDom = await page.evaluate(()=>{
        const els = Array.from(document.querySelectorAll('*')).map(e=>({tag:e.tagName, href:e.getAttribute('href')||'', txt:e.innerText||''}));
        for(const e of els){
          if(e.href && e.href.startsWith('tel:')) return e.href.replace(/^tel:\s*/i,'');
        }
        return null;
      });
      if(telFromDom) result.phone = telFromDom;

      // also try to read phone from the popup's phone item text
      if(!result.phone){
        const popupPhone = await page.$$eval('.property-detail-popup .item.phone', els => els.map(e=>e.innerText.trim()).filter(Boolean)[0] || '').catch(()=>null);
        if(popupPhone) result.phone = popupPhone.replace(/\s+/g,' ');
      }
    }

    result.status = 'ok';
  }catch(err){
    result.status = 'error';
    result._error = (err && err.message) ? err.message : String(err);
  }
  return result;
}

async function main(){
  const argv = process.argv.slice(2);
  const url = argv[0] || DEFAULT_URL;

  const outDir = path.join(__dirname,'files');
  if(!fs.existsSync(outDir)) fs.mkdirSync(outDir, { recursive:true });
  const outFile = path.join(outDir,'e_chalupy_detail.csv');

  const browser = await puppeteer.launch({ headless: true, args:['--no-sandbox','--disable-setuid-sandbox'] });
  const page = await browser.newPage();
  page.setDefaultNavigationTimeout(45000);

  const res = await extractFromPage(page, url);

  await browser.close();

  // write CSV header if missing (explicit UTF-8)
  const header = 'Name;URL;Phone;Email;Status\n';
  if(!fs.existsSync(outFile)) fs.writeFileSync(outFile, header, 'utf8');
  const line = `${(res.name||'').replace(/;/g,',')};${res.url};${(res.phone||'').replace(/;/g,',')};${(res.email||'').replace(/;/g,',')};${res.status}\n`;
  fs.appendFileSync(outFile, line, 'utf8');

  console.log('Wrote', outFile);
  console.log(res);
}

if(require.main === module){
  main().catch(e=>{ console.error(e); process.exit(1); });
}
