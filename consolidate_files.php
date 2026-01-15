<?php
// Usage: php consolidate_files.php [path/to/files_dir]
// Default path: ./files

$dir = $argv[1] ?? __DIR__ . '/files';
if (!is_dir($dir)) {
    fwrite(STDERR, "Directory not found: $dir\n");
    exit(1);
}

$masterRows = [];
$masterHeaders = [];
$seenEmails = [];
$seenPhones = [];
$perCategoryEmails = [];
$perCategoryPhones = [];

function normalize_email($e) {
    $e = trim(strtolower($e));
    return $e === '' ? null : $e;
}

function normalize_phone($p) {
    $d = trim($p);
    if ($d === '') return null;
    // keep leading + if present, otherwise digits only
    $hasPlus = strpos($d, '+') === 0;
    $digits = preg_replace('/[^0-9]/', '', $d);
    if ($digits === '') return null;
    return $hasPlus ? '+' . $digits : $digits;
}

function extract_emails($text) {
    if (!$text) return [];
    preg_match_all('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $text, $m);
    $out = [];
    foreach ($m[0] as $e) { $ne = normalize_email($e); if ($ne) $out[] = $ne; }
    return array_values(array_unique($out));
}

function extract_phones($text) {
    if (!$text) return [];
    $out = [];
    // normalize HTML <br> and common separators to pipe
    $s = str_ireplace(["<br>", "<br/>", "<br />", "|", ",", ";", "/", "\\\n", "\n", "\r"], "|", $text);
    // collapse multiple pipes/spaces
    $s = preg_replace('/\|+/', '|', $s);
    $tokens = explode('|', $s);

    foreach ($tokens as $token) {
        $token = trim($token);
        if ($token === '') continue;
        // keep only digits, plus and spaces for analysis
        $clean = preg_replace('/[^0-9+ ]/', '', $token);
        if ($clean === '') continue;

        // extract contiguous digit groups (separated by spaces or other non-digits)
        preg_match_all('/\d+/', $clean, $g);
        $groups = $g[0];
        if (count($groups) === 0) continue;

        // if there's a single long group, split it
        if (count($groups) === 1) {
            $digits = $groups[0];
            if (strlen($digits) <= 12) {
                $np = normalize_phone($digits);
                if ($np) $out[] = $np;
            } else {
                $chunks = split_long_digits($digits);
                foreach ($chunks as $chunk) { if ($chunk) { $np = normalize_phone($chunk); if ($np) $out[] = $np; } }
            }
            continue;
        }

        // multiple groups: try to assemble reasonable phone numbers by concatenating adjacent groups
        $i = 0;
        $n = count($groups);
        while ($i < $n) {
            $curr = $groups[$i];
            $i++;
            // extend until length in [6,12] or no more groups
            while (strlen($curr) < 6 && $i < $n) {
                $curr .= $groups[$i];
                $i++;
            }
            $len = strlen($curr);
            if ($len <= 12 && $len >= 6) {
                $np = normalize_phone($curr);
                if ($np) $out[] = $np;
                continue;
            }
            if ($len > 12) {
                $chunks = split_long_digits($curr);
                foreach ($chunks as $chunk) { if ($chunk) { $np = normalize_phone($chunk); if ($np) $out[] = $np; } }
                continue;
            }
            // if shorter than 6 (unlikely), skip
        }
    }
    return array_values(array_unique($out));
}

function split_long_digits($digits) {
    $len = strlen($digits);
    $sizes = [9,10];
    // DP to find sequence of sizes summing to len
    $dp = array_fill(0, $len+1, false);
    $prev = array_fill(0, $len+1, -1);
    $dp[0] = true;
    for ($i=0;$i<=$len;$i++) {
        if (!$dp[$i]) continue;
        foreach ($sizes as $s) {
            if ($i + $s <= $len && !$dp[$i+$s]) {
                $dp[$i+$s] = true;
                $prev[$i+$s] = $s;
            }
        }
    }
    $res = [];
    if ($dp[$len]) {
        // backtrack
        $idx = $len;
        $parts = [];
        while ($idx > 0) {
            $s = $prev[$idx];
            if ($s <= 0) break;
            array_unshift($parts, $s);
            $idx -= $s;
        }
        $pos = 0;
        foreach ($parts as $s) {
            $res[] = substr($digits, $pos, $s);
            $pos += $s;
        }
        return $res;
    }
    // fallback: greedy split into 9-digit chunks, last chunk may be shorter
    $pos = 0;
    while ($pos < $len) {
        $take = min(9, $len - $pos);
        $res[] = substr($digits, $pos, $take);
        $pos += $take;
    }
    return $res;
}

$files = glob(rtrim($dir, '/') . '/*.csv');
sort($files);
foreach ($files as $file) {
    $base = basename($file);
    // derive category from filename, remove extension and common prefix
    $cat = preg_replace('/\.csv$/i','',$base);
    $cat = preg_replace('/^zoznam_sk[_-]?/i','',$cat);

    $fh = fopen($file, 'r');
    if (!$fh) continue;
    // detect delimiter by checking first line for semicolon
    $first = fgets($fh);
    rewind($fh);
    $delimiter = strpos($first, ';') !== false ? ';' : ',';

    $headers = fgetcsv($fh, 0, $delimiter);
    if ($headers === false) { fclose($fh); continue; }
    $headers = array_map('trim', $headers);

    // map header indexes
    while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
        // build associative row
        $assoc = [];
        for ($i=0;$i<count($headers);$i++) {
            $assoc[$headers[$i]] = isset($row[$i]) ? $row[$i] : '';
        }
        $assoc['category'] = $cat;

        // extract contact identifiers
        $allEmails = [];
        $allPhones = [];
        foreach ($assoc as $v) {
            if (!$v) continue;
            $emails = extract_emails($v);
            $phones = extract_phones($v);
            if ($emails) $allEmails = array_merge($allEmails, $emails);
            if ($phones) $allPhones = array_merge($allPhones, $phones);
        }
        $allEmails = array_values(array_unique($allEmails));
        $allPhones = array_values(array_unique($allPhones));

        // deduplicate: skip if any email or phone already seen globally
        $isDup = false;
        foreach ($allEmails as $e) { if (isset($seenEmails[$e])) { $isDup = true; break; } }
        if (!$isDup) {
            foreach ($allPhones as $p) { if (isset($seenPhones[$p])) { $isDup = true; break; } }
        }

        if ($isDup) continue;

        // mark seen
        foreach ($allEmails as $e) { $seenEmails[$e] = true; $perCategoryEmails[$cat][$e]=true; }
        foreach ($allPhones as $p) { $seenPhones[$p] = true; $perCategoryPhones[$cat][$p]=true; }

        // collect headers union
        foreach ($assoc as $k=>$v) { if (!in_array($k, $masterHeaders)) $masterHeaders[] = $k; }
        $masterRows[] = $assoc;
    }
    fclose($fh);
}

