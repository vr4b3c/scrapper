<?php
include_once __DIR__ . '/../../simple_dom.php';

function fetch_url($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; scraper-test/1.0)');
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $html = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 400 || !$html) return false;
    return $html;
}

$url = $argv[1] ?? 'https://www.zoznam.sk/firma/3724675/Cernovske-drevenice-Ruzomberok';
echo "Testing URL: $url\n";
$html = fetch_url($url);
if (!$html) { echo "Failed to fetch\n"; exit(1); }
$doc = str_get_html($html);

// Name
$name = '';
if ($el = $doc->find('h1', 0)) $name = trim($el->plaintext);
if (!$name && ($el = $doc->find('h2', 0))) $name = trim($el->plaintext);
if (!$name) $name = trim($doc->find('title', 0)->plaintext ?? '');

// Email
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

// Phone extraction (same logic as scraper)
$phone = '';
foreach ($doc->find('.label') as $lab) {
    if (stripos($lab->plaintext, 'telef') !== false) {
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

if (!$phone) {
    foreach ($doc->find('a') as $a) {
        if (isset($a->href) && stripos($a->href, 'tel:') !== false) {
            $phone = trim(str_replace('tel:', '', $a->href));
            break;
        }
    }
}

if (!$phone) {
    if (preg_match_all('/(\+?0?\d[\d \-\/()]{5,}\d)/', $doc->plaintext, $m2)) {
        foreach ($m2[0] as $cand) {
            $clean = preg_replace('/[^+0-9]/', '', $cand);
            if (strlen(preg_replace('/[^0-9]/','',$clean)) >= 7) { $phone = $cand; break; }
        }
    }
}

echo "Name: $name\n";
echo "Email: $email\n";
echo "Phone: $phone\n";

?>
