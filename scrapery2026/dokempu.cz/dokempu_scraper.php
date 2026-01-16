<?php
// Complete Dokempu scraper
// Features:
// - enumerate detail URLs from --sitemap (default) or --in/--url
// - scrape each detail for Name, Phone, Email
// - dedupe/resume by existing output CSV
// - retry/backoff, --force to overwrite, timing & verbose logs

ini_set('user_agent','Mozilla/5.0 (compatible; DokempuScraper/1.0)');

$opts = getopt('', ['url:', 'in:', 'sitemap::', 'out:', 'start::', 'limit::', 'delay-min::', 'delay-max::', 'retry::', 'force', 'verbose']);

function usage() {
    global $argv;
    echo "Usage:\n  php {$argv[0]} --sitemap=URL|--in=file|--url=URL [options]\nOptions:\n  --out=path      Output CSV (default ./files/dokempu_all.csv)\n  --limit=N       Limit processed URLs\n  --start=N       Skip first N URLs\n  --delay-min=ms  Min delay between requests (default 200)\n  --delay-max=ms  Max delay between requests (default 800)\n  --retry=N       Retry attempts (default 3)\n  --force         Overwrite output file\n  --verbose       More logs\"\n";
    exit(1);
}

// default sitemap if none provided
$defaultSitemap = 'https://www.dokempu.cz/sitemap_Campsites_1.xml';

if (!isset($opts['url']) && !isset($opts['in']) && !isset($opts['sitemap'])) {
    // fall back to default sitemap
    $opts['sitemap'] = $defaultSitemap;
}

$outDir = __DIR__ . '/files';
if (!is_dir($outDir)) @mkdir($outDir, 0755, true);
$outFile = isset($opts['out']) ? $opts['out'] : $outDir . '/dokempu_all.csv';

$start = isset($opts['start']) ? (int)$opts['start'] : 0;
$limit = isset($opts['limit']) ? (int)$opts['limit'] : 0;
$delayMin = isset($opts['delay-min']) ? (int)$opts['delay-min'] : 200;
$delayMax = isset($opts['delay-max']) ? (int)$opts['delay-max'] : 800;
$maxRetries = isset($opts['retry']) ? (int)$opts['retry'] : 3;
$force = isset($opts['force']);
$verbose = isset($opts['verbose']);

if ($maxRetries < 1) $maxRetries = 1;

// persistent curl
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
    $attempt = 0; $wait = 500; $code = 0;
    while ($attempt < $maxAttempts) {
        $attempt++;
        list($html,$info) = curl_get($url);
        $code = isset($info['http_code']) ? (int)$info['http_code'] : 0;
        if ($html !== false && ($code === 200 || $code === 301 || $code === 302)) return [$html,$info];
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

    $h1 = $xpath->query('//h1');
    if ($h1->length) $res['name'] = trim($h1->item(0)->textContent);
    if ($res['name'] === '') {
        $title = $xpath->query('//title');
        if ($title->length) $res['name'] = trim($title->item(0)->textContent);
    }

    $mail = $xpath->query("//a[starts-with(@href,'mailto:')]");
    if ($mail->length) {
        $href = $mail->item(0)->getAttribute('href');
        $res['email'] = preg_replace('/^mailto:/i','',$href);
    } else {
        if (preg_match('#[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}#i', $html, $m)) $res['email'] = $m[0];
    }

    $text = $dom->textContent;
    $text = str_replace("\xc2\xa0", ' ', $text);
    if (preg_match('#(\+420[ \t\-\/\.\(\)]*\d{3}[ \t\-\/\.\)]*\d{3}[ \t\-\/\.\)]*\d{3})#i', $text, $m)) {
        $res['phone'] = preg_replace('#[^+0-9]#','',$m[1]);
    } elseif (preg_match('#(\+?\d[0-9 \-\/\.\(\)]{6,}\d)#', $text, $m)) {
        $p = preg_replace('#[^+0-9]#','',$m[1]); if (strlen($p) >= 7) $res['phone'] = $p;
    }
    return $res;
}

