<?php
// Simple Dokempu detail scraper
// Usage: php dokempu_detail_scraper.php --url="https://..." [--out=path]

ini_set('user_agent','Mozilla/5.0 (compatible; DokempuScraper/1.0)');

$opts = getopt('', ['url:', 'in:', 'sitemap:', 'out:', 'start::', 'limit::', 'delay-min::', 'delay-max::', 'retry::', 'force']);

$outDir = __DIR__ . '/files';
if (!is_dir($outDir)) @mkdir($outDir, 0755, true);
$outFile = isset($opts['out']) ? $opts['out'] : $outDir . '/dokempu_details.csv';

function usage() {
    global $argv;
    echo "Usage:\n  php {$argv[0]} --url=URL  or  --in=file_with_urls\nOptions: --out=path --start=N --limit=M --delay-min=ms --delay-max=ms\n";
    exit(1);
}

if (!isset($opts['url']) && !isset($opts['in']) && !isset($opts['sitemap'])) usage();

// collect URLs from --url, --in (file) or --sitemap (XML)
$urls = [];
if (isset($opts['url'])) $urls[] = $opts['url'];
if (isset($opts['in'])) {
    $lines = @file($opts['in'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) { echo "Cannot read input file {$opts['in']}\n"; exit(1); }
    foreach ($lines as $l) {
        $l = trim($l);
        if ($l === '') continue;
        $urls[] = $l;
    }
}

// sitemap support: fetch XML and extract <loc> entries
if (isset($opts['sitemap'])) {
    echo "Fetching sitemap: {$opts['sitemap']}\n";
    list($sxml,$sinfo) = curl_get($opts['sitemap']);
    if ($sxml !== false && $sxml !== null) {
        // simple extraction of <loc>
        if (preg_match_all('#<loc>([^<]+)</loc>#i', $sxml, $m)) {
            foreach ($m[1] as $u) $urls[] = trim($u);
        }
    } else {
        echo "  Failed to fetch sitemap {$opts['sitemap']}\n";
    }
}

$start = isset($opts['start']) ? (int)$opts['start'] : 0;
$limit = isset($opts['limit']) ? (int)$opts['limit'] : 0;
$delayMin = isset($opts['delay-min']) ? (int)$opts['delay-min'] : 200;
$delayMax = isset($opts['delay-max']) ? (int)$opts['delay-max'] : 800;
$maxRetries = isset($opts['retry']) ? (int)$opts['retry'] : 3;

if ($maxRetries < 1) $maxRetries = 1;

// persistent curl handle
function curl_get($url) {
    static $ch = null;
    if ($ch === null) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, ini_get('user_agent'));
    }
    curl_setopt($ch, CURLOPT_URL, $url);
    $html = curl_exec($ch);
    $info = curl_getinfo($ch);
    return [$html, $info];
}

function curl_get_retry($url, $maxAttempts=3) {
    $attempt = 0;
    $wait = 500; // ms
    while ($attempt < $maxAttempts) {
        $attempt++;
        list($html,$info) = curl_get($url);
        $code = isset($info['http_code']) ? (int)$info['http_code'] : 0;
        if ($html !== false && ($code === 200 || $code === 301 || $code === 302)) {
            return [$html,$info];
        }
        // wait with exponential backoff
        usleep($wait * 1000);
        $wait *= 2;
        echo "  retry {$attempt}/{$maxAttempts} for $url (http={$code})\n";
    }
    return [false, ['http_code'=>$code]];
}

