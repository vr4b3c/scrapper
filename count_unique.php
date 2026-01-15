<?php
// Count unique emails and phones in master CSV
$csv = $argv[1] ?? __DIR__ . '/files/zoznam_sk.csv';
if (!is_file($csv)) {
    fwrite(STDERR, "CSV not found: $csv\n");
    exit(2);
}

$fh = fopen($csv, 'r');
if (!$fh) { fwrite(STDERR, "Failed open: $csv\n"); exit(2); }

$emails = [];
$phones = [];
$line = 0;
while (($row = fgetcsv($fh, 0, ';', '"')) !== false) {
    $line++;
    if ($line === 1) continue; // header
    // Ensure we have at least 3 columns: name;email;phone;...
    $email = isset($row[1]) ? trim($row[1]) : '';
    $phoneField = isset($row[2]) ? trim($row[2]) : '';

    if ($email !== '') {
        $e = mb_strtolower($email);
        $emails[$e] = true;
    }

    if ($phoneField !== '') {
        // Split on explicit newlines or separator '|' used when multiple phones are joined
        $parts = preg_split('/[\r\n]+|\s*\|\s*/', $phoneField);
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '') continue;
            // extract phone-like substrings (keep formatted)
            if (preg_match_all('/[+0-9][0-9 \-\/().]{5,}[0-9]/u', $p, $m)) {
                foreach ($m[0] as $cand) {
                    $norm = preg_replace('/[^0-9+]/', '', $cand);
                    // normalize leading zeros: keep as-is
                    if (strlen(preg_replace('/\D/', '', $norm)) < 7) continue;
                    $phones[$norm] = true;
                }
            } else {
                // fallback: if entire field is digits/spaces and long enough
                $norm = preg_replace('/[^0-9+]/', '', $p);
                if (strlen(preg_replace('/\D/', '', $norm)) >= 7) {
                    $phones[$norm] = true;
                }
            }
        }
    }
}
fclose($fh);

$emailCount = count($emails);
$phoneCount = count($phones);
echo "Unique emails: $emailCount\n";
echo "Unique phones: $phoneCount\n";

// Save lists
$outDir = dirname($csv);
file_put_contents($outDir . '/unique_emails.txt', implode("\n", array_keys($emails)));
file_put_contents($outDir . '/unique_phones.txt', implode("\n", array_keys($phones)));

echo "Wrote: $outDir/unique_emails.txt and unique_phones.txt\n";

return 0;
