<?php
// dedupe_files.php (moved to project root)
// Remove duplicates inside each file in the given directory.

if (php_sapi_name() !== 'cli') {
    echo "Run from CLI only\n";
    exit(1);
}

// use required-value options so both `--indir=value` and `--indir value` work
$opts = getopt('', ['indir:', 'file:', 'verbose']);
// Default input directory when none provided (project-root relative)
$defaultIndir = 'scrapery2026/e-chalupy/files';
$indir = $opts['indir'] ?? $defaultIndir;
$fileOpt = $opts['file'] ?? null;
$verbose = isset($opts['verbose']);

// Resolve paths relative to project root (this file is in project root)
$projectRoot = dirname(__FILE__);
if ($indir && strpos($indir, '/') !== 0) {
    $indir = $projectRoot . '/' . $indir;
}
if ($fileOpt && strpos($fileOpt, '/') !== 0) {
    // try project-root relative candidate
    $candidate = $projectRoot . '/' . $fileOpt;
    if (file_exists($candidate)) $fileOpt = $candidate;
}

if (!is_dir($indir)) { echo "Input directory not found: $indir\n"; exit(1); }

// CSV files
if ($fileOpt && file_exists($fileOpt) && preg_match('/\.csv$/i', $fileOpt)) {
    $csvs = [$fileOpt];
} else {
    // try in indir if filename provided
    if ($fileOpt) {
        $try = rtrim($indir, '/') . '/' . $fileOpt;
        if (file_exists($try) && preg_match('/\.csv$/i', $try)) $csvs = [$try];
        else $csvs = array_filter(glob(rtrim($indir, '/') . '/*.csv') ?: [], function($p){ return !preg_match('/_DEDUPLICATED\\.csv$/i', $p); });
    } else {
        $csvs = array_filter(glob(rtrim($indir, '/') . '/*.csv') ?: [], function($p){ return !preg_match('/_DEDUPLICATED\\.csv$/i', $p); });
    }
}

foreach ($csvs as $csv) {
    if ($verbose) echo "Processing CSV: $csv\n";
    $lines = file($csv, FILE_IGNORE_NEW_LINES);
    if ($lines === false) continue;
    if (count($lines) === 0) continue;
    $header = $lines[0];
    $out = [];
    $seen = [];
    $removed = [];
    // preserve header line
    $start = 0;
    // if header contains Name;Phone;Email keep it
    if (stripos($header, 'name') !== false && stripos($header, 'phone') !== false) {
        $out[] = $header;
        $start = 1;
    }
    for ($i = $start; $i < count($lines); $i++) {
        $ln = trim($lines[$i]);
        if ($ln === '') continue;
        // Use the full line as dedupe key after normalizing whitespace
        $key = preg_replace('/\s+/', ' ', trim($ln));
        $key_l = mb_strtolower($key);
        if (isset($seen[$key_l])) { $removed[] = ['dup' => $ln, 'orig' => $seen[$key_l]]; continue; }
        $seen[$key_l] = $ln;
        $out[] = $ln;
    }
    // write deduped copy, do not overwrite original
    $dst = preg_replace('/\.csv$/i', '_DEDUPLICATED.csv', $csv);
    $tmp = $dst . '.tmp';
    file_put_contents($tmp, implode("\n", $out) . "\n");
    rename($tmp, $dst);
    if ($verbose) {
        echo "Wrote deduped copy: $dst\n";
        if (!empty($removed)) {
            echo "Removed duplicates (" . count($removed) . ") from $csv (copied to $dst):\n";
            foreach ($removed as $pair) echo "- DUP: {$pair['dup']}  <-- duplicate of -->  ORIG: {$pair['orig']}\n";
        } else {
            echo "No duplicates found in $csv (copied to $dst)\n";
        }
    }
}

// URL / candidate lists (*.txt)
if ($fileOpt && file_exists($fileOpt) && preg_match('/\.txt$/i', $fileOpt)) {
    $txts = [$fileOpt];
} else {
    if ($fileOpt) {
        $try1 = rtrim($indir, '/') . '/' . $fileOpt;
        $try2 = rtrim($indir, '/') . '/' . $fileOpt . '_urls.txt';
        $try3 = rtrim($indir, '/') . '/' . $fileOpt . '_candidates.txt';
        $txts = [];
        if (file_exists($try1)) $txts[] = $try1;
        if (file_exists($try2)) $txts[] = $try2;
        if (file_exists($try3)) $txts[] = $try3;
        if (empty($txts)) $txts = array_filter(glob(rtrim($indir, '/') . '/*_candidates.txt') ?: [], function($p){ return !preg_match('/_DEDUPLICATED\\.txt$/i', $p); });
    } else {
        $txts = array_filter(glob(rtrim($indir, '/') . '/*_candidates.txt') ?: [], function($p){ return !preg_match('/_DEDUPLICATED\\.txt$/i', $p); });
        if (!$txts) $txts = array_filter(glob(rtrim($indir, '/') . '/*_urls.txt') ?: [], function($p){ return !preg_match('/_DEDUPLICATED\\.txt$/i', $p); });
    }
}

foreach ($txts as $txt) {
    if ($verbose) echo "Processing TXT: $txt\n";
    $lines = file($txt, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) continue;
    $seen = [];
    $out = [];
    $removed = [];
    foreach ($lines as $ln) {
        $u = trim($ln);
        if ($u === '') continue;
        if (isset($seen[$u])) { $removed[] = ['dup' => $u, 'orig' => $seen[$u]]; continue; }
        $seen[$u] = $u;
        $out[] = $u;
    }
    $dst = preg_replace('/\.txt$/i', '_DEDUPLICATED.txt', $txt);
    $tmp = $dst . '.tmp';
    file_put_contents($tmp, implode("\n", $out) . "\n");
    rename($tmp, $dst);
    if ($verbose) {
        echo "Wrote deduped copy: $dst\n";
        if (!empty($removed)) {
            echo "Removed duplicates (" . count($removed) . ") from $txt (copied to $dst):\n";
            foreach ($removed as $pair) echo "- DUP: {$pair['dup']}  <-- duplicate of -->  ORIG: {$pair['orig']}\n";
        } else {
            echo "No duplicates found in $txt (copied to $dst)\n";
        }
    }
}

echo "done\n";


