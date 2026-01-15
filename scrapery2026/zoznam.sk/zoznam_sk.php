<?php

// Scraper pro zoznam.sk katalog - stáhne listingy, projde paginaci a detaily
// Výstup: CSV se sloupci Název;E-mail;Telefon

// use new root DOM wrapper instead of historical scrappery version
// script lives in scrapery2026/zoznam.sk/, helpers and simple_dom are in repo root
include_once __DIR__ . '/../../simple_dom.php';
include_once __DIR__ . '/../../scrappery2025/helpers.php';

ini_set('max_execution_time', 0);

// output directory for CSV files (per-category)
$outDir = __DIR__ . '/files';
if (!is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}

$startUrl = 'https://www.zoznam.sk/katalog/Cestovanie-ubytovanie-turizmus/Ubytovanie/';
$domain = 'https://www.zoznam.sk';

$maxPages = 500; // bezpečnostní strop
$delayMicro = 300000; // 300ms mezi požadavky

// optional detail limit via CLI: --limit=N or env LIMIT
$detailLimit = null;
if (isset($argv) && is_array($argv)) {
    foreach ($argv as $arg) {
        if (strpos($arg, '--limit=') === 0) {
            $v = (int)substr($arg, strlen('--limit='));
            if ($v > 0) $detailLimit = $v;
        }
    }
}
if (!$detailLimit) {
    $env = getenv('LIMIT');
    if ($env !== false && is_numeric($env)) $detailLimit = (int)$env;
}

// allow processing a single detail URL or a file with detail URLs to avoid crawling listings
$singleDetail = null;
$detailFile = null;
if (isset($argv) && is_array($argv)) {
    foreach ($argv as $arg) {
        if (strpos($arg, '--detail=') === 0) {
            $singleDetail = trim(substr($arg, strlen('--detail=')), "\"'");
        }
        if (strpos($arg, '--detail-file=') === 0) {
            $detailFile = trim(substr($arg, strlen('--detail-file=')), "\"'");
        }
    }
}

// support --unlimited to remove built-in page/detail caps for full runs
$unlimited = false;
if (isset($argv) && is_array($argv)) {
    foreach ($argv as $arg) {
        if ($arg === '--unlimited') $unlimited = true;
    }
}

// Per-category page limit (set very high to effectively disable during full runs)
$perCategoryPageLimit = PHP_INT_MAX;
$pagesPerCategory = []; // counts processed pages per category

if ($unlimited) {
    $maxPages = PHP_INT_MAX;
    $perCategoryPageLimit = PHP_INT_MAX;
    if (!$detailLimit) $detailLimit = PHP_INT_MAX;
}

function queued_count_by_category($queue, $category) {
    $c = 0;
    foreach ($queue as $q) if (($q['category'] ?? '') === $category) $c++;
    return $c;
}

$visitedPages = [];
// toVisit holds arrays with url and category label
$toVisit = [];
$detailUrls = []; // map url => category
$startPath = parse_url($startUrl, PHP_URL_PATH) ?: '';

// seed queue
// seed queue with category derived from URL
$toVisit[] = ['url' => $startUrl, 'category' => ''];

function derive_category($url, $startPath) {
    $p = parse_url($url, PHP_URL_PATH) ?: '';
    $parts = array_values(array_filter(explode('/', $p)));
    if (empty($parts)) return '';
    // try to derive category as the segment after the startPath's last segment
    $startParts = array_values(array_filter(explode('/', $startPath)));
    $base = end($startParts);
    if ($base) {
        foreach ($parts as $i => $seg) {
            if ($seg === $base) {
                // next segment exists and is not sekcia.fcgi
                if (isset($parts[$i+1]) && stripos($parts[$i+1], 'sekcia') === false && stripos($parts[$i+1], '.fcgi') === false) {
                    return $parts[$i+1];
                }
                break;
            }
        }
    }
    // fallback: last non-empty segment; if it's sekcia.fcgi use previous
    $last = end($parts);
    if (stripos($last, '.fcgi') !== false && count($parts) >= 2) {
        return $parts[count($parts)-2];
    }
    return $last;
}

