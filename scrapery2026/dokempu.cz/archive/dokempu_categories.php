<?php
// dokempu_categories.php
// Enumerate provided category listing pages, paginate and collect detail URLs per category.

if (php_sapi_name() !== 'cli') {
    echo "Run from CLI only\n";
    exit(1);
}

$opts = getopt('', ['categories:', 'outdir::', 'max-pages::', 'delay-min::', 'delay-max::', 'verbose']);
$categoriesFile = $opts['categories'] ?? null;
$outdir = $opts['outdir'] ?? sys_get_temp_dir() . '/dokempu_cat_urls';
$maxPages = intval($opts['max-pages'] ?? 5);
$delayMin = intval($opts['delay-min'] ?? 100);
$delayMax = intval($opts['delay-max'] ?? 300);
$verbose = isset($opts['verbose']);

if (!$categoriesFile || !is_file($categoriesFile)) {
    echo "Usage: php dokempu_categories.php --categories=categories.txt [--outdir=/path] [--max-pages=5] [--delay-min=100] [--delay-max=300] [--verbose]\n";
    exit(1);
}

if (!is_dir($outdir)) mkdir($outdir, 0755, true);

function curl_get($url, &$curlHandle = null) {
    if (!$curlHandle) {
        $curlHandle = curl_init();
        curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curlHandle, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curlHandle, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible)');
        curl_setopt($curlHandle, CURLOPT_TIMEOUT, 20);
    }
    curl_setopt($curlHandle, CURLOPT_URL, $url);
    $res = curl_exec($curlHandle);
    $err = curl_error($curlHandle);
    return [$res, $err];
}

function extract_bracketed_json($haystack, $key) {
    $pos = stripos($haystack, $key);
    if ($pos === false) return null;
    $start = strpos($haystack, '[', $pos);
    if ($start === false) return null;
    $len = strlen($haystack);
    $depth = 0;
    for ($i = $start; $i < $len; $i++) {
        $ch = $haystack[$i];
        if ($ch === '[') {
            $depth++;
            continue;
        }
        if ($ch === ']') {
            $depth--;
            if ($depth === 0) {
                return substr($haystack, $start, $i - $start + 1);
            }
            continue;
        }
        if ($ch === '"') {
            $i++;
            while ($i < $len) {
                if ($haystack[$i] === '\\') { $i += 2; continue; }
                if ($haystack[$i] === '"') break;
                $i++;
            }
        }
    }
    return null;
}

