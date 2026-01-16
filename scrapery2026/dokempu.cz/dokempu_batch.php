<?php
// dokempu_batch.php
// Process per-category URL list files and extract details into per-category CSVs.

if (php_sapi_name() !== 'cli') {
    echo "Run from CLI only\n";
    exit(1);
}

$opts = getopt('', ['indir::', 'outdir::', 'delay-min::', 'delay-max::', 'retry::', 'force', 'verbose']);
$indir = $opts['indir'] ?? sys_get_temp_dir() . '/dokempu_cat_urls';
$outdir = $opts['outdir'] ?? 'scrapery2026/dokempu.cz/files';
$delayMin = intval($opts['delay-min'] ?? 100);
$delayMax = intval($opts['delay-max'] ?? 300);
$retry = intval($opts['retry'] ?? 2);
$force = isset($opts['force']);
$verbose = isset($opts['verbose']);

if (!is_dir($indir)) {
    echo "Input directory not found: $indir\n";
    echo "Usage: php dokempu_batch.php --indir=/path/to/urls --outdir=/path/to/csvs [--delay-min=100] [--delay-max=300] [--retry=2] [--force] [--verbose]\n";
    exit(1);
}

if (!is_dir($outdir)) mkdir($outdir, 0755, true);

function fetch_html($url, $retry = 2) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible)');
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    while ($retry-- >= 0) {
        curl_setopt($ch, CURLOPT_URL, $url);
        $res = curl_exec($ch);
        if ($res !== false) return $res;
        sleep(1);
    }
    return false;
}

function extract_detail($html) {
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    $name = '';
    // try common selectors
    $h = $xpath->query('//h1');
    if ($h->length) $name = trim($h->item(0)->textContent);
    // phone and email
    $phone = '';
    $email = '';
    // Prefer mailto/tel links inside the detail/context near the H1 (owner contact usually there)
    $disallowed = ["partners@dokempu.cz", "undefined", "null", "n/a"];
    $contextNode = null;
    if ($h->length) {
        $contextNode = $h->item(0)->parentNode;
    }
    // try to find mailto/tel within context first
    if ($contextNode) {
        $mailNodes = $xpath->query('.//a[starts-with(@href, "mailto:")]', $contextNode);
        foreach ($mailNodes as $a) {
            $cand = substr($a->getAttribute('href'), 7);
            $cand = trim($cand);
            if ($cand === '') continue;
            if (in_array(strtolower($cand), $disallowed)) continue;
            $email = $cand;
            break;
        }
        $telNodes = $xpath->query('.//a[starts-with(@href, "tel:")]', $contextNode);
        foreach ($telNodes as $a) {
            $cand = substr($a->getAttribute('href'), 4);
            if (trim($cand) !== '') { $phone = trim($cand); break; }
        }
    }
    // If not found in context, scan the whole page but prefer non-disallowed addresses
    if (!$email) {
        foreach ($xpath->query('//a[starts-with(@href, "mailto:")]') as $a) {
            $cand = trim(substr($a->getAttribute('href'), 7));
            if ($cand === '') continue;
            if (in_array(strtolower($cand), $disallowed)) continue;
            $email = $cand;
            break;
        }
    }
    if (!$phone) {
        foreach ($xpath->query('//a[starts-with(@href, "tel:")]') as $a) {
            $cand = trim(substr($a->getAttribute('href'), 4));
            if ($cand === '') continue;
            $phone = $cand;
            break;
        }
    }
    // regex fallback, but avoid disallowed
    if (!$email) {
        if (preg_match('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $html, $m)) {
            $cand = $m[0];
            if (!in_array(strtolower($cand), $disallowed)) $email = $cand;
        }
    }
    if (!$phone) {
        if (preg_match('/\+?[0-9][0-9\s\-()]{6,}/', $html, $m)) $phone = trim($m[0]);
    }

    // sanitize common bogus strings
    $email_lc = strtolower(trim($email));
    if ($email_lc === '' || in_array($email_lc, $disallowed)) $email = '';

    // normalize and validate phone: accept only plausible numbers
    $phone_digits = preg_replace('/\D+/', '', $phone);
    if ($phone !== '') {
        if (strpos($phone, '+') === 0) {
            // international: require at least 7 digits
            if (strlen($phone_digits) < 7) $phone = '';
        } else {
            // local: require at least 9 digits to avoid IDs/short codes
            if (strlen($phone_digits) < 9) $phone = '';
        }
    }
    return [trim($name), trim($phone), trim($email)];
}

$files = glob(rtrim($indir, '/') . '/*_urls.txt');
if (!$files) {
    echo "No *_urls.txt files in $indir\n";
    exit(0);
}

foreach ($files as $file) {
    $base = basename($file, '_urls.txt');
    $outCsv = rtrim($outdir, '/') . '/' . $base . '.csv';
    if (file_exists($outCsv) && !$force) {
        if ($verbose) echo "Skipping existing $outCsv (use --force to overwrite)\n";
        continue;
    }
    $urls = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $seen = [];
    $fh = fopen($outCsv, 'w');
    if (!$fh) { echo "Failed to open $outCsv for writing\n"; continue; }
    // header
    fwrite($fh, "Name;Phone;Email\n");
    foreach ($urls as $i => $url) {
        if (trim($url) === '') continue;
        if ($verbose) echo "[$base][$i] fetch: $url\n";
        $html = fetch_html($url, $retry);
        if ($html === false) { if ($verbose) echo " failed to fetch $url\n"; continue; }
        list($name, $phone, $email) = extract_detail($html);
        // skip entries with no usable contact (both phone and email empty)
        if (trim($phone) === '' && trim($email) === '') {
            if ($verbose) echo " no contact, skipping\n";
            continue;
        }
        $key = $name . '|' . $phone . '|' . $email;
        if (isset($seen[$key])) { if ($verbose) echo " duplicate, skipping\n"; continue; }
        $seen[$key] = true;
        fwrite($fh, implode(';', [$name, $phone, $email]) . "\n");
        usleep(rand($delayMin * 1000, $delayMax * 1000));
    }
    fclose($fh);
    if ($verbose) echo "Wrote $outCsv\n";
}

echo "done\n";
