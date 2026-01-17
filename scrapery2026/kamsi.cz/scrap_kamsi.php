#!/usr/bin/env php
<?php
// scrap_kamsi.php
// Usage:
//   php scrap_kamsi.php collect apartmany
//   php scrap_kamsi.php parse apartmany
//   php scrap_kamsi.php all apartmany

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "Run from CLI only\n");
    exit(1);
}

$argv0 = array_shift($argv);
$mode = $argv[0] ?? null;
$cat = $argv[1] ?? null;

if (!in_array($mode, ['collect','parse','all'], true) || !$cat) {
    fwrite(STDERR, "Usage: php $argv0 (collect|parse|all) <category>\n");
    fwrite(STDERR, "Categories: apartmany, chalupa, chata, hotel, kemp, penzion\n");
    exit(1);
}

$categoryMap = [
    'apartmany' => 'Apartmány',
    'chalupa' => 'Chalupa',
    'chata' => 'Chata',
    'hotel' => 'Hotel',
    'kemp' => 'Kemp',
    'penzion' => 'Penzion',
];

if (!isset($categoryMap[$cat])) {
    fwrite(STDERR, "Unknown category: $cat\n");
    exit(1);
}

$slug = $cat;
$label = $categoryMap[$cat];
$baseDir = __DIR__ . '/files';
$urlDir = $baseDir . '/urls';
if (!is_dir($urlDir)) mkdir($urlDir, 0755, true);

function fetchUrl(string $url, array $opts = []): array {
    $ua = $opts['ua'] ?? 'Mozilla/5.0 (X11; Linux x86_64)';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, $ua);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $body = curl_exec($ch);
    $info = curl_getinfo($ch);
    $err = curl_error($ch);
    curl_close($ch);
    return ['body' => $body, 'info' => $info, 'err' => $err];
}

function collectList(string $slug): string {
    global $urlDir;
    $out = "$urlDir/{$slug}_urls.txt";
    $urls = [];
    $seen = [];
    $maxPages = 200;
    $consecutiveNoNew = 0;
    $toleranceNoNew = 1; // stop when first duplicate/full-empty page is found
    for ($p = 1; $p <= $maxPages; $p++) {
        $pageUrl = $p === 1 ? "https://kamsi.cz/{$slug}/" : "https://kamsi.cz/{$slug}/page-{$p}/";
        fwrite(STDERR, "Fetching $pageUrl\n");
        $r = fetchUrl($pageUrl, ['ua' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36']);
        if ($r['body'] === false || $r['body'] === null) {
            fwrite(STDERR, "Failed fetch page $p\n");
            break;
        }
        $effective = rtrim($r['info']['url'] ?? $pageUrl, '/');
        if ($effective === 'https://kamsi.cz' || $effective === 'https://www.kamsi.cz') {
            fwrite(STDERR, "Page $p redirected to homepage — stopping\n");
            break;
        }

        // parse DOM
        $doc = new DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8"?>' . $r['body']);
        $xpath = new DOMXPath($doc);
        $nodes = $xpath->query("//a[contains(@class,'accommodtn-preview__link')]/@href");
        if ($nodes->length === 0) {
            // fallback to data-href on preview containers
            $nodes = $xpath->query("//div[@data-href]/@data-href");
        }
        $pageFound = [];
        if ($nodes->length === 0) {
            // fallback to JS markers: accommodation_id in JSON
            preg_match_all('/"accommodation_id"\s*:\s*(\d+)/', $r['body'], $m);
            if (!empty($m[1])) {
                foreach (array_unique($m[1]) as $id) {
                    $pageFound[] = "/detail/{$id}";
                }
            }
        } else {
            foreach ($nodes as $n) {
                $href = trim($n->nodeValue);
                if ($href === '') continue;
                $href = preg_replace('#/$#', '', $href);
                $pageFound[] = $href;
            }
        }

        // normalize pageFound and keep only detail/id entries
        $pageFound = array_map(function($u){ return preg_replace('#.*(/detail/\d+).*#', '$1', $u); }, $pageFound);
        $pageFound = array_filter($pageFound, function($u){ return preg_match('#/detail/\d+#', $u); });
        $pageFound = array_values(array_unique($pageFound));

        // detect new vs seen
        $new = array_diff($pageFound, $seen);
        if (empty($new)) {
            $consecutiveNoNew++;
            fwrite(STDERR, "[{$slug}] page $p — no NEW URLs (consecutive={$consecutiveNoNew})\n");
            if ($consecutiveNoNew > $toleranceNoNew) {
                fwrite(STDERR, "[{$slug}] stopping — encountered duplicate/empty page\n");
                break;
            }
        } else {
            // reset counter, add new
            $consecutiveNoNew = 0;
            foreach ($new as $u) {
                $seen[] = $u;
                $urls[] = $u;
            }
        }
    }
    // normalize, keep only /.../detail/<id>
    $urls = array_map(function($u){ return preg_replace('#.*(/detail/\d+).*#', '$1', $u); }, $urls);
    $urls = array_filter($urls, function($u){ return preg_match('#/detail/\d+#', $u); });
    $urls = array_values(array_unique($urls));
    sort($urls, SORT_STRING);
    $outPath = $out;
    file_put_contents($outPath, implode("\n", $urls) . "\n");
    fwrite(STDERR, "Wrote $outPath — " . count($urls) . " URLs\n");
    return $outPath;
}

function runParser(string $listFile, string $catLabel): void {
    $parser = __DIR__ . '/kamsi_parser.php';
    if (!is_file($parser)) {
        fwrite(STDERR, "Parser not found: $parser\n");
        return;
    }
    $outcsv = __DIR__ . '/files/kamsi_' . strtolower($catLabel) . '.csv';
    $cmd = sprintf('php %s --file=%s --category=%s --out=%s --delay=0.4 --verbose',
        escapeshellarg($parser), escapeshellarg($listFile), escapeshellarg($catLabel), escapeshellarg($outcsv)
    );
    fwrite(STDERR, "Running: $cmd\n");
    passthru($cmd, $rc);
    if ($rc !== 0) fwrite(STDERR, "Parser exited with code $rc\n");
}

if ($mode === 'collect' || $mode === 'all') {
    $listFile = collectList($slug);
}

if ($mode === 'parse' || $mode === 'all') {
    $listFile = $listFile ?? "$urlDir/{$slug}_urls.txt";
    if (!is_file($listFile) || filesize($listFile) === 0) {
        fwrite(STDERR, "URL file not found or empty: $listFile\n");
        exit(1);
    }
    runParser($listFile, $label);
}

exit(0);
