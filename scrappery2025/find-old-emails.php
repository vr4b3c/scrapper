<?php

function getLinks($url, $baseUrl, &$visited = []) {
    if (isset($visited[$url])) {
        return [];
    }

    $visited[$url] = true;

    $html = @file_get_contents($url);
    if ($html === false) {
        echo "Nelze načíst stránku: $url<br>";
        return [];
    }

    $links = [];
    $dom = new DOMDocument();

    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();

    $anchors = $dom->getElementsByTagName('a');
    foreach ($anchors as $anchor) {
        $href = $anchor->getAttribute('href');
        $parsedUrl = parse_url($href);

        if (isset($parsedUrl['scheme']) && $parsedUrl['scheme'] !== 'http' && $parsedUrl['scheme'] !== 'https') {
            continue;
        }

        if (isset($parsedUrl['host']) && $parsedUrl['host'] !== parse_url($baseUrl, PHP_URL_HOST)) {
            continue;
        }

        $absoluteUrl = isset($parsedUrl['host']) ? $href : rtrim($baseUrl, '/') . '/' . ltrim($href, '/');

        $absoluteUrl = strtok($absoluteUrl, '#');

        if (!isset($visited[$absoluteUrl])) {
            $links[] = $absoluteUrl;
        }
    }

    return $links;
}

function extractEmails($html, $url) {
    $emails = [];
    if (preg_match_all('/[a-zA-Z0-9._%+-]+@coi\.cz/', $html, $matches)) {
        foreach ($matches[0] as $email) {
            $emails[] = ["email" => $email, "url" => $url];
        }
    }
    return $emails;
}

function crawlWebsite($startUrl) {
    $toVisit = [$startUrl];
    $visited = [];
    $allLinks = [];
    $emailAddresses = [];

    set_time_limit(300); // Nastav maximální čas provádění na 5 minut

    while (!empty($toVisit)) {
        $currentUrl = array_shift($toVisit);
        echo "Procházím: $currentUrl<br>";
        $html = @file_get_contents($currentUrl);
        if ($html === false) {
            echo "Nelze načíst stránku: $currentUrl<br>";
            continue;
        }

        $emailsFound = extractEmails($html, $currentUrl);
        foreach ($emailsFound as $emailData) {
            $emailAddresses[] = $emailData;
            echo "Nalezena emailová adresa: {$emailData['email']} na stránce {$emailData['url']}<br>";
        }

        $links = getLinks($currentUrl, $startUrl, $visited);

        foreach ($links as $link) {
            if (!isset($visited[$link])) {
                echo "Nalezena stránka: $link<br>";
                $visited[$link] = true;
                $toVisit[] = $link;
                $allLinks[] = $link;
            }
        }
    }

    return ["links" => array_unique($allLinks), "emails" => $emailAddresses];
}

function saveEmailsToCSV($emails, $filename = 'coi.gov.cz---stranky-a-emaily-se-starou-domenou.csv') {
    $file = fopen($filename, 'w');
    fputcsv($file, ['URL', 'Email']);

    $uniqueEmails = [];
    foreach ($emails as $emailData) {
        $key = $emailData['url'] . '_' . $emailData['email'];
        if (!isset($uniqueEmails[$key])) {
            fputcsv($file, [$emailData['url'], $emailData['email']]);
            $uniqueEmails[$key] = true;
        }
    }

    fclose($file);
    echo "<br>Data byla uložena do souboru: $filename<br>";
}

$startUrl = 'https://coi.gov.cz/';

$result = crawlWebsite($startUrl);

saveEmailsToCSV($result['emails']);

echo "<br>Seznam podstránek:<br>";
foreach ($result['links'] as $link) {
    echo $link . "<br>";
}

?>