// collect URLs
$urls = [];
if (isset($opts['url'])) $urls[] = $opts['url'];
if (isset($opts['in'])) {
    $lines = @file($opts['in'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) { echo "Cannot read input file {$opts['in']}\n"; exit(1); }
    foreach ($lines as $l) { $l = trim($l); if ($l==='') continue; $urls[] = $l; }
}
if (isset($opts['sitemap']) && $opts['sitemap'] !== '') {
    $surl = $opts['sitemap'];
    echo "Fetching sitemap: {$surl}\n";
    list($sxml,$sinfo) = curl_get_retry($surl, $maxRetries);
    if ($sxml !== false && preg_match_all('#<loc>([^<]+)</loc>#i', $sxml, $m)) {
        foreach ($m[1] as $u) $urls[] = trim($u);
    }
} elseif (empty($urls)) {
    // if still empty, try default sitemap
    echo "Fetching default sitemap: {$defaultSitemap}\n";
    list($sxml,$sinfo) = curl_get_retry($defaultSitemap, $maxRetries);
    if ($sxml !== false && preg_match_all('#<loc>([^<]+)</loc>#i', $sxml, $m)) foreach ($m[1] as $u) $urls[] = trim($u);
}

if (count($urls) === 0) { echo "No URLs to process.\n"; exit(0); }

// prepare output
if ($force && file_exists($outFile)) unlink($outFile);
$outHandle = fopen($outFile, 'a'); if ($outHandle === false) { echo "Cannot open out file $outFile\n"; exit(1); }
if (filesize($outFile) === 0) fwrite($outHandle, "Name;Phone;Email\n");

// load seen composite keys from existing CSV to avoid duplicates
$seen = [];
if (file_exists($outFile)) {
    $f = fopen($outFile,'r');
    if ($f) {
        // skip header
        fgets($f);
        while (($line=fgets($f))!==false) {
            $parts = array_map('trim', explode(';', $line));
            $name = '';
            $phone = '';
            $email = '';
            if (count($parts) >= 3) {
                // either Name;Phone;Email OR URL;Name;Phone;Email (legacy)
                if (filter_var($parts[0], FILTER_VALIDATE_URL) && count($parts) >= 4) {
                    $name = $parts[1]; $phone = $parts[2]; $email = $parts[3];
                } else {
                    $name = $parts[0]; $phone = $parts[1]; $email = $parts[2];
                }
            }
            $key = strtolower(trim($name) . '|' . preg_replace('/[^+0-9]/','', $phone) . '|' . strtolower(trim($email)));
            if ($key !== '||') $seen[$key] = true;
        }
        fclose($f);
    }
}

$total = count($urls);
$processed=0; $skipped=0; $failed=0;
for ($i=0;$i<$total;$i++) {
    if ($i < $start) continue;
    if ($limit>0 && ($i-$start) >= $limit) break;
    $url = $urls[$i];
    echo "[".($i+1)."/$total] Fetching: $url\n";

    $t0 = microtime(true);
    $t_fetch_start = microtime(true);
    list($html,$info) = curl_get_retry($url, $maxRetries);
    $t_fetch = (microtime(true)-$t_fetch_start)*1000.0;
    if ($html === false) { echo "  FAILED\n"; $failed++; continue; }
    $t_parse_start = microtime(true);
    $fields = extract_from_html($html);
    $t_parse = (microtime(true)-$t_parse_start)*1000.0;
    $t_total = (microtime(true)-$t0)*1000.0;

    $nameClean = str_replace(["\"","\n","\r",";"],['','','',''],$fields['name']);
    $phoneClean = preg_replace('/[^+0-9]/','',$fields['phone']);
    $emailClean = strtolower(trim($fields['email']));
    $key = strtolower(trim($nameClean) . '|' . $phoneClean . '|' . $emailClean);
    if (isset($seen[$key])) {
        echo "  => Duplicate (by name/phone/email), skipping write.\n";
        $skipped++;
    } else {
        $line = sprintf("%s;%s;%s\n",
            $nameClean,
            $phoneClean,
            $emailClean
        );
        fwrite($outHandle, $line);
        $seen[$key] = true;
        $processed++;
    }
    $http = isset($info['http_code']) ? $info['http_code'] : 'N/A';
    $effurl = isset($info['url']) ? $info['url'] : $url;
    echo sprintf("  => Name='%s' Phone='%s' Email='%s' (http=%s)\n", $fields['name'],$fields['phone'],$fields['email'],$http);
    echo sprintf("     timings: fetch=%.1fms parse=%.1fms total=%.1fms effective_url=%s\n", $t_fetch,$t_parse,$t_total,$effurl);

    $d = ($delayMax>=$delayMin)?rand($delayMin,$delayMax):$delayMin; usleep($d*1000);
}

fclose($outHandle);
echo "\nSummary: processed={$processed} skipped={$skipped} failed={$failed} total_urls={$total}\n";

echo "All done. Output: {$outFile}\n";
