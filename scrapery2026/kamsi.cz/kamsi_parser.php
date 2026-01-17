<?php
// kamsi_parser.php
// Simple CLI parser for kamsi.cz categories (Apartmány, Chalupa, Chata, Hotel, Kemp, Penzion)

if (php_sapi_name() !== 'cli') { echo "Run from CLI only\n"; exit(1); }

$opts = getopt('', ['category:', 'start:', 'end:', 'out:', 'delay:', 'verbose', 'file:']);
$category = $opts['category'] ?? null;
$fileList = $opts['file'] ?? null;
$start = intval($opts['start'] ?? 1);
$end = isset($opts['end']) && $opts['end'] !== '' ? intval($opts['end']) : null;
$out = $opts['out'] ?? 'kamsi_results.csv';
$delay = floatval($opts['delay'] ?? 0.5);
$verbose = isset($opts['verbose']);

$categoryMap = [
    'Apartmány' => 'apartmany',
    'Chalupa' => 'chalupa',
    'Chata' => 'chata',
    'Hotel' => 'hotel',
    'Kemp' => 'kemp',
    'Penzion' => 'penzion',
];

# category is optional when using --file; otherwise required
if (!$fileList) {
    if (!$category) {
        echo "Usage: php kamsi_parser.php --category=Apartmány [--start=1] [--end=10] [--out=path] [--delay=0.5] [--verbose]\n";
        echo "Supported categories: ".implode(', ', array_keys($categoryMap))."\n";
        exit(1);
    }
    if (!isset($categoryMap[$category])) { echo "Unknown category: $category\n"; exit(1); }
    $slug = $categoryMap[$category];
} else {
    // If file mode and category provided, keep it; otherwise use empty category label
    $slug = $category ? ($categoryMap[$category] ?? null) : null;
}

$outDir = dirname($out);
if ($outDir && !is_dir($outDir)) {
    if (!mkdir($outDir, 0755, true) && !is_dir($outDir)) {
        echo "Failed to create output directory: $outDir\n";
        exit(1);
    }
}
$fpOut = fopen($out, 'w');
if (!$fpOut) { echo "Cannot open output file: $out\n"; exit(1); }
fwrite($fpOut, "Name;Phone;Email\n");

function fetch_url(string $url, int $maxRetries = 3, float $delay = 0.5, bool $verbose = false) {
    $attempt = 0;
    while ($attempt < $maxRetries) {
        $attempt++;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; kamsi-parser/1.0)');
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body !== false && $code >= 200 && $code < 400) return $body;
        if ($verbose) echo "fetch attempt $attempt failed (code=$code err=$err)\n";
        usleep((int)($delay * 1e6));
    }
    return false;
}

function parse_detail(string $html, bool $verbose = false) {
    $doc = new DOMDocument();
    @$doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
    $xpath = new DOMXPath($doc);

    // Note: these selectors are guesses — adjust after inspecting site HTML
    $name = '';
    $nodes = $xpath->query("//h1");
    if ($nodes->length) $name = trim($nodes->item(0)->textContent);

    $phone = '';
    // first attempt: elements that expose phone via data-phone attribute (site uses hidden-phone-number links)
    $phoneNodes = $xpath->query("//*[@data-phone]");
    if ($phoneNodes->length) {
        $phone = trim($phoneNodes->item(0)->getAttribute('data-phone'));
    } else {
        // fallback: common phone selectors or visible phone text
        $phoneNodes = $xpath->query("//*[contains(@class,'tel') or contains(@class,'phone')]");
        if ($phoneNodes->length) $phone = trim($phoneNodes->item(0)->textContent);
        else {
            // last fallback: regex search in raw HTML for +420 numbers
            if (preg_match('/(\+?420[\d \-\/\(\)\.]{6,})/u', $html, $m)) $phone = trim($m[1]);
        }
    }

    $email = '';
    $emailNodes = $xpath->query("//a[starts-with(@href,'mailto:')]");
    if ($emailNodes->length) $email = preg_replace('/^mailto:/', '', trim($emailNodes->item(0)->getAttribute('href')));

    // We do not extract address anymore — only name, phone, email are needed.

    // normalize whitespace
    $sanitize = function($s){ return preg_replace('/\s+/',' ', trim($s)); };

    return [
        'name' => $sanitize($name),
        'phone' => $sanitize($phone),
        'email' => $sanitize($email),
    ];
}

// If a file with URLs is provided, process those links instead of paginated listing
if ($fileList) {
    if (!is_file($fileList)) { echo "URL file not found: $fileList\n"; exit(1); }
    $lines = file($fileList, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $href) {
        $href = trim($href);
        if ($href === '') continue;
        // normalize relative paths
        if (strpos($href, 'http') !== 0) {
            if (strpos($href, '/') === 0) $href = 'https://kamsi.cz' . $href;
            else $href = 'https://kamsi.cz/' . $href;
        }
        if ($verbose) echo "Fetching detail: $href\n";
        $detailHtml = fetch_url($href, 3, $delay, $verbose);
        if ($detailHtml === false) { if ($verbose) echo "Failed detail $href\n"; continue; }
        $data = parse_detail($detailHtml, $verbose);
        $line = implode(';', [ $data['name'], $data['phone'], $data['email'] ]);
        fwrite($fpOut, $line . "\n");
        usleep((int)($delay * 1e6));
    }
} else {
    $listUrlTemplate = 'https://kamsi.cz/'.$slug.'/page-%d/';
    $page = $start;
    while (true) {
        if ($end !== null && $page > $end) break;
        $listUrl = sprintf($listUrlTemplate, $page);
        if ($verbose) echo "Fetching list: $listUrl\n";
        $listHtml = fetch_url($listUrl, 3, $delay, $verbose);
        if ($listHtml === false) { if ($verbose) echo "Failed to fetch list page $page\n"; break; }

        // Extract detail links from listing page — use the preview link class or data-href on preview divs
        $doc = new DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8"?>' . $listHtml);
        $xpath = new DOMXPath($doc);
        $linkNodes = $xpath->query("//a[contains(@class,'accommodtn-preview__link') and @href]");
        if ($linkNodes->length === 0) {
            // fallback: use preview containers that embed data-href
            $linkNodes = $xpath->query("//div[contains(@class,'js-accommodtn-item') and @data-href]");
        }
        if ($linkNodes->length === 0) { if ($verbose) echo "No links found on page $page — stopping.\n"; break; }

        foreach ($linkNodes as $ln) {
            $href = $ln->hasAttribute('href') ? $ln->getAttribute('href') : $ln->getAttribute('data-href');
            if (preg_match('#^https?://#i', $href) !== 1) {
                if (strpos($href, '/') === 0) $href = 'https://kamsi.cz' . $href;
                else $href = 'https://kamsi.cz/' . $href;
            }
            if ($verbose) echo "Fetching detail: $href\n";
            $detailHtml = fetch_url($href, 3, $delay, $verbose);
            if ($detailHtml === false) { if ($verbose) echo "Failed detail $href\n"; continue; }
            $data = parse_detail($detailHtml, $verbose);
            $line = implode(';', [ $data['name'], $data['phone'], $data['email'] ]);
            fwrite($fpOut, $line . "\n");
            // polite delay
            usleep((int)($delay * 1e6));
        }

        $page++;
    }
}

fclose($fpOut);
echo "Done — output: $out\n";

// End of file
