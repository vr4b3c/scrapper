<?php
$outDir = __DIR__ . '/files';
$pattern = $outDir . '/zoznam_sk_*.csv';
$masterFile = $outDir . '/zoznam_sk_master.csv';

if (!is_dir($outDir)) {
    fwrite(STDERR, "Missing files directory: $outDir\n");
    exit(1);
}

$files = glob($pattern);
if (!$files) {
    echo "No category CSV files found (pattern: $pattern)\n";
    exit(0);
}

$masterFp = fopen($masterFile, 'w');
if (!$masterFp) {
    fwrite(STDERR, "Unable to open master file for writing: $masterFile\n");
    exit(1);
}

$headerWritten = false;
$countRows = 0;

foreach ($files as $file) {
    // skip the master if it matches the pattern
    if (realpath($file) === realpath($masterFile)) {
        continue;
    }

    $base = pathinfo($file, PATHINFO_FILENAME); // zoznam_sk_Category-name
    $category = preg_replace('/^zoznam_sk_/', '', $base);
    $category = str_replace('-', ' ', $category);

    if (($fp = fopen($file, 'r')) === false) {
        continue;
    }

    $header = fgetcsv($fp);
    if (!$header) { fclose($fp); continue; }

    if (!$headerWritten) {
        $header[] = 'kategorie';
        fputcsv($masterFp, $header);
        $headerWritten = true;
    }

    while (($row = fgetcsv($fp)) !== false) {
        $row[] = $category;
        fputcsv($masterFp, $row);
        $countRows++;
    }

    fclose($fp);
}

fclose($masterFp);
echo "Master saved: $masterFile (rows: $countRows)\n";
