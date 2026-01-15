const puppeteer = require('puppeteer');

async function sleep(ms){ return new Promise(r=>setTimeout(r,ms)); }

async function waitForPhoneReveal(page, timeoutMs=8000, interval=200){
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

(async ()=>{
  const url = process.argv[2];
  if(!url){ console.error('Usage: node e-chalupy-debug.js <url>'); process.exit(1); }
  const browser = await puppeteer.launch({ headless: true, args:['--no-sandbox','--disable-setuid-sandbox'] });
  const page = await browser.newPage();
  try{
    await page.setRequestInterception(true);
    page.on('request', req=>{
      const r = req.resourceType();
      if(r === 'image' || r === 'stylesheet' || r === 'font' || r === 'media') return req.abort();
      req.continue();
    });
  }catch(e){}

  await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 30000 });
  try{ await page.waitForSelector('h1', { timeout: 3000 }); }catch(e){}
  console.log('PAGE:', url);
  try{ const h = await page.$eval('h1', el=>el.innerText.trim()); console.log('Title:', h); }catch(e){}

  // try clicks similar to batch
  const selectorsToClick = ['.property-detail-order .btn2.btn-contact', '.btn2.btn-contact', 'button.item.contact', '.item.contact', 'a[onclick*="telefon"]', 'a:contains("Zobrazit")'];
  for(const sel of selectorsToClick){
    try{
      const el = await page.$(sel);
      if(el){
        console.log('Clicking', sel);
        await el.click().catch(()=>{});
        await sleep(500);
      }
    }catch(e){}
  }

  // also click elements containing "Zobrazit telefon"
  try{
    await page.evaluate(()=>{
      const re = /zobrazit\s+telefon/i;
      const els = Array.from(document.querySelectorAll('a,button,span,div'));
      for(const el of els){ try{ if(el.innerText && re.test(el.innerText)){ el.click(); } }catch(e){} }
    });
  }catch(e){}

  const revealed = await waitForPhoneReveal(page, 8000, 200);
  console.log('Phone reveal detected:', revealed);

  // dump tel anchors
  try{
    const anchors = await page.$$eval('a[href^="tel:"]', els => els.map(a=> ({href: a.getAttribute('href'), text: (a.innerText||a.textContent||'').trim()})));
    console.log('tel anchors:', JSON.stringify(anchors, null, 2));
  }catch(e){ console.log('tel anchors: error', e.message); }

  // dump popup HTML
  try{
    const popup = await page.$('.property-detail-popup');
    if(popup){ const html = await page.evaluate(el=>el.innerHTML, popup); console.log('property-detail-popup HTML:\n', html.slice(0,2000)); }
    else console.log('property-detail-popup: not found');
  }catch(e){ console.log('popup html error', e.message); }

  // dump any .item.phone texts
  try{
    const phones = await page.$$eval('.property-detail-popup .item.phone, .item.phone', els => els.map(e=> (e.innerText||e.textContent||'').trim()));
    console.log('.item.phone texts:', JSON.stringify(phones));
  }catch(e){ console.log('.item.phone texts: error', e.message); }

  // dump any elements containing 'telefon' and their text
  try{
    const telEls = await page.$$eval('a,button,span,div', els => els.map(e=> (e.innerText||e.textContent||'').trim()).filter(t=>/telefon/i.test(t)).slice(0,20));
    console.log('Elements containing "telefon":', JSON.stringify(telEls, null, 2));
  }catch(e){ console.log('telefon elements: error', e.message); }

  await browser.close();
})().catch(e=>{ console.error(e); process.exit(1); });
