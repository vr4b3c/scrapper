<?php
// e-chalupy-crawler.php
// Simple PHP crawler to collect detail URLs for selected categories

ini_set('user_agent', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/118.0 Safari/537.36');
date_default_timezone_set('UTC');
// Allow long-running CLI execution for large crawls
@set_time_limit(0);
ini_set('max_execution_time', '0');
// increase memory if needed (adjustable)
ini_set('memory_limit', '512M');

$categories = [
    'chaty-chalupy',
    'sruby-roubenky',
    'glamping',
    'apartmany',
    'penziony',
    'kempy',
    'vinne-sklepy',
    'farmy-statky'
];

$maxPages = 0; // 0 = unlimited, use --max-pages=N to cap
$argv = $GLOBALS['argv'];
foreach($argv as $i => $a){
    if($a === '--max-pages' && isset($argv[$i+1])) $maxPages = intval($argv[$i+1]);
    if(substr($a,0,11) === '--max-pages'){
        $parts = explode('=', $a, 2);
        if(isset($parts[1])) $maxPages = intval($parts[1]);
    }
}

$outDir = __DIR__ . '/files';
if(!is_dir($outDir)) mkdir($outDir, 0777, true);

function fetch($url){
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    // increase cURL timeouts for slow responses
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_USERAGENT, ini_get('user_agent'));
    $res = curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);
    return [$res, $info];
}

function absUrl($base, $rel){
    // if already absolute
    if(parse_url($rel, PHP_URL_SCHEME) != '') return $rel;
    // build absolute from base
    $baseParts = parse_url($base);
    $scheme = $baseParts['scheme'];
    $host = $baseParts['host'];
    if(substr($rel,0,1) === '/') return "$scheme://$host" . $rel;
    $path = isset($baseParts['path']) ? rtrim(dirname($baseParts['path']), '/') . '/' : '/';
    return "$scheme://$host$path$rel";
}

$baseHost = 'https://www.e-chalupy.cz';

foreach($categories as $cat){
    $found = [];
    echo "Crawling category: $cat (max pages: " . ($maxPages>0?$maxPages:'unlimited') . ")\n";
    $p = 1;
    $emptyStreak = 0;
    $safetyCap = ($maxPages>0) ? $maxPages : 1000;
    while(true){
        if($maxPages>0 && $p>$maxPages) break;
        if($p > $safetyCap) break;
        $url = $baseHost . '/' . $cat . ($p>1 ? '?p='.$p : '');
        echo "  Fetching: $url\n";
        list($html, $info) = fetch($url);
        if(!$html) { echo "    Failed to fetch $url\n"; $emptyStreak++; if($emptyStreak>=3) break; $p++; usleep(300000); continue; }
        // find hrefs containing '/ubytovani-'
        $matches = [];
        preg_match_all('#href=["\']([^"\']+)["\']#i', $html, $m);
        $foundThisPage = 0;
        if(!empty($m[1])){
            foreach($m[1] as $href){
                // normalize
                $abs = absUrl($url, $href);
                $abs = strtok($abs, '#');
                // Only accept detail pages that include '-o<digits>' (detail identifier)
                // Filter out category/filter pages like '/chaty-chalupy/s-virivkou' which do not have a detail id.
                if(preg_match('#-o[0-9]{1,5}#', $abs)){
                    if(!isset($found[$abs])){
                        $found[$abs] = $abs;
                        $foundThisPage++;
                    }
                }
            }
        }
        // polite delay
        usleep(300000);
        if($foundThisPage === 0){
            $emptyStreak++;
            if($emptyStreak>=3) break;
        } else {
            $emptyStreak = 0;
        }
        $p++;
    }

    $outFile = $outDir . '/e_chalupy_candidates_' . $cat . '.csv';
    // write UTF-8 file with one URL per line
    $fp = fopen($outFile, 'w');
    if(!$fp){ echo "  Unable to write $outFile\n"; continue; }
    // ensure BOM not added; write header comment optional
    foreach($found as $u){
        fwrite($fp, $u . "\n");
    }
    fclose($fp);
    $count = count($found);
    echo "  Wrote $outFile with $count candidates\n";
}

echo "Done.\n";

?>
