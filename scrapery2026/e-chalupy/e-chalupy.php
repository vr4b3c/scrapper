<?php
// Minimal scraper scaffold for https://www.e-chalupy.cz/
// - collects listing page links and detects candidate detail links
// - extracts basic Name / Email / Telefon (best-effort)
// Usage: php e-chalupy.php (this script runs a short listing-only test by default)

include_once __DIR__ . '/../../simple_dom.php';
include_once __DIR__ . '/../../scrappery2025/helpers.php';

ini_set('max_execution_time', 0);

$outDir = __DIR__ . '/files';
if (!is_dir($outDir)) mkdir($outDir, 0755, true);

$startUrl = 'https://www.e-chalupy.cz/';
$domain = 'https://www.e-chalupy.cz';

$maxPages = 200; // safety
$delayMicro = 200000;

function fetch_url($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; scraper/1.0)');
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $html = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 400 || !$html) return false;
    return $html;
}

function normalize_url($href, $baseDomain, $currentUrl = null) {
    $href = trim($href);
    if ($href === '') return null;
    if (strpos($href, '//') === 0) return 'https:' . $href;
    if (preg_match('#^https?://#i', $href)) return $href;
    if (strpos($href, '/') === 0) return rtrim($baseDomain, '/') . $href;
    if ($currentUrl) {
        $p = parse_url($currentUrl);
        $scheme = $p['scheme'] ?? 'https';
        $host = $p['host'] ?? parse_url($baseDomain, PHP_URL_HOST);
        $path = $p['path'] ?? '/';
        $dir = rtrim(dirname($path), '/');
        if ($dir === '.') $dir = '';
        return $scheme . '://' . $host . $dir . '/' . ltrim($href, './');
    }
    return null;
}

echo "Starting e-chalupy scaffold (listing-only test)\n";

$html = fetch_url($startUrl);
if (!$html) { echo "Failed to fetch start URL\n"; exit(1); }

$dom = str_get_html($html);

// Collect candidate detail links (heuristic): likely contain '/ubytovani' or '/chalupa' or '/apartman'
$candidates = [];
foreach ($dom->find('a') as $a) {
    $href = $a->href ?? '';
    if (!$href) continue;
    $full = normalize_url($href, $domain, $startUrl);
    if (!$full) continue;
    $p = parse_url($full, PHP_URL_PATH) ?: '';
    $lp = strtolower($p);
    if (strpos($lp, '/chalupa') !== false || strpos($lp, '/ubytovani') !== false || strpos($lp, '/apart') !== false) {
        $candidates[$full] = true;
    }
}

$candList = array_keys($candidates);
echo "Found " . count($candList) . " candidate detail links on start page\n";
// Write quick CSV with detected candidate links for manual inspection
$rows = [];
foreach ($candList as $u) $rows[] = [basename($u), $u, ''];
if (!empty($rows)) {
    $csv = fopen($outDir . '/e_chalupy_candidates.csv', 'w');
    fputcsv($csv, ['label','url','note']);
    foreach ($rows as $r) fputcsv($csv, $r);
    fclose($csv);
    echo "Wrote candidate CSV: " . $outDir . "/e_chalupy_candidates.csv\n";
} else {
    echo "No candidate links detected on start page. You may need to provide a category/listing URL.\n";
}

echo "Scaffold complete — next: implement detail parsing and CSV output.\n";

?>