<?php
// Enumerate category pages on kamsi.cz and save detail URLs per category

function curl_get($url, &$http=null) {
    static $ch = null;
    if ($ch === null) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; kamsi-cat-enum/1.0)');
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    }
    curl_setopt($ch, CURLOPT_URL, $url);
    $b = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return $b;
}

function parse_args($argv) {
    $o = [];
    foreach ($argv as $a) if (strpos($a, '--') === 0) {
        $p = explode('=', $a, 2);
        $k = ltrim($p[0], '-');
        $o[$k] = $p[1] ?? true;
    }
    return $o;
}

function extract_category_links($html) {
    $out = [];
    if (!$html) return $out;
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
    $xpath = new DOMXPath($dom);
    $nodes = $xpath->query('//div[contains(@class,"threeColumnsVersion")]//a');
    foreach ($nodes as $n) {
        $href = $n->getAttribute('href');
        $text = trim($n->textContent);
        if ($href) $out[$href] = $text ?: $href;
    }
    return $out;
}

function extract_detail_links($html, $baseHost='https://kamsi.cz') {
    $links = [];
    if (!$html) return $links;
    // find anchors with "/detail/"
    if (preg_match_all('#href=["\']([^"\']*/detail/[0-9]+[^"\']*)["\']#i', $html, $m)) {
        foreach ($m[1] as $u) {
            if (strpos($u, 'http') !== 0) $u = rtrim($baseHost, '/') . '/' . ltrim($u, '/');
            $links[$u] = true;
        }
    }
    return array_keys($links);
}

$opts = parse_args($argv ?? []);
$all = isset($opts['all']);
$category = $opts['category'] ?? '';
$outdir = $opts['outdir'] ?? __DIR__ . '/files';
$maxPages = intval($opts['max-pages'] ?? 5);

if (!is_dir($outdir)) mkdir($outdir, 0755, true);

$base = 'https://kamsi.cz';

$categories = [];
if ($all) {
    $home = curl_get($base, $http);
    $categories = extract_category_links($home);
} elseif ($category) {
    $categories[$category] = $category;
} else {
    echo "Provide --all or --category=/path\n";
    exit(1);
}

foreach ($categories as $path => $label) {
    $catUrl = (strpos($path, 'http')===0) ? $path : rtrim($base, '/') . '/' . ltrim($path, '/');
    $slug = preg_replace('#[^a-z0-9_-]#i','_', trim(parse_url($catUrl, PHP_URL_PATH), '/')) ?: 'category';
    $outfile = rtrim($outdir,'/') . '/' . $slug . '_urls.txt';
    $seen = [];
    echo "Processing category: {$label} -> {$catUrl}\n";

    // iterate pages: first page is base, then ?page=2,3...
    for ($p=1;$p<=$maxPages;$p++) {
        $url = $catUrl;
        if ($p > 1) $url = $catUrl . (strpos($catUrl,'?')===false ? '?page=' . $p : '&page=' . $p);
        $html = curl_get($url, $http);
        if ($http >= 400 || !$html) {
            echo "  page {$p} returned http={$http}, stopping.\n";
            break;
        }
        $links = extract_detail_links($html, $base);
        $new = 0;
        foreach ($links as $l) {
            if (!isset($seen[$l])) { $seen[$l]=true; $new++; }
        }
        echo "  page {$p}: found " . count($links) . " links, new={$new}\n";
        if ($new === 0 && $p>1) break;
    }

    // write out
    $lines = array_keys($seen);
    sort($lines);
    file_put_contents($outfile, implode("\n", $lines) . (count($lines)?"\n":""));
    echo "  wrote " . count($lines) . " URLs to {$outfile}\n";
}

echo "done\n";

?>