// write master CSV
$outFile = rtrim($dir,'/') . '/master_with_category.csv';
$outF = fopen($outFile,'w');
// use semicolon delimiter
fputcsv($outF, $masterHeaders, ';');
foreach ($masterRows as $r) {
    $line = [];
    foreach ($masterHeaders as $h) $line[] = $r[$h] ?? '';
    fputcsv($outF, $line, ';');
}
fclose($outF);

// write stats per category
$statsFile = rtrim($dir,'/') . '/stats_by_category.csv';
$sF = fopen($statsFile,'w');
fputcsv($sF, ['category','unique_emails','unique_phones'], ';');
$portalEmails = [];
$portalPhones = [];
foreach ($perCategoryEmails as $cat=>$set) {
    $eCount = count($set);
    $pCount = isset($perCategoryPhones[$cat]) ? count($perCategoryPhones[$cat]) : 0;
    fputcsv($sF, [$cat, $eCount, $pCount], ';');
    foreach ($set as $k=>$v) $portalEmails[$k]=true;
    if (isset($perCategoryPhones[$cat])) foreach ($perCategoryPhones[$cat] as $k=>$v) $portalPhones[$k]=true;
}
// portal summary row
fputcsv($sF, ['__PORTAL_SUMMARY__', count($portalEmails), count($portalPhones)], ';');
fclose($sF);

// final overall stats across this run
echo "Master file written: $outFile\n";
echo "Per-category stats: $statsFile\n";
echo "Portal unique emails: " . count($portalEmails) . "\n";
echo "Portal unique phones: " . count($portalPhones) . "\n";

// also print a simple table to stdout
echo "\nCategory,UniqueEmails,UniquePhones\n";
foreach ($perCategoryEmails as $cat=>$set) {
    $eCount = count($set);
    $pCount = isset($perCategoryPhones[$cat]) ? count($perCategoryPhones[$cat]) : 0;
    echo "$cat,$eCount,$pCount\n";
}
echo "__PORTAL_SUMMARY__," . count($portalEmails) . "," . count($portalPhones) . "\n";

exit(0);