function extract_items_from_next_payload($html) {
    // Search for a Next.js push payload that contains a "state" -> "data" -> "items" array
    // Find the occurrence of "items" with offset, then bracket-match the array start
    if (!preg_match('/"items"\s*:\s*\[/si', $html, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $itemsPos = $m[0][1];
    // locate the first '[' after the match
    $start = strpos($html, '[', $itemsPos);
    if ($start === false) return null;
    $len = strlen($html);
    $depth = 0;
    for ($i = $start; $i < $len; $i++) {
        $ch = $html[$i];
        if ($ch === '[') { $depth++; continue; }
        if ($ch === ']') {
            $depth--;
            if ($depth === 0) {
                return substr($html, $start, $i - $start + 1);
            }
            continue;
        }
        if ($ch === '"') {
            $i++;
            while ($i < $len) {
                if ($html[$i] === '\\') { $i += 2; continue; }
                if ($html[$i] === '"') break;
                $i++;
            }
        }
    }
    return null;
}

$curl = null;
$lines = file($categoriesFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '') continue;
    // Derive a safe filename from category URL or name
    $parts = parse_url($line);
    $path = $parts['path'] ?? 'cat';
    $safe = preg_replace('#[^a-z0-9\-]#i', '-', trim($path, '/'));
    if ($safe === '') $safe = 'cat';
    $outFile = rtrim($outdir, '/') . '/' . $safe . '_urls.txt';
    $fh = fopen($outFile, 'w');
    if (!$fh) {
        echo "Failed to open $outFile for writing\n";
        continue;
    }
    if ($verbose) echo "Enumerating category: $line -> $outFile\n";

    for ($p = 1; $p <= $maxPages; $p++) {
        $pageUrl = $line . (strpos($line, '?') === false ? '?' : '&') . 'page=' . $p;
        list($html, $err) = curl_get($pageUrl, $curl);
        if ($err || !$html) {
            if ($verbose) echo "fetch error page $p: $err\n";
            break;
        }
        $found = 0;
        $seen_page = [];
        // 1) Try to extract items from embedded JSON (preferred) by locating the "items" array
        if ($found === 0) {
            $itemsText = null;
            // first try targeted Next.js payload extraction
            $itemsText = extract_items_from_next_payload($html);
            // fallback to general bracketed extractor
            if ($itemsText === null) {
                $itemsText = extract_bracketed_json($html, '"items"');
            }
            if ($itemsText !== null) {
                if ($verbose) echo " extracted items JSON length: " . strlen($itemsText) . "\n";
                $items = json_decode($itemsText, true);
                if (!is_array($items)) {
                    $items = json_decode(stripcslashes($itemsText), true);
                }
                if (is_array($items)) {
                    foreach ($items as $it) {
                        if (!empty($it['campsiteId']) && !empty($it['slug'])) {
                            $id = $it['campsiteId'];
                            $slug = $it['slug'];
                            $href = 'https://www.dokempu.cz/' . $slug . '--kemp-' . $id;
                            if (isset($seen_page[$href])) continue;
                            fwrite($fh, $href . "\n");
                            $seen_page[$href] = true;
                            $found++;
                        }
                    }
                }
            }
        }
        // 2) Fallback: match pairs allowing escaped quotes (handles JSON inside JS strings)
        if ($found === 0) {
            // try campsiteId then slug
            if (preg_match_all('/"campsiteId"\s*:\s*(\d+).*?"slug"\s*:\s*"([^"]+)"/si', $html, $m)) {
                for ($i = 0; $i < count($m[0]); $i++) {
                    $id = $m[1][$i];
                    $slug = $m[2][$i];
                    $href = 'https://www.dokempu.cz/' . $slug . '--kemp-' . $id;
                    if (isset($seen_page[$href])) continue;
                    fwrite($fh, $href . "\n");
                    $seen_page[$href] = true;
                    $found++;
                }
            }
            // try slug then campsiteId
            if ($found === 0 && preg_match_all('/"slug"\s*:\s*"([^"]+)".*?"campsiteId"\s*:\s*(\d+)/si', $html, $m2)) {
                for ($i = 0; $i < count($m2[0]); $i++) {
                    $slug = $m2[1][$i];
                    $id = $m2[2][$i];
                    $href = 'https://www.dokempu.cz/' . $slug . '--kemp-' . $id;
                    if (isset($seen_page[$href])) continue;
                    fwrite($fh, $href . "\n");
                    $seen_page[$href] = true;
                    $found++;
                }
            }
        }
        // 3) Targeted container extraction: look for anchors inside #carItemsWraper
        if ($found === 0) {
            $dom = new DOMDocument();
            @$dom->loadHTML($html);
            $xpath = new DOMXPath($dom);
            $containerAnchors = $xpath->query('//*[@id="carItemsWraper"]//a[@href]');
            if ($containerAnchors && $containerAnchors->length) {
                foreach ($containerAnchors as $a) {
                    $href = $a->getAttribute('href');
                    if (preg_match('/--kemp-(\d+)/i', $href, $mm)) {
                        if (strpos($href, 'http') !== 0) {
                            $href = rtrim('https://www.dokempu.cz', '/') . '/' . ltrim($href, '/');
                        }
                        if (isset($seen_page[$href])) continue;
                        fwrite($fh, $href . "\n");
                        $seen_page[$href] = true;
                        $found++;
                    }
                }
            }
        }
        // 4) Fallback: parse anchor hrefs for detail-like patterns (full-page scan)
        if ($found === 0) {
            if (!isset($dom)) {
                $dom = new DOMDocument();
                @$dom->loadHTML($html);
                $xpath = new DOMXPath($dom);
            }
            $anchors = $xpath->query('//a[@href]');
            foreach ($anchors as $a) {
                $href = $a->getAttribute('href');
                if (strpos($href, '/detail/') !== false || preg_match('#/objekt/#', $href) || (preg_match('#--[a-z0-9\-]+-[0-9]+#i', $href) && !preg_match('#--seg-#i', $href))) {
                    if (strpos($href, 'http') !== 0) {
                        $href = rtrim('https://www.dokempu.cz', '/') . '/' . ltrim($href, '/');
                    }
                    if (isset($seen_page[$href])) continue;
                    fwrite($fh, $href . "\n");
                    $seen_page[$href] = true;
                    $found++;
                }
            }
        }
        if ($verbose) echo " page $p: found $found detail links\n";
        // if no links found on page, assume pagination ended
        if ($found === 0) break;
        // randomized delay
        usleep(rand($delayMin * 1000, $delayMax * 1000));
    }
    fclose($fh);
    if ($verbose) echo "Wrote $outFile\n";
}

if ($curl) curl_close($curl);
echo "done\n";