function fetch_url($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; scraper/1.0)');
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $html = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 400 || !$html) return false;
    return $html;
}

function normalize_url($href, $baseDomain, $currentUrl = null) {
    $href = trim($href);
    if ($href === '') return null;
    if (strpos($href, '//') === 0) return 'https:' . $href;
    if (preg_match('#^https?://#i', $href)) return $href;
    if (strpos($href, '/') === 0) return rtrim($baseDomain, '/') . $href;
    // relative path without leading slash: resolve against current URL if provided
    if ($currentUrl) {
        $p = parse_url($currentUrl);
        $scheme = $p['scheme'] ?? 'https';
        $host = $p['host'] ?? parse_url($baseDomain, PHP_URL_HOST);
        $path = $p['path'] ?? '/';
        $dir = rtrim(dirname($path), '/');
        if ($dir === '.') $dir = '';
        return $scheme . '://' . $host . $dir . '/' . ltrim($href, './');
    }
    return null;
}

echo "Starting scraper for: $startUrl\n";

// Crawl listing pages (BFS) to collect detail links and pagination links
$skipCrawl = false;
if ($singleDetail || $detailFile) $skipCrawl = true;
if (!$skipCrawl) {
    while (!empty($toVisit) && count($visitedPages) < $maxPages) {
        $item = array_shift($toVisit);
        $page = $item['url'];
        $currentCategory = $item['category'] ?? '';
        if (isset($visitedPages[$page])) continue;

    // pokud kategorie není v itemu, odhadneme ji z URL path krátce před fetch
    if (empty($currentCategory)) {
        $p = parse_url($page, PHP_URL_PATH) ?: '';
        $parts = array_values(array_filter(explode('/', $p)));
        $currentCategory = end($parts) ?: $startPath;
    }

    // pokud už jsme překročili limit pro tuto kategorii, přeskočíme (testovací režim)
    $countProcessed = $pagesPerCategory[$currentCategory] ?? 0;
    $countQueued = queued_count_by_category($toVisit, $currentCategory);
    if ($countProcessed + $countQueued >= $perCategoryPageLimit) {
        echo "Skipping page (limit reached for category): $page (category=$currentCategory)\n";
        $visitedPages[$page] = true;
        continue;
    }

    echo "Fetching page: $page\n";
    $htmlContent = fetch_url($page);
    if (!$htmlContent) {
        echo "  Failed to fetch $page\n";
        $visitedPages[$page] = true;
        usleep($delayMicro);
        continue;
    }

    $html = str_get_html($htmlContent);
    if (!$html) {
        $visitedPages[$page] = true;
        usleep($delayMicro);
        continue;
    }

    // detect category label if not set
    if (empty($currentCategory)) {
        $h1 = $html->find('h1', 0);
        if ($h1) {
            $currentCategory = trim($h1->plaintext);
        } else {
            $p = parse_url($page, PHP_URL_PATH) ?: '';
            $parts = array_values(array_filter(explode('/', $p)));
            $currentCategory = end($parts) ?: $startPath;
        }
    }

    // 1) Pokud stránka obsahuje seznam záznamů, extrahuj odkazy na detaily
    $catalog = $html->find('.row.catalog-list', 0);
    // Collect detail links that point to /firma/ (detail pages live under /firma/)
    foreach ($html->find('a') as $a) {
        $href = trim($a->href ?? '');
        if (!$href) continue;
        $full = normalize_url($href, $domain, $page);
        if (!$full) continue;
        $path = parse_url($full, PHP_URL_PATH) ?: '';
        if (stripos($path, '/firma/') !== false) {
            $detailUrls[$full] = $currentCategory;
        }
    }

    // If there's a paginator element, enqueue pagination links (stay within startPath)
    $paginator = $html->find('.row.paginator', 0);
    if ($paginator) {
        foreach ($paginator->find('a') as $pa) {
            $href = $pa->href ?? '';
            $full = normalize_url($href, $domain, $page);
            if (!$full) continue;
            $path = parse_url($full, PHP_URL_PATH) ?: '';
            if ($startPath && strpos($path, $startPath) === 0) {
                if (!isset($visitedPages[$full])) {
                    $exists = false;
                    foreach ($toVisit as $q) { if ($q['url'] === $full) { $exists = true; break; } }
                    if (!$exists && count($visitedPages) + count($toVisit) < $maxPages) {
                        // paginator links are pages of the current listing -> preserve currentCategory
                        $cat = $currentCategory ?: derive_category($full, $startPath);
                        $toVisit[] = ['url' => $full, 'category' => $cat];
                    }
                }
            }
        }
    }

    // If page presents subsections (explicit category links), enqueue them so we cover all subcategories
    $subsections = $html->find('#subsections a.folder');
    if ($subsections) {
        foreach ($subsections as $sa) {
            $href = $sa->href ?? '';
            $full = normalize_url($href, $domain, $page);
            if (!$full) continue;
            $path = parse_url($full, PHP_URL_PATH) ?: '';
            if ($startPath && strpos($path, $startPath) === 0) {
                // avoid duplicates
                $exists = false;
                if (isset($visitedPages[$full])) $exists = true;
                foreach ($toVisit as $q) { if ($q['url'] === $full) { $exists = true; break; } }
                if ($exists) continue;
                $cat = derive_category($full, $startPath);
                $toVisit[] = ['url' => $full, 'category' => $cat];
            }
        }
    }

    // 2) Fallback limited: projdi odkazy na stránce a přidej jen ty v rámci startPath (paginace/subkategorie)
    foreach ($html->find('a') as $a) {
        $href = trim($a->href ?? '');
        if (!$href) continue;
        $full = normalize_url($href, $domain, $page);
        if (!$full) continue;

        $path = parse_url($full, PHP_URL_PATH) ?: '';
        if ($startPath && strpos($path, $startPath) === 0) {
            if (!isset($visitedPages[$full])) {
                $exists = false;
                foreach ($toVisit as $q) { if ($q['url'] === $full) { $exists = true; break; } }
                if (!$exists && count($visitedPages) + count($toVisit) < $maxPages) {
                    // if this is a pagination/sekcia link, preserve the current listing category
                    if (stripos($path, 'sekcia.fcgi') !== false || preg_match('/[?&]page=\d+/i', $full)) {
                        $cat = $currentCategory ?: derive_category($full, $startPath);
                    } else {
                        $cat = derive_category($full, $startPath);
                    }
                    $toVisit[] = ['url' => $full, 'category' => $cat];
                }
            }
        }
    }

    $visitedPages[$page] = true;
    // increment processed count for category
    $pagesPerCategory[$currentCategory] = ($pagesPerCategory[$currentCategory] ?? 0) + 1;
    usleep($delayMicro);
    }
} else {
    // build detailUrlsMap from provided detail(s)
    $detailUrlsMap = [];
    if ($singleDetail) {
        $full = normalize_url($singleDetail, $domain, $startUrl) ?: $singleDetail;
        // if the provided URL is a category (not a /firma/ detail), try to resolve first detail link on that page
        $p = parse_url($full, PHP_URL_PATH) ?: '';
        if (stripos($p, '/firma/') === false) {
            $pageHtml = fetch_url($full);
            if ($pageHtml) {
                $tmp = str_get_html($pageHtml);
                if ($tmp) {
                    foreach ($tmp->find('a') as $a) {
                        $href = trim($a->href ?? '');
                        if (!$href) continue;
                        $cand = normalize_url($href, $domain, $full);
                        if (!$cand) continue;
                        if (stripos(parse_url($cand, PHP_URL_PATH) ?: '', '/firma/') !== false) {
                            $full = $cand;
                            break;
                        }
                    }
                }
            }
        }
        $detailUrlsMap[$full] = '';
    }
    if ($detailFile && file_exists($detailFile)) {
        $lines = file($detailFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $ln) {
            $ln = trim($ln);
            if ($ln === '') continue;
            $full = normalize_url($ln, $domain, $startUrl) ?: $ln;
            $detailUrlsMap[$full] = '';
        }
    }
    $detailUrls = array_keys($detailUrlsMap);
}

