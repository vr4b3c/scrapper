    <?php

//  OBSAHUJE ODKAZY NA WEB, TAK NEJE ZPARSOVAT

    // Scraper pro zoznam.sk katalog - stáhne listingy, projde paginaci a detaily
    // Výstup: CSV se sloupci Název;E-mail;Telefon

    // use new root DOM wrapper instead of historical scrappery version
    // script lives in scrapery2026/zoznam.sk/, helpers and simple_dom are in repo root

return;

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

    // Per-category page limit (set very high to effectively disable during full runs)
    $perCategoryPageLimit = PHP_INT_MAX;
    $pagesPerCategory = []; // counts processed pages per category

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
        $catalog = $html->find('.catalog-list', 0);
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
        $paginator = $html->find('.paginator', 0);
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
                            $cat = derive_category($full, $startPath);
                            $toVisit[] = ['url' => $full, 'category' => $cat];
                        }
                    }
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
                        $cat = derive_category($full, $startPath);
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

    // Additional fallback: some category pages render listings via sekcia.fcgi or provide
    // <link rel="next" href="./sekcia.fcgi?...">. Detect and enqueue those targets.
    // Use raw HTML because simple_dom doesn't support attribute selectors for link[rel=next].
    if (preg_match_all('/<link[^>]+rel=["\']next["\'][^>]*href=["\']([^"\']+)["\']/i', $htmlContent, $mnext)) {
        foreach ($mnext[1] as $href) {
            $full = normalize_url($href, $domain, $page);
            if (!$full) continue;
            $path = parse_url($full, PHP_URL_PATH) ?: '';
            if ($startPath && strpos($path, $startPath) === 0) {
                if (!isset($visitedPages[$full])) {
                    $exists = false;
                    foreach ($toVisit as $q) { if ($q['url'] === $full) { $exists = true; break; } }
                    if (!$exists && count($visitedPages) + count($toVisit) < $maxPages) {
                        $cat = derive_category($full, $startPath);
                        $toVisit[] = ['url' => $full, 'category' => $cat];
                    }
                }
            }
        }
    }

    // Enqueue any sekcia.fcgi links found on the page (used by Zoznam for paginated listings)
    if (preg_match_all('/href=["\']([^"\']*sekcia\.fcgi[^"\']*)["\']/i', $htmlContent, $msec)) {
        foreach ($msec[1] as $href) {
            $full = normalize_url($href, $domain, $page);
            if (!$full) continue;
            if (!isset($visitedPages[$full])) {
                $exists = false;
                foreach ($toVisit as $q) { if ($q['url'] === $full) { $exists = true; break; } }
                if (!$exists && count($visitedPages) + count($toVisit) < $maxPages) {
                    $cat = derive_category($full, $startPath);
                    $toVisit[] = ['url' => $full, 'category' => $cat];
                }
            }
        }
    }

    // preserve mapping url => category
    $detailUrlsMap = $detailUrls;
    $detailUrls = array_keys($detailUrlsMap);
    echo "Found " . count($detailUrls) . " detail URLs (unique)\n";

    $results = [];
    $processed = 0;

    foreach ($detailUrls as $durl) {
        if ($processed >= 2000) break; // safety limit
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

        // Telefon: preferovat explicitní label (např. <span class="label">Telefón:</span>)
        // potom odkaz tel: a nakonec regulární výraz (opatrně, aby nechytal interní ID)
        $phone = '';

        // 1) Hledat elementy s třídou "label" a textem obsahujícím "telef"
        foreach ($doc->find('.label') as $lab) {
            if (stripos($lab->plaintext, 'telef') !== false) {
                // lab->node je DOMNode; rodič obvykle <div class="col-sm-3">, sourozenci obsahují hodnotu
                $parent = $lab->node->parentNode;
                if ($parent && $parent->parentNode) {
                    $container = $parent->parentNode;
                    foreach ($container->childNodes as $child) {
                        if ($child->nodeType !== XML_ELEMENT_NODE) continue;
                        $class = $child->getAttribute('class') ?? '';
                        if (stripos($class, 'col-sm-9') !== false || stripos($class, 'col-md-9') !== false) {
                            $txt = trim($child->textContent ?? '');
                            if ($txt !== '') { $phone = $txt; break 2; }
                        }
                    }
                }
            }
        }

        // 2) fallback: odkaz tel:
        if (!$phone) {
            foreach ($doc->find('a') as $a) {
                if (isset($a->href) && stripos($a->href, 'tel:') !== false) {
                    $phone = trim(str_replace('tel:', '', $a->href));
                    break;
                }
            }
        }

        // 3) poslední možnost: regex fallback — vyber kandidáty obsahující předponu 0/+ nebo separátory,
        //    nebo pokud je to pouze čísla, akceptuj pouze pokud začíná 0 (vyhneme se interním ID jako 6851976)
        if (!$phone) {
            if (preg_match_all('/[+0-9][0-9 \-\/()\.]{5,}[0-9]/', $doc->plaintext, $m2)) {
                foreach ($m2[0] as $cand) {
                    $trim = trim($cand);
                    $digitsOnly = preg_replace('/\D/', '', $trim);
                    if (strlen($digitsOnly) < 7) continue; // příliš krátké

                    // pokud kandidát obsahuje nějaký separátor nebo začíná '+' nebo '0', přijmeme ho
                    if (preg_match('/[\s\-\/\.\(\)]/', $trim) || strpos($trim, '+') === 0 || strpos($trim, '0') === 0) {
                        $phone = $trim; break;
                    }

                    // pokud je to jen řetězec číslic bez separátorů, akceptuj pouze když začíná 0 (místní číslo)
                    if (preg_match('/^[0-9]+$/', $trim) && strpos($trim, '0') === 0) {
                        $phone = $trim; break;
                    }
                }
            }
        }

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
    $files = write_csv_per_category($grouped, $outDir . '/zoznam_sk_2_databazy');
    echo "Saved CSV files. Master: " . ($files['master'] ?? ($outDir . '/zoznam_sk_2_databazy_master.csv')) . "\n";

        echo "Collected " . count($deduped) . " unique records\n";

        // Group by category
        $grouped = [];
        foreach ($deduped as $r) {
            $cat = $r[3] ?: 'Uncategorized';
            if (!isset($grouped[$cat])) $grouped[$cat] = [];
            $grouped[$cat][] = [$r[0], $r[1], $r[2]];
        }

        // Save grouped output as CSV files (one file per category + master) into local files/ folder
        $files = write_csv_per_category($grouped, $outDir . '/zoznam_sk_2_databazy');
        echo "Saved CSV files. Master: " . ($files['master'] ?? ($outDir . '/zoznam_sk_2_databazy_master.csv')) . "\n";

        ?>

