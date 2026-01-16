<?php
// cotady_scraper.php
// Single-file scraper: fetches list pages and then scrapes details.
// Usage:
// php cotady_scraper.php            # fetch + scrape from start
// php cotady_scraper.php --start=100 # resume scraping from index 100 (0-based)
// php cotady_scraper.php --force-fetch # re-fetch candidate URLs even if present

$BASE = __DIR__;
$FILES_DIR = $BASE . '/files';
if(!is_dir($FILES_DIR)) mkdir($FILES_DIR, 0755, true);

$CAND_FILE = $FILES_DIR . '/cotady_candidates_ubytovani.csv';
$OUT_FILE  = $FILES_DIR . '/cotady_ubytovani.csv';

function curl_get($url){
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_USERAGENT, 'cotady-scraper/1.0 (+https://example.org)');
    $res = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if($res === false) throw new RuntimeException("curl error: $err");
    if($code >= 400) throw new RuntimeException("http $code");
    return $res;
}

function extract_detail_links($html){
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xp = new DOMXPath($dom);
    // Only take anchors that are inside the .geodir-entry-title element
    $nodes = $xp->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' geodir-entry-title ')]//a[@href]");
    $out = [];
    foreach($nodes as $n){
        $href = trim($n->getAttribute('href'));
        if(!$href) continue;
        // make absolute
        if(strpos($href, '/') === 0) $href = 'https://www.cotady.cz' . $href;
        // ignore category index anchors and fragments that don't point to details
        if(strpos($href, '/podniky/category') !== false) continue;
        // normalize (strip query and fragment) and ensure trailing slash
        $canon = normalize_url($href);
        if(!$canon) continue;
        // skip invalid detail root
        if(preg_match('#/podniky/?$#', $canon)) continue;
        $out[] = $canon;
    }
    return array_values(array_unique($out));
}

function normalize_url($href){
    $href = trim($href);
    if($href === '') return '';
    // if relative like /podniky/..., prefix domain
    if(strpos($href, '/') === 0) $href = 'https://www.cotady.cz' . $href;
    $parts = parse_url($href);
    if($parts === false) return '';
    $scheme = isset($parts['scheme']) ? $parts['scheme'] : 'https';
    $host = isset($parts['host']) ? $parts['host'] : 'www.cotady.cz';
    $path = isset($parts['path']) ? $parts['path'] : '/';
    // remove duplicate slashes
    $path = preg_replace('#/+#','/',$path);
    // ensure trailing slash for directory-like URLs
    if(substr($path, -1) !== '/') $path .= '/';
    return $scheme . '://' . $host . $path;
}