// preserve mapping url => category
// $detailUrls may be either a map (url => category) or a numeric array of urls.
$detailUrlsMap = [];
if (is_array($detailUrls)) {
    // associative map (url => category)
    $isAssoc = array_values($detailUrls) !== $detailUrls;
    if ($isAssoc) {
        $detailUrlsMap = $detailUrls;
    } else {
        // numeric array of urls -> map to empty category
        foreach ($detailUrls as $u) {
            $detailUrlsMap[$u] = '';
        }
    }
}
$detailUrls = array_keys($detailUrlsMap);
echo "Found " . count($detailUrls) . " detail URLs (unique)\n";

$results = [];
$processed = 0;

foreach ($detailUrls as $durl) {
    $safety = $detailLimit && $detailLimit > 0 ? $detailLimit : 2000;
    if ($processed >= $safety) break; // safety limit (respect --limit)
    echo "Fetching detail: $durl\n";
    $htmlContent = fetch_url($durl);
    if (!$htmlContent) {
        echo "  Failed to fetch detail $durl\n";
        usleep($delayMicro);
        continue;
    }
    $doc = str_get_html($htmlContent);
    if (!$doc) { usleep($delayMicro); continue; }

    // Název: h1, h2, meta og:title, title
    $name = '';
    if ($el = $doc->find('h1', 0)) $name = trim($el->plaintext);
    if (!$name && ($el = $doc->find('h2', 0))) $name = trim($el->plaintext);
    if (!$name) $name = trim($doc->find('title', 0)->plaintext ?? '');
    if (!$name) $name = trim($doc->find('meta[property=og:title]', 0)->content ?? '');

    // E-mail: mailto nebo regex
    $email = '';
    foreach ($doc->find('a') as $a) {
        if (isset($a->href) && stripos($a->href, 'mailto:') !== false) {
            $email = str_replace('mailto:', '', $a->href);
            break;
        }
    }
    if (!$email) {
        if (preg_match_all('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $doc->plaintext, $m)) {
            $email = $m[0][0] ?? '';
        }
    }

    // Telefon: sbírat více kandidátů, normalizovat a spojit je bezpečným oddělovačem (stejně jako u e-chalupy)
    $phones = [];

    // helper: normalize a zkontroluj zda kandidát vypadá jako telefon
    $add_phone_candidate = function($candidate) use (&$phones) {
        $candidate = trim($candidate);
        if ($candidate === '') return;

        // skip pure decimal numbers (likely coordinates) and coordinate pairs
        if (preg_match('/^[+-]?\d+\.\d+$/', $candidate)) return;
        if (preg_match('/^[+-]?\d+\.\d+\s*[, ]\s*[+-]?\d+\.\d+$/', $candidate)) return;

        // if not enough digits, skip
        $digitsOnly = preg_replace('/\D/', '', $candidate);
        if (strlen($digitsOnly) < 7) return;

        // smart DP splitting over the full digit string; prefer chunks of lengths 10/9/7/6
        $n = strlen($digitsOnly);
        $chunks = [];

        if ($n > 9) {
            $allowed = [10,9,7,6];
            $dp = array_fill(0, $n+1, PHP_INT_MAX);
            $prev = array_fill(0, $n+1, -1);
            $dp[0] = 0;

            // compute token-boundary positions (bonus if split aligns with token boundary)
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

            for ($i = 0; $i < $n; $i++) {
                if ($dp[$i] === PHP_INT_MAX) continue;
                foreach ($allowed as $len) {
                    if ($i + $len > $n) continue;
                    $chunk = substr($digitsOnly, $i, $len);
                    $penalty = abs($len - 9); // prefer 9
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
                // fallback: greedily take 9s
                preg_match_all('/\d{9}/', $digitsOnly, $mch);
                $chunks = $mch[0];
                $rest = substr($digitsOnly, count($mch[0]) * 9);
                if ($rest !== '') $chunks[] = $rest;
            }

            // pokud kandidát obsahoval oddělovače/mezery, zmapuj chunks zpět na původní formát (zachovej mezery)
            if (preg_match('/\D/', $candidate)) {
                $pos = 0;
                $candidateChars = preg_split('//u', $candidate, -1, PREG_SPLIT_NO_EMPTY);
                foreach ($chunks as $ch) {
                    $len = strlen($ch);
                    $startDigit = $pos;
                    $endDigit = $pos + $len;
                    $pos += $len;

                    $digitIndex = 0;
                    $out = '';
                    foreach ($candidateChars as $c) {
                        if (preg_match('/\d/', $c)) {
                            if ($digitIndex >= $startDigit && $digitIndex < $endDigit) $out .= $c;
                            $digitIndex++;
                        } else {
                            if ($digitIndex > $startDigit && $digitIndex <= $endDigit) $out .= $c;
                        }
                    }
                    $out = preg_replace('/\s+/', ' ', trim($out));
                    if ($out !== '') $phones[] = $out; else $phones[] = $ch;
                }
            } else {
                foreach ($chunks as $ch) $phones[] = $ch;
            }
        } else {
            // short-ish candidate: accept original formatting if reasonable
            $hasDot = strpos($candidate, '.') !== false;
            $hasSpace = preg_match('/[ \-()\/]/', $candidate);
            $starts0plus = preg_match('/^[+0]/', $candidate);
            if (!($hasDot && !$hasSpace && !$starts0plus)) {
                $phones[] = $candidate;
            }
        }
    };

    // 1) Hledat elementy s třídou "label" obsahující "telef" a poté najít nejbližší rodičovský .row,
    //     z něho vybrat .col-sm-9/.col-md-9 obsahující telefonní čísla oddělená <br>
    foreach ($doc->find('.label') as $lab) {
        if (stripos($lab->plaintext, 'telef') === false) continue;

        // walk up the DOM to find ancestor with class containing 'row'
        $node = $lab->node;
        $rowNode = null;
        while ($node && $node->parentNode) {
            $node = $node->parentNode;
            if ($node->nodeType !== XML_ELEMENT_NODE) continue;
            $class = $node->getAttribute('class') ?? '';
            if ($class && stripos($class, 'row') !== false) { $rowNode = $node; break; }
        }

        // fallback to two-level parent if not found
        if (!$rowNode) {
            $parent = $lab->node->parentNode;
            if ($parent && $parent->parentNode) $rowNode = $parent->parentNode;
        }

        if ($rowNode) {
            // look for the column that holds the phone (col-sm-9 / col-md-9)
            foreach ($rowNode->childNodes as $child) {
                if ($child->nodeType !== XML_ELEMENT_NODE) continue;
                $class = $child->getAttribute('class') ?? '';
                if (stripos($class, 'col-sm-9') !== false || stripos($class, 'col-md-9') !== false) {
                    $inner = $child->innertext ?? '';
                    // convert <br> variants to pipe, preserve pipes
                    $inner = str_ireplace(['<br>', '<br/>', '<br />'], '|', $inner);
                    $inner = preg_replace('/\s*\|\s*/', '|', $inner);
                    // remove other tags but keep pipes
                    $txt = trim(preg_replace('/<[^>]+>/', '', $inner));
                    if ($txt === '') {
                        $fallback = trim($child->textContent ?? '');
                        $fallback = preg_replace('/[\r\n]+/', '|', $fallback);
                        $fallback = preg_replace('/\s*\|\s*/', '|', $fallback);
                        $txt = $fallback;
                    }
                    if ($txt !== '') {
                        if (strpos($txt, '|') !== false) {
                            $parts = preg_split('/\s*\|\s*/', $txt);
                            foreach ($parts as $p) { $p = trim($p); if ($p !== '') $add_phone_candidate($p); }
                        } else {
                            $add_phone_candidate($txt);
                        }
                        break 2;
                    }
                }
            }
        }
    }

    // 2) fallback: všechny odkazy tel: (může jich být více)
    foreach ($doc->find('a') as $a) {
        if (isset($a->href) && stripos($a->href, 'tel:') !== false) {
            $tel = trim(str_replace('tel:', '', $a->href));
            if ($tel !== '') $add_phone_candidate($tel);
        }
    }

    // 2b) Prefer explicit telephone selectors / microdata before regex fallback
    $foundBySelector = false;
    $selectors = ['[itemprop=telephone]', '[class*=phone]', '[class*=tel]', '.contact-phone'];
    foreach ($selectors as $sel) {
        foreach ($doc->find($sel) as $el) {
            $txt = trim($el->plaintext ?? '');
            if ($txt === '') continue;
            if (strpos($txt, '|') !== false) {
                $parts = preg_split('/\s*\|\s*/', $txt);
                foreach ($parts as $p) { $p = trim($p); if ($p !== '') $add_phone_candidate($p); }
            } else {
                $add_phone_candidate($txt);
            }
            $foundBySelector = true;
        }
        if ($foundBySelector) break;
    }

    // 3) poslední možnost: regex fallback pro celé texty (může vrátit více kandidátů)
    // 3) regex fallback - only if selector search didn't find explicit phones
    if (!$foundBySelector && preg_match_all('/[+0-9][0-9 \-\/()\.]{5,}[0-9]/', $doc->plaintext, $m2)) {
        foreach ($m2[0] as $cand) {
            $trim = trim($cand);
            // skip tokens that look like decimal coordinates (e.g. 19.2795) without separators
            if (strpos($trim, '.') !== false && !preg_match('/[\s\-()\/]/', $trim)) continue;
            $digitsOnly = preg_replace('/\D/', '', $trim);
            if (strlen($digitsOnly) < 7) continue;
            // prefer candidates with separators or leading +/0
            if (preg_match('/[\s\-\/()]/', $trim) || strpos($trim, '+') === 0 || strpos($trim, '0') === 0) {
                $add_phone_candidate($trim);
            } else {
                // if pure digits, accept only when starting with 0
                if (preg_match('/^[0-9]+$/', $trim) && strpos($trim, '0') === 0) {
                    $add_phone_candidate($trim);
                }
            }
        }
    }

    // deduplikace a normalizace (odstraníme duplicitní formáty)
    $normalized = [];
    foreach ($phones as $p) {
        $pNorm = preg_replace('/\s+/', ' ', trim($p));
        if ($pNorm !== '') $normalized[] = $pNorm;
    }
    $normalized = array_values(array_unique($normalized));

    $phone = implode(' | ', $normalized);

    // Přidáme záznam pouze pokud stránka zjevně obsahuje kontakty
    $plaintext = $doc->plaintext;
    $hasContact = false;
    if ($email || $phone) $hasContact = true;
    $keywords = ['kontakt', 'kontakty', 'e-mail', 'email', 'telef', 'tel:', 'telefón', 'kontaktovať'];
    foreach ($keywords as $kw) {
        if (stripos($plaintext, $kw) !== false) { $hasContact = true; break; }
    }

    if ($hasContact) {
        $category = $detailUrlsMap[$durl] ?? '';
        $results[] = [trim($name), trim($email), trim($phone), $category];
    }
    $processed++;
    usleep($delayMicro);
}

// Unikátní podle názvu+email+telefon
// Unikátní záznamy podle názvu+email+telefon, zachovej kategorii
$unique = [];
$deduped = [];
foreach ($results as $r) {
    $key = strtolower($r[0] . '|' . $r[1] . '|' . $r[2]);
    if (!isset($unique[$key]) && ($r[0] || $r[1] || $r[2])) {
        $unique[$key] = true;
        $deduped[] = $r;
    }
}

echo "Collected " . count($deduped) . " unique records\n";

// Group by category
$grouped = [];
foreach ($deduped as $r) {
    $cat = $r[3] ?: 'Uncategorized';
    if (!isset($grouped[$cat])) $grouped[$cat] = [];
    $grouped[$cat][] = [$r[0], $r[1], $r[2]];
}

// Save grouped output as CSV files (one file per category + master) into local files/ folder
$files = write_csv_per_category($grouped, $outDir . '/zoznam_sk');
echo "Saved CSV files. Master: " . ($files['master'] ?? ($outDir . '/zoznam_sk_master.csv')) . "\n";

?>

