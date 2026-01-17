<?php
// merge_to_master.php (moved to project root)
// Merge per-category CSVs into one master CSV with Category column.

if (php_sapi_name() !== 'cli') { echo "Run from CLI only\n"; exit(1); }

$opts = getopt('', ['indir::', 'outfile::', 'verbose']);
// default to e-chalupy files
$defaultIndir = 'scrapery2026/e-chalupy/files';
$indir = $opts['indir'] ?? $defaultIndir;
$projectRoot = dirname(__FILE__);
$outfile = $opts['outfile'] ?? ($projectRoot . '/' . rtrim($indir, '/') . '/echalupy_master.csv');
$verbose = isset($opts['verbose']);

if ($indir && strpos($indir, '/') !== 0) {
    $indir = $projectRoot . '/' . $indir;
}
if ($outfile && strpos($outfile, '/') !== 0) {
    $outfile = $projectRoot . '/' . $outfile;
}

if (!is_dir($indir)) { echo "Input directory not found: $indir\n"; exit(1); }

$files = glob(rtrim($indir, '/') . '/*.csv');
$rows = [];
$categories_for_key = [];

foreach ($files as $f) {
    if (realpath($f) === realpath($outfile)) continue;
    if ($verbose) echo "Reading $f\n";
    $base = basename($f);
    if (preg_match('/dokempucz_(.+)\.csv$/', $base, $m)) {
        $cat = $m[1];
    } elseif (preg_match('/www-dokempu-cz-(.+)\.csv$/', $base, $m)) {
        $cat = $m[1];
    } else {
        $cat = preg_replace('/\.csv$/', '', $base);
    }
    $lines = file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) continue;
    $start = 0;
    $first = $lines[0];
    if (stripos($first, 'name') !== false && stripos($first, 'phone') !== false) $start = 1;
    for ($i = $start; $i < count($lines); $i++) {
        $ln = trim($lines[$i]);
        if ($ln === '') continue;
        $parts = explode(';', $ln);
        $name = trim($parts[0] ?? '');
        $phone = trim($parts[1] ?? '');
        $email = trim($parts[2] ?? '');
        $key = mb_strtolower($name . '|' . $phone . '|' . $email);
        if (!isset($rows[$key])) {
            $rows[$key] = [$name, $phone, $email];
            $categories_for_key[$key] = [];
        }
        if (!in_array($cat, $categories_for_key[$key], true)) $categories_for_key[$key][] = $cat;
    }
}

$out = fopen($outfile, 'w');
if (!$out) { echo "Failed to open $outfile for writing\n"; exit(1); }
fwrite($out, "Name;Phone;Email;Category\n");
foreach ($rows as $key => $vals) {
    $cats = implode('|', $categories_for_key[$key]);
    $line = implode(';', [$vals[0], $vals[1], $vals[2], $cats]);
    fwrite($out, $line . "\n");
}
fclose($out);
if ($verbose) echo "Wrote master: $outfile\n";
echo "done\n";
