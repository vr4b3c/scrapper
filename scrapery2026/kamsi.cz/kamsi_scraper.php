<?php
// Simple detail extractor for kamsi.cz

function parse_args($argv) {
    $out = [];
    foreach ($argv as $a) {
        if (strpos($a,'--') !== 0) continue;
        $p = explode('=', $a, 2);
        $k = ltrim($p[0], '-');
        $v = isset($p[1]) ? $p[1] : true;
        $out[$k] = $v;
    }
    return $out;
}

function curl_get($url, &$http_code=null) {
    static $ch = null;
    if ($ch === null) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; kamsi-scraper/1.0)');
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    }
    curl_setopt($ch, CURLOPT_URL, $url);
    $body = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return $body;
}

function curl_get_retry($url, $attempts=3) {
    $wait = 200000; // 200ms
    for ($i=0;$i<$attempts;$i++) {
        $t0 = microtime(true);
        $body = curl_get($url, $http);
        $t1 = microtime(true);
        if ($http >= 200 && $http < 400 && strlen($body) > 50) {
            return [$body, $http, $t1-$t0];
        }
        usleep($wait);
        $wait *= 2;
    }
    return [false, $http ?? 0, 0];
}

function normalize_phone($s) {
    if (!$s) return '';
    $d = preg_replace('/[^0-9+]/','', $s);
    if (strlen($d) < 6) return '';
    return $d;
}

function extract_from_html($html, $url='') {
    $res = ['name'=>'', 'phone'=>'', 'email'=>''];
    if (!$html) return $res;
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    $xpath = new DOMXPath($dom);

    // Name / title: h1 or og:title
    $node = $xpath->query('//h1')->item(0);
    if ($node) $res['name'] = trim($node->textContent);
    if (!$res['name']) {
        $meta = $xpath->query('//meta[@property="og:title"]')->item(0);
        if ($meta) $res['name'] = trim($meta->getAttribute('content'));
    }

    // Email: mailto anchors first
    $emails = [];
    $nodes = $xpath->query('//a[starts-with(@href, "mailto:")]');
    foreach ($nodes as $n) {
        $href = $n->getAttribute('href');
        $parts = explode(':', $href, 2);
        if (isset($parts[1])) $emails[] = trim($parts[1]);
    }
    if (!$emails) {
        if (preg_match_all('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $html, $m)) {
            $emails = $m[0];
        }
    }
    $res['email'] = strtolower($emails[0] ?? '');

    // Phone: tel anchors first
    $phones = [];
    $nodes = $xpath->query('//a[starts-with(@href, "tel:")]');
    foreach ($nodes as $n) {
        $href = $n->getAttribute('href');
        $parts = explode(':', $href, 2);
        if (isset($parts[1])) $phones[] = normalize_phone($parts[1]);
    }
    if (!$phones) {
        if (preg_match_all('/\+?[0-9][0-9\-\s()]{6,}/', $html, $m)) {
            foreach ($m[0] as $p) $phones[] = normalize_phone($p);
        }
    }
    $res['phone'] = $phones[0] ?? '';

    return $res;
}

function write_csv_header_if_needed($out) {
    if (!file_exists($out) || filesize($out) === 0) {
        $dir = dirname($out);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents($out, "Name;Phone;Email\n", LOCK_EX);
    }
}

function load_seen($out) {
    $seen = [];
    if (!file_exists($out)) return $seen;
    $f = fopen($out,'r');
    if (!$f) return $seen;
    $hdr = fgetcsv($f, 0, ';');
    while (($row = fgetcsv($f, 0, ';')) !== false) {
        $name = strtolower(trim($row[0] ?? ''));
        $phone = preg_replace('/[^0-9+]/','', $row[1] ?? '');
        $email = strtolower(trim($row[2] ?? ''));
        $key = $name . '|' . $phone . '|' . $email;
        if ($key !== '||') $seen[$key] = true;
    }
    fclose($f);
    return $seen;
}

// --- main
$argv ?? [];
$opts = parse_args($argv ?? []);
$url = $opts['url'] ?? '';
$in = $opts['in'] ?? '';
$out = $opts['out'] ?? __DIR__ . '/files/kamsi_all.csv';
$start = intval($opts['start'] ?? 0);
$limit = intval($opts['limit'] ?? 0);
$delay_min = intval($opts['delay-min'] ?? 100);
$delay_max = intval($opts['delay-max'] ?? 300);
$retry = intval($opts['retry'] ?? 2);
$force = isset($opts['force']);
$verbose = isset($opts['verbose']);

$urls = [];
if ($url) $urls[] = $url;
if ($in && file_exists($in)) {
    $lines = file($in, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $l) $urls[] = trim($l);
}
if (!$urls) {
    echo "No --url or --in provided\n";
    exit(1);
}

write_csv_header_if_needed($out);
$seen = $force ? [] : load_seen($out);

$total = count($urls);
$processed = $skipped = $failed = 0;

for ($i=0;$i<$total;$i++) {
    if ($i < $start) continue;
    if ($limit && $processed >= $limit) break;
    $u = $urls[$i];
    $u = trim($u);
    if (!$u) continue;

    $key_preview = strtolower(trim(preg_replace('/\s+/', ' ', $u)));
    if ($verbose) echo "[${i}] url={$u}\n";

    // fetch
    $t0 = microtime(true);
    list($body, $http, $fetch_secs) = curl_get_retry($u, $retry);
    $t_fetch_end = microtime(true);
    if ($body === false) {
        echo "[{$i}] failed http={$http} url={$u}\n";
        $failed++;
        continue;
    }

    $t_parse0 = microtime(true);
    $ex = extract_from_html($body, $u);
    $t_parse1 = microtime(true);

    $name = trim($ex['name']);
    $phone = normalize_phone($ex['phone']);
    $email = trim(strtolower($ex['email']));

    $key = strtolower($name) . '|' . $phone . '|' . $email;
    if ($key && isset($seen[$key])) {
        $skipped++;
        if ($verbose) echo "[{$i}] skipped duplicate: {$name}\n";
    } else {
        $line = [$name, $phone, $email];
        $fp = fopen($out, 'a');
        fputcsv($fp, $line, ';');
        fclose($fp);
        $seen[$key] = true;
        $processed++;
        if ($verbose) echo "[{$i}] saved: {$name} | {$phone} | {$email}\n";
    }

    $t_end = microtime(true);
    if ($verbose) printf("[{$i}] times fetch=%.3f parse=%.3f total=%.3f\n", $fetch_secs, $t_parse1-$t_parse0, $t_end-$t0);

    // polite delay
    $ms = rand($delay_min, $delay_max);
    usleep($ms * 1000);
}

echo "done processed={$processed} skipped={$skipped} failed={$failed} total_urls={$total}\n";

?>