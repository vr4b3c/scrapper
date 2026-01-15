<?php
// Analyze numeric tokens in Telefon column and report repeats
$csv = $argv[1] ?? __DIR__ . '/files/zoznam_sk.csv';
if (!is_file($csv)) { fwrite(STDERR, "CSV not found: $csv\n"); exit(2); }

$fh = fopen($csv, 'r'); if (!$fh) { fwrite(STDERR, "Failed open: $csv\n"); exit(2); }

$counts = [];
$examples = [];
$line = 0;
while (($row = fgetcsv($fh, 0, ';', '"')) !== false) {
    $line++;
    if ($line === 1) continue; // header
    $phone = isset($row[2]) ? trim($row[2]) : '';
    if ($phone === '') continue;

    // Split by newlines first
    $parts = preg_split('/[\r\n]+/', $phone);
    foreach ($parts as $part) {
        // split into numeric-like tokens (allow digits and dot)
        $tokens = preg_split('/[^0-9.\+]+/', $part);
        foreach ($tokens as $t) {
            $t = trim($t);
            if ($t === '') continue;
            // normalize: remove leading + for counting but keep dot for decimals
            $norm = ltrim($t, '+');
            // accept tokens that are digits or decimals (contains at least one digit)
            if (!preg_match('/[0-9]/', $norm)) continue;
            // ignore typical phone-like long tokens? we'll count all and present top ones
            $counts[$norm] = ($counts[$norm] ?? 0) + 1;
            if (!isset($examples[$norm])) $examples[$norm] = [];
            if (count($examples[$norm]) < 5) $examples[$norm][] = ['line'=>$line,'raw'=>$phone];
        }
    }
}
fclose($fh);

arsort($counts);
$top = array_slice($counts, 0, 40, true);
echo "Top numeric tokens in Telefon column (token => count):\n";
foreach ($top as $tok => $c) {
    echo "- $tok => $c\n";
}

echo "\nExamples for repeated tokens:\n";
foreach ($top as $tok => $c) {
    if ($c <= 1) continue;
    echo "\nToken: $tok (count=$c)\n";
    foreach ($examples[$tok] as $ex) {
        echo "  line {$ex['line']}: {$ex['raw']}\n";
    }
}

// Save full list for inspection
file_put_contents(dirname($csv) . '/numeric_token_counts.json', json_encode($counts, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
echo "\nWrote numeric_token_counts.json\n";

return 0;
