const fs = require('fs');
const path = require('path');
const p = path.join(__dirname,'..','scrapery2026','e-chalupy','files','e_chalupy_candidates_chaty-chalupy.csv');
const startIdx = parseInt(process.argv[2]||'1',10);
if(!fs.existsSync(p)){ console.error('not found',p); process.exit(2); }
const txt = fs.readFileSync(p,'utf8');
const lines = txt.split(/\r?\n/);
const urls = [];
for(const line of lines){ if(!line) continue; const m = line.match(/https?:\/\/[^\s;\r\n,]+/); if(m) urls.push(m[0]); }
console.log('total urls', urls.length);
console.log('startIdx', startIdx);
console.log('urls slice length', urls.slice(Math.max(0,startIdx-1)).length);
console.log('first two at start:', urls.slice(Math.max(0,startIdx-1), Math.max(0,startIdx-1)+2));
