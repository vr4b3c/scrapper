const puppeteer = require('puppeteer');

async function run(url){
  const browser = await puppeteer.launch({ headless: true, args:['--no-sandbox','--disable-setuid-sandbox'] });
  const page = await browser.newPage();
  await page.setRequestInterception(false);
  function sleep(ms){ return new Promise(r=>setTimeout(r,ms)); }
  page.on('response', async res => {
    try{
      const req = res.request();
      const rType = req.resourceType();
      const u = res.url();
      if(rType === 'xhr' || /api|contact|phone|tel|kontakt/i.test(u)){
        let ct = (res.headers()['content-type']||'').toLowerCase();
        let txt = '';
        try{
          if(ct.includes('application/json')) txt = JSON.stringify(await res.json());
          else txt = await res.text();
        }catch(e){ txt = '<non-text response>'; }
        if(/\d{6,}|phone|tel|telefon|kontakt/i.test(txt) || /phone|tel|kontakt|contact/i.test(u)){
          console.log('--- RESPONSE MATCH ---');
          console.log('url:', u);
          console.log('resourceType:', rType, 'content-type:', ct);
          console.log('body (truncated 2000 chars):');
          console.log(txt.slice(0,2000));
          console.log('--- END ---\n');
        }
      }
    }catch(e){}
  });

  await page.goto(url, { waitUntil: 'networkidle2', timeout: 30000 });
  console.log('PAGE:', url);
  try{
    // attempt clicking known contact buttons
    const selectors = ['.property-detail-order .btn2.btn-contact', '.btn2.btn-contact', 'button.item.contact', '.item.contact', 'a.show-phone'];
    for(const sel of selectors){
      try{
        const el = await page.$(sel);
        if(el){
          console.log('Clicking', sel);
          await el.click().catch(()=>{});
          await sleep(2000);
        }
      }catch(e){}
    }

    // also trigger any elements containing "telefon"
    await page.evaluate(()=>{
      const re = /zobrazit\s+telefon|telefon/i;
      const els = Array.from(document.querySelectorAll('a,button,span,div'));
      for(const el of els){ try{ if(el.innerText && re.test(el.innerText)){ el.click(); } }catch(e){} }
    });

    // wait a bit to capture XHRs
    await sleep(5000);
  }catch(e){ console.error(e && e.message); }

  await browser.close();
}

const url = process.argv[2];
if(!url){ console.error('Usage: node e-chalupy-capture-xhr.js <URL>'); process.exit(2); }
run(url).catch(e=>{ console.error(e); process.exit(1); });