function extract_from_html($html) {
    $res = ['name'=>'','phone'=>'','email'=>''];
    if (!$html) return $res;
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    $xpath = new DOMXPath($dom);

    // Name: try h1 then title
    $h1 = $xpath->query('//h1');
    if ($h1->length) $res['name'] = trim($h1->item(0)->textContent);
    if ($res['name'] === '') {
        $title = $xpath->query('//title');
        if ($title->length) $res['name'] = trim($title->item(0)->textContent);
    }

    // Email: mailto links
    $mail = $xpath->query("//a[starts-with(@href,'mailto:')]");
    if ($mail->length) {
        $href = $mail->item(0)->getAttribute('href');
        $res['email'] = preg_replace('/^mailto:/i','',$href);
    } else {
        // fallback: regex in HTML (use # delimiter to avoid escaping issues)
        if (preg_match('#[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}#i', $html, $m)) $res['email'] = $m[0];
    }

    // Phone: common patterns (prefer +420)
    // Search visible text blocks first (drawer area)
    $text = $dom->textContent;
    // Normalize non-breaking spaces
    $text = str_replace("\xc2\xa0", ' ', $text);
    // Try find +420 or long digit sequences
    if (preg_match('#(\+420[ \t\-\/\.\(\)]*\d{3}[ \t\-\/\.\)]*\d{3}[ \t\-\/\.\)]*\d{3})#i', $text, $m)) {
        $res['phone'] = preg_replace('#[^+0-9]#','',$m[1]);
    } elseif (preg_match('#(\+?\d[0-9 \-\/\.\(\)]{6,}\d)#', $text, $m)) {
        $p = preg_replace('#[^+0-9]#','',$m[1]);
        if (strlen($p) >= 7) $res['phone'] = $p;
    }

    return $res;
}

// handle output file, support --force to overwrite
$force = isset($opts['force']);
if ($force && file_exists($outFile)) unlink($outFile);
$outHandle = fopen($outFile, 'a');
if ($outHandle === false) { echo "Cannot open out file $outFile\n"; exit(1); }
// write header if empty
if (filesize($outFile) === 0) {
    fwrite($outHandle, "URL;Name;Phone;Email\n");
}

// load seen URLs from existing out file to avoid duplicates
$seen = [];
if (file_exists($outFile)) {
    $f = fopen($outFile, 'r');
    if ($f) {
        // skip header
        $h = fgets($f);
        while (($line = fgets($f)) !== false) {
            $parts = explode(';', $line);
            $u = trim($parts[0]);
            if ($u !== '') $seen[$u] = true;
        }
        fclose($f);
    }
}

$total = count($urls);
$processed = 0; $skipped = 0; $failed = 0;
for ($i=0;$i<$total;$i++) {
    $idx = $i;
    if ($idx < $start) continue;
    if ($limit>0 && ($idx-$start) >= $limit) break;
    $url = $urls[$i];
    if (isset($seen[$url])) { echo "[".($i+1)."/$total] Skipping already-seen: $url\n"; $skipped++; continue; }
    echo "[".($i+1)."/$total] Fetching: $url\n";
    $t0 = microtime(true);
    $t_fetch_start = microtime(true);
    list($html,$info) = curl_get_retry($url, $maxRetries);
    $t_fetch = (microtime(true) - $t_fetch_start) * 1000.0;
    if ($html === false) { echo "  FAILED\n"; $failed++; continue; }
    $t_parse_start = microtime(true);
    $fields = extract_from_html($html);
    $t_parse = (microtime(true) - $t_parse_start) * 1000.0;
    $t_total = (microtime(true) - $t0) * 1000.0;
    $line = sprintf("%s;%s;%s;%s\n",
        $url,
        str_replace(["\"","\n","\r",";"], ['','','',''], $fields['name']),
        $fields['phone'],
        $fields['email']
    );
    fwrite($outHandle, $line);
    $seen[$url] = true;
    $processed++;
    $http = isset($info['http_code']) ? $info['http_code'] : 'N/A';
    $effurl = isset($info['url']) ? $info['url'] : $url;
    echo sprintf("  => Name='%s' Phone='%s' Email='%s' (http=%s)\n",
        $fields['name'], $fields['phone'], $fields['email'], $http
    );
    echo sprintf("     timings: fetch=%.1fms parse=%.1fms total=%.1fms effective_url=%s\n",
        $t_fetch, $t_parse, $t_total, $effurl
    );

    // polite delay
    $d = ($delayMax>=$delayMin) ? rand($delayMin, $delayMax) : $delayMin;
    usleep($d * 1000);
}

// summary
echo "\nSummary: processed={$processed} skipped={$skipped} failed={$failed} total_urls={$total}\n";

fclose($outHandle);
echo "Done. Results appended to $outFile\n";
