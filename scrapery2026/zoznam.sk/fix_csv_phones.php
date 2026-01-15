<?php
$dir = $argv[1] ?? __DIR__ . '/files';
if (!is_dir($dir)) {
    fwrite(STDERR, "Directory not found: $dir\n");
    exit(2);
}

$files = glob(rtrim($dir, '/\\') . '/*.csv');
if (!$files) {
    echo "No CSV files found in $dir\n";
    exit(0);
}

function fix_phone_field($s) {
    $s = trim($s, " \t\n\r\0\x0B\"'");
    if ($s === '') return $s;

    // Normalize common <br> variants to pipe
    $s = str_ireplace(['<br>', '<br/>', '<br />', "\n", "\r\n"], ' | ', $s);
    // Decode HTML entities and strip other tags
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5);
    $s = preg_replace('/<[^>]+>/', '', $s);
    // Normalize existing pipes and whitespace
    $s = preg_replace('/\s*\|\s*/', ' | ', $s);
    $s = preg_replace('/\s+/', ' ', $s);
    $s = trim($s, " \t\n\r\0\x0B|,");

    // If there are pipes already, split and ensure each part is a cleaned phone
    $parts = preg_split('/\s*\|\s*/', $s);
    $fixed_parts = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '') continue;

        // Skip pure decimal numbers (likely coordinates) like 19.6483612
        $p_nospace = trim(str_replace(' ', '', $p));
        if (preg_match('/^\d+\.\d+$/', $p_nospace)) continue;

        // Extract digit groups inside this part
        preg_match_all('/\d+/', $p, $m);
        $groups = $m[0];

        // If multiple digit-groups and at least two are relatively long (>=7), split them as separate phones
        $longCount = 0;
        foreach ($groups as $g) if (strlen($g) >= 7) $longCount++;
        if (count($groups) > 1 && $longCount >= 2) {
            // join groups into plausible phone numbers (keep original grouping boundaries)
            foreach ($groups as $g) {
                $fixed_parts[] = preg_replace('/(\d{3})(?=\d)/', '$1 ', $g);
            }
        } else {
            $fixed_parts[] = $p;
        }
    }

    // If nothing produced, return original cleaned string
    if (empty($fixed_parts)) return $s;

    // Final normalize: remove duplicates and keep order
    $seen = [];
    $out = [];
    foreach ($fixed_parts as $f) {
        $ff = trim($f);
        if ($ff === '') continue;
        if (!isset($seen[$ff])) { $seen[$ff]=1; $out[]=$ff; }
    }

    return implode(' | ', $out);
}

foreach ($files as $file) {
    echo "Processing: $file\n";
    $bak = $file . '.bak';
    if (!file_exists($bak)) copy($file, $bak);

    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if (!$lines) continue;
    $header = array_shift($lines);

    $out = [$header];
    foreach ($lines as $line) {
        // Parse CSV with semicolon delimiter
        $cols = str_getcsv($line, ';', '"');
        if (!$cols) { $out[] = $line; continue; }

        // Telefon is usually the last column
        $idx = count($cols) - 1;
        $orig = $cols[$idx];
        $fixed = fix_phone_field($orig);
        // Preserve quotes if necessary
        if (strpos($fixed, ';') !== false || strpos($fixed, '"') !== false) {
            $fixed = '"' . str_replace('"', '""', $fixed) . '"';
        }
        $cols[$idx] = $fixed;

        // Recreate CSV line using semicolon sep and quotes where needed
        foreach ($cols as &$c) {
            // if contains semicolon or pipe or leading/trailing space, wrap in quotes
            if (preg_match('/;|\||^\s|\s$|\"/', $c)) {
                $c = '"' . str_replace('"', '""', $c) . '"';
            }
        }
        $out[] = implode(';', $cols);
    }

    file_put_contents($file, implode("\n", $out) . "\n");
}

echo "Done. Backups saved as .bak files.\n";
