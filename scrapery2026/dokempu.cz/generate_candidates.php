<?php
// generate_candidates.php
// Create candidate detail-URL lists for Dokempu category pages.
// Usage: php generate_candidates.php --category=URL [--outdir=/tmp/dokempu_cat_urls] [--max-pages=3] [--verbose]

if (php_sapi_name() !== 'cli') { echo "Run from CLI only\n"; exit(1); }
$opts = getopt('', ['category::', 'categories::', 'outdir::', 'max-pages::', 'verbose']);
$outdir = $opts['outdir'] ?? sys_get_temp_dir() . '/dokempu_cat_urls';
$maxPages = intval($opts['max-pages'] ?? 3);
$verbose = isset($opts['verbose']);

if (!is_dir($outdir)) mkdir($outdir, 0755, true);

function curl_get_once($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible)');
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_URL, $url);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    return [$res, $err];
}

function extract_bracketed_json_from_pos($haystack, $pos, $open='[', $close=']') {
    $len = strlen($haystack);
    $start = strpos($haystack, $open, $pos);
    if ($start === false) return null;
    $depth = 0;
    for ($i = $start; $i < $len; $i++) {
        $ch = $haystack[$i];
        if ($ch === $open) { $depth++; continue; }
        if ($ch === $close) { $depth--; if ($depth === 0) return substr($haystack, $start, $i - $start + 1); continue; }
        if ($ch === '"') { $i++; while ($i < $len) { if ($haystack[$i] === '\\') { $i += 2; continue; } if ($haystack[$i] === '"') break; $i++; } }
    }
    return null;
}

function extract_items_array($html) {
    // Look for a direct "items":[ sequence first (fast path)
    $needle = '"items":[';
    $pos = strpos($html, $needle);
    if ($pos !== false) {
        // position at start of "items":[
        $bracketPos = strpos($html, '[', $pos);
        if ($bracketPos !== false) {
            $arr = extract_bracketed_json_from_pos($html, $bracketPos, '[', ']');
            if ($arr !== null) return $arr;
        }
    }

    // If fast path failed, search for any occurrence of "items" and attempt extraction
    $offset = 0;
    while (($itemsPos = stripos($html, '"items"', $offset)) !== false) {
        $arr = extract_bracketed_json_from_pos($html, $itemsPos, '[', ']');
        if ($arr !== null) return $arr;
        $offset = $itemsPos + 7;
    }

    // final fallback: find an array that starts with an object containing campsiteId
    if (preg_match('/\[\s*\{\s*"campsiteId"/i', $html, $m, PREG_OFFSET_CAPTURE)) {
        $pos = $m[0][1];
        $arr = extract_bracketed_json_from_pos($html, $pos, '[', ']');
        if ($arr !== null) return $arr;
    }
    return null;
}

function safe_filename_from_url($url) {
    $parts = parse_url($url);
    $path = $parts['path'] ?? 'cat';
    $safe = preg_replace('#[^a-z0-9\-]#i', '-', trim($path, '/'));
    if ($safe === '') $safe = 'cat';
    return $safe;
}

function process_category($url, $outdir, $maxPages, $verbose) {
    $safe = safe_filename_from_url($url);
    $outFile = rtrim($outdir, '/') . '/' . $safe . '_urls.txt';
    $fh = fopen($outFile, 'w');
    if (!$fh) { echo "Failed to open $outFile\n"; return; }
    if ($verbose) echo "Processing $url -> $outFile\n";
    for ($p = 1; $p <= $maxPages; $p++) {
        $pageUrl = $url . (strpos($url, '?') === false ? '?' : '&') . 'page=' . $p;
        list($html,$err) = curl_get_once($pageUrl);
        if ($err || !$html) { if ($verbose) echo " fetch error page $p: $err\n"; break; }
        $found = 0; $seen = [];
        $itemsText = extract_items_array($html);
        if ($itemsText !== null) {
            if ($verbose) echo " extracted items JSON length: " . strlen($itemsText) . "\n";
            $items = json_decode($itemsText, true);
            if (!is_array($items)) $items = json_decode(stripcslashes($itemsText), true);
            if (is_array($items)) {
                foreach ($items as $it) {
                    if (!empty($it['campsiteId']) && !empty($it['slug'])) {
                        $href = 'https://www.dokempu.cz/' . $it['slug'] . '--kemp-' . $it['campsiteId'];
                        if (isset($seen[$href])) continue;
                        fwrite($fh, $href . "\n"); $seen[$href]=true; $found++;
                    }
                }
            }
        }
        if ($found === 0 && preg_match_all('/"campsiteId"\s*:\s*(\d+).*?"slug"\s*:\s*"([^"]+)"/si', $html, $m)) {
            for ($i=0;$i<count($m[0]);$i++) {
                $href = 'https://www.dokempu.cz/' . $m[2][$i] . '--kemp-' . $m[1][$i];
                if (isset($seen[$href])) continue; fwrite($fh,$href."\n"); $seen[$href]=true; $found++;
            }
        }
        if ($found === 0) {
            $dom = new DOMDocument(); @$dom->loadHTML($html); $xpath = new DOMXPath($dom);
            $anchors = $xpath->query('//*[@id="carItemsWraper"]//a[@href]');
            if ($anchors && $anchors->length) {
                foreach ($anchors as $a) {
                    $href = $a->getAttribute('href');
                    if (preg_match('/--kemp-(\d+)/i', $href)) {
                        if (strpos($href,'http')!==0) $href = rtrim('https://www.dokempu.cz','/').'/'.ltrim($href,'/');
                        if (isset($seen[$href])) continue; fwrite($fh,$href."\n"); $seen[$href]=true; $found++;
                    }
                }
            }
        }
        if ($verbose) echo " page $p: found $found detail links\n";
        if ($found === 0) break;
        usleep(200000);
    }
    fclose($fh);
    if ($verbose) echo "Wrote $outFile\n";
}

$categories = [];
if (isset($opts['category'])) $categories[] = $opts['category'];
if (isset($opts['categories'])) {
    $lines = @file($opts['categories'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines !== false) foreach ($lines as $l) $categories[] = trim($l);
}
if (empty($categories)) {
    $categories = [
        'https://www.dokempu.cz/stany--seg-11147',
        'https://www.dokempu.cz/glamping--seg-4969',
        'https://www.dokempu.cz/dovolena-na-kole--seg-5987',
        'https://www.dokempu.cz/pro-firmy-a-skupiny--seg-6259',
        'https://www.dokempu.cz/kempy-na-slovensku--seg-6462',
        'https://www.dokempu.cz/nejlepe-hodnocene--seg-6774',
    ];
}

foreach ($categories as $cat) {
    if ($cat === '') continue;
    process_category($cat, $outdir, $maxPages, $verbose);
}

echo "done\n";
