<?php
// Simple test harness for phone parsing heuristics similar to zoznam_sk.php
function split_digits_dp($digitsOnly) {
    $n = strlen($digitsOnly);
    $allowed = [10,9,7,6];
    $dp = array_fill(0, $n+1, false);
    $prev = array_fill(0, $n+1, -1);
    $dp[0] = true;
    for ($i=0;$i<$n;$i++) {
        if (!$dp[$i]) continue;
        foreach ($allowed as $len) {
            if ($i + $len <= $n) {
                if (!$dp[$i+$len]) {
                    $dp[$i+$len] = true;
                    $prev[$i+$len] = $len;
                }
            }
        }
    }
    $chunks = [];
    if ($dp[$n]) {
        $pos = $n;
        while ($pos > 0) {
            $len = $prev[$pos];
            if ($len <= 0) break;
            $chunks[] = substr($digitsOnly, $pos - $len, $len);
            $pos -= $len;
        }
        $chunks = array_reverse($chunks);
    } else {
        preg_match_all('/\d{9}/', $digitsOnly, $mch);
        $chunks = $mch[0];
        $rest = substr($digitsOnly, count($mch[0]) * 9);
        if ($rest !== '') $chunks[] = $rest;
    }
    return $chunks;
}

function parse_like_parser($s) {
    $s2 = str_ireplace(['<br>', '<br/>', '<br />', "\n", "\r\n"], '|', $s);
    $s2 = preg_replace('/\s*\|\s*/', '|', $s2);
    $parts = preg_split('/\|/', $s2);
    $out = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '') continue;

        // find digit-like candidates
        if (preg_match_all('/[+0-9][0-9 \-\/()\.]{5,}[0-9]/', $p, $m)) {
            foreach ($m[0] as $cand) {
                $candidate = trim($cand);
                $digitsOnly = preg_replace('/\D/', '', $candidate);
                if (strlen($digitsOnly) < 7) continue;

                // build token boundaries
                $boundaries = [];
                if (preg_match('/\s+/', $candidate)) {
                    $tokens = preg_split('/\s+/', $candidate);
                    $pos = 0;
                    foreach ($tokens as $t) {
                        $d = preg_replace('/\D/', '', $t);
                        $pos += strlen($d);
                        $boundaries[$pos] = true;
                    }
                }

                // smart DP over digitsOnly
                $n = strlen($digitsOnly);
                $dp = array_fill(0, $n+1, PHP_INT_MAX);
                $prev = array_fill(0, $n+1, -1);
                $dp[0] = 0;
                $allowed = [10,9,7,6];
                for ($i=0;$i<$n;$i++) {
                    if ($dp[$i] === PHP_INT_MAX) continue;
                    foreach ($allowed as $len) {
                        if ($i + $len > $n) continue;
                        $chunk = substr($digitsOnly, $i, $len);
                        $penalty = abs($len - 9);
                        if ($chunk[0] === '0') $penalty -= 3;
                        $endPos = $i + $len;
                        if (isset($boundaries[$endPos])) $penalty -= 2;
                        $cost = max(0, $penalty);
                        if ($dp[$i] + $cost < $dp[$i+$len]) {
                            $dp[$i+$len] = $dp[$i] + $cost;
                            $prev[$i+$len] = $len;
                        }
                    }
                }
                $chunks = [];
                if ($dp[$n] !== PHP_INT_MAX) {
                    $pos = $n;
                    while ($pos > 0) {
                        $len = $prev[$pos];
                        if ($len <= 0) break;
                        $chunks[] = substr($digitsOnly, $pos - $len, $len);
                        $pos -= $len;
                    }
                    $chunks = array_reverse($chunks);
                } else {
                    preg_match_all('/\d{9}/', $digitsOnly, $mch);
                    $chunks = $mch[0];
                    $rest = substr($digitsOnly, count($mch[0]) * 9);
                    if ($rest !== '') $chunks[] = $rest;
                }

                foreach ($chunks as $ch) $out[] = $ch;
            }
            continue;
        }
        // fallback: if no regex matches, take numeric chunks
        $digitsOnly = preg_replace('/\D/', '', $p);
        if (strlen($digitsOnly) >= 12) {
            $chunks = split_digits_dp($digitsOnly);
            foreach ($chunks as $ch) $out[] = $ch;
        } elseif (strlen($digitsOnly) >= 6) {
            $out[] = $p;
        }
    }
    return $out;
}

function test($label, $s) {
    echo "-- $label --\n";
    echo "orig: $s\n";
    $parsed = parse_like_parser($s);
    echo "parsed: " . implode(' | ', $parsed) . "\n\n";
}

$samples = [
    'HOREZZA' => "02 3210 6000<br>0914 324 188<br>033 7983 111<br>052 4422 751<br>",
    'Adonai' => "048 4164 9890948 642 4050905 642 4050905 433 383 | 048 4164 989",
    'Briatkova' => "0903 767 319033 55 430 22 | 0903 767 319",
];

foreach ($samples as $k => $v) test($k, $v);

echo "Done tests.\n";