function extract_fields_from_detail($html){
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xp = new DOMXPath($dom);
    $get = function($expr) use ($xp){
        $nodes = $xp->query($expr);
        if($nodes && $nodes->length) return trim($nodes->item(0)->textContent);
        return '';
    };
    $name_expr = "//div[@id='main']//*[contains(concat(' ', normalize-space(@class), ' '), ' geodir-field-post_title ')]";
    // prefer the email field container; some anchors use javascript onclick to build mailto
    $email_expr = "//div[@id='main']//*[contains(concat(' ', normalize-space(@class), ' '), ' geodir-field-email ')]//a | //div[@id='main']//*[contains(concat(' ', normalize-space(@class), ' '), ' geodir-i-email ')]//a";
    $phone_expr = "//div[@id='main']//*[contains(concat(' ', normalize-space(@class), ' '), ' geodir-field-phone ')]//a[starts-with(@href,'tel:')] | //div[@id='main']//*[contains(concat(' ', normalize-space(@class), ' '), ' geodir-field-phone ')]//a";

    $name = $get($name_expr);
    // email: try href mailto first, otherwise extract from visible text (handles javascript href)
    $email = '';
    $nodes = $xp->query($email_expr);
    if($nodes && $nodes->length){
        $n = $nodes->item(0);
        if($n->hasAttribute('href')){
            $href = trim($n->getAttribute('href'));
            if(preg_match('/^mailto:/i',$href)){
                $email = preg_replace('/^mailto:/i','',$href);
            } else {
                // fallback: try to extract email from anchor text
                $text = trim($n->textContent);
                if(preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i',$text,$m)) $email = $m[0];
                else {
                    // as last resort, search entire container text
                    $container = trim($n->parentNode->textContent);
                    if(preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i',$container,$m2)) $email = $m2[0];
                }
            }
        } else {
            $text = trim($n->textContent);
            if(preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i',$text,$m)) $email = $m[0];
        }
        $email = trim($email);
    }
    $phone = '';
    $nodes = $xp->query($phone_expr);
    if($nodes && $nodes->length){
        $n = $nodes->item(0);
        if($n->hasAttribute('href')) $phone = preg_replace('/^tel:/i','',trim($n->getAttribute('href')));
        else $phone = trim($n->textContent);
    }
    return [trim($name), trim($phone), trim($email)];
}

// parse CLI
$opts = [];
foreach($argv as $a) if(strpos($a,'=')!==false){ list($k,$v)=explode('=',$a,2); $opts[$k]=$v; } else $opts[$a]=true;
$start = isset($opts['--start']) ? (int)$opts['--start'] : 0;
$limit = isset($opts['--limit']) ? (int)$opts['--limit'] : 0; // 0 = no limit
$out_override = isset($opts['--out']) ? $opts['--out'] : null;
// delay controls in milliseconds (defaults roughly 300-600 ms)
$delay_min_ms = isset($opts['--delay-min']) ? (int)$opts['--delay-min'] : 300;
$delay_max_ms = isset($opts['--delay-max']) ? (int)$opts['--delay-max'] : 600;
$force_fetch = isset($opts['--force-fetch']) || isset($opts['--force']);

// 1) fetch candidate URLs if needed or forced
if(!file_exists($CAND_FILE) || $force_fetch){
    echo "Fetching candidate pages...\n";
    $base = 'https://www.cotady.cz/podniky/category/ubytovani/';
    $total_found = 0;
    $seen = [];
    if(file_exists($CAND_FILE)){
        $lines = file($CAND_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach($lines as $l){
            $canon = normalize_url($l);
            if($canon) $seen[$canon]=true;
        }
    }
    for($p=1;$p<=978;$p++){
        $list_url = $base . 'page/' . $p . '/';
        echo "  page $p - $list_url\n";
        try{
            $html = curl_get($list_url);
        }catch(Exception $e){
            echo "failed ({$e->getMessage()})\n";
            continue;
        }
        $links = extract_detail_links($html);
        $added = 0;
        // log collected links for this page
        if(count($links)){
            echo "    collected (" . count($links) . "):\n";
            foreach($links as $ln) echo "      - $ln\n";
        } else {
            echo "    collected (0)\n";
        }
        foreach($links as $l){ if(!isset($seen[$l])){ file_put_contents($CAND_FILE, $l.PHP_EOL, FILE_APPEND | LOCK_EX); $seen[$l]=true; $added++; } }
        $total_found += count($links);
        echo "    page summary: found " . count($links) . ", added $added\n";
        // polite delay (ms -> us)
        usleep( ($delay_min_ms * 1000) + rand(0, max(0, ($delay_max_ms - $delay_min_ms) * 1000)) );
    }
    echo "Candidates written to: $CAND_FILE\n";
} else {
    echo "Candidate file exists: $CAND_FILE (use --force-fetch to refresh)\n";
}

// 2) scrape details
if(!file_exists($CAND_FILE)){
    fwrite(STDERR, "No candidate file: $CAND_FILE\n");
    exit(2);
}
$urls = file($CAND_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if(!$urls){ fwrite(STDERR, "No URLs found in $CAND_FILE\n"); exit(2); }

// prepare output (allow override)
if($out_override){
    $OUT_FILE = $out_override;
}
if(!file_exists($OUT_FILE)) file_put_contents($OUT_FILE, "Name;Phone;Email\n", LOCK_EX);

$already = 0;
// determine resume position from existing output file
if(file_exists($OUT_FILE)){
    $lines = file($OUT_FILE, FILE_IGNORE_NEW_LINES);
    $already = max(0, count($lines)-1);
}
if($start > 0) $pos = $start; else $pos = $already;
echo "Scraping details from index: $pos (total urls: " . count($urls) . ")\n";

for($i=$pos;$i<count($urls);$i++){
    if($limit > 0 && $i >= $pos + $limit) break;
    $u = $urls[$i];
    echo sprintf("[%d/%d] %s\n", $i+1, count($urls), $u);
    $t0 = microtime(true);
    try{ $html = curl_get($u); }catch(Exception $e){ echo "  failed: {$e->getMessage()}\n"; file_put_contents($OUT_FILE, PHP_EOL, FILE_APPEND | LOCK_EX); usleep(200000); continue; }
    list($name,$phone,$email) = extract_fields_from_detail($html);
    // strip leading label like "Název podniku:" and normalize whitespace
    $name = preg_replace('/^\s*Název podniku:\s*/iu','',$name);
    $name = trim(preg_replace('/\s+/',' ',$name));
    $phone = trim($phone);
    $email = trim($email);
    $t1 = microtime(true);
    $ms = round(($t1 - $t0) * 1000);
    echo "  extracted: Name='" . $name . "' | Phone='" . $phone . "' | Email='" . $email . "' | took {$ms} ms\n";
    $line = str_replace(";",",",$name) . ";" . str_replace(";",",",$phone) . ";" . str_replace(";",",",$email) . "\n";
    file_put_contents($OUT_FILE, $line, FILE_APPEND | LOCK_EX);
    // polite delay (ms -> us)
    usleep( ($delay_min_ms * 1000) + rand(0, max(0, ($delay_max_ms - $delay_min_ms) * 1000)) );
}

echo "Finished. Results: $OUT_FILE\n";
