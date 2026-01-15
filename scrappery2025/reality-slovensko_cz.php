<?php

// Načtení knihovny Simple HTML DOM Parser
include_once 'simple_html_dom.php';

// URL stránky s realitními kancelářemi
$baseUrl = 'https://www.reality-slovensko.cz/realitni-kancelare-makleri';
$baseDomain = 'https://www.reality-slovensko.cz';
$validUrlPrefix = 'https://www.reality-slovensko.cz/realitni-kancelar/';

// Stažení HTML obsahu hlavní stránky
$ch = curl_init($baseUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
$htmlContent = curl_exec($ch);
curl_close($ch);

if (!$htmlContent) {
    die("Chyba při stahování hlavní stránky.");
}

$html = str_get_html($htmlContent);

$data = [];

$counter = 0;
$maxRecords = 9999;

echo "<h2>reality-slovensko.cz</h2>";
echo "<table border='1' cellspacing='0'><tr><th>Název</th><th>E-mail</th><th>Telefon</th></tr>";

// Procházení všech položek realitních kanceláří, které NEJSOU uvnitř #filterContent
foreach ($html->find('.filter-list__item a') as $link) {
    $parent = $link;
    $insideFilterContent = false;
    
    while ($parent = $parent->parent()) {
        if ($parent->id === 'filterContent') {
            $insideFilterContent = true;
            break;
        }
    }
    
    if ($insideFilterContent) {
        continue; // Přeskočíme odkazy uvnitř #filterContent
    }
    
    if ($counter >= $maxRecords) {
        break;
    }
    
    $relativeUrl = trim($link->href);
    if (strpos($relativeUrl, '/') === 0) {
        $detailUrl = $baseDomain . $relativeUrl;
    } elseif (!preg_match('/^https?:\/\//', $relativeUrl)) {
        $detailUrl = $baseDomain . '/' . $relativeUrl;
    } else {
        $detailUrl = $relativeUrl;
    }
    
    // Kontrola, zda URL odpovídá požadovanému formátu
    if (strpos($detailUrl, $validUrlPrefix) !== 0) {
        continue; // Přeskočíme odkazy, které nezačínají požadovanou URL
    }
    
    // Stažení HTML obsahu detailu realitky
    $ch = curl_init($detailUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $detailContent = curl_exec($ch);
    curl_close($ch);
    
    if (!$detailContent) {
        echo "Chyba při stahování detailu realitky: $detailUrl<br>";
        continue;
    }
    
    $detailHtml = str_get_html($detailContent);
    
    // Extrakce jména realitky
    $nazev = trim($detailHtml->find('[itemprop="name"]', 0)->plaintext ?? '');
    
    // Extrakce e-mailu
    $emailElement = $detailHtml->find('[itemprop="email"]', 0);
    $email = $emailElement ? trim($emailElement->plaintext) : '';
    
    // Extrakce prvního telefonu
    $telefonElement = $detailHtml->find('[itemprop="telephone"]', 0);
    $telefon = $telefonElement ? trim($telefonElement->plaintext) : '';
    
    $data[] = [$nazev, $email, $telefon];
    
    // Výpis do tabulky ihned
    echo "<tr><td>{$nazev}</td><td>{$email}</td><td>{$telefon}</td></tr>";
    
    $counter++;
}

echo "</table>";

echo "<p>Počet nalezených záznamů: " . count($data) . "</p>";


$data = array_map("unserialize", array_unique(array_map("serialize", $data)));

// Řazení dat podle české abecedy
setlocale(LC_COLLATE, 'cs_CZ.UTF-8');
usort($data, function($a, $b) {
    return strcoll(mb_strtolower($a[0], 'UTF-8'), mb_strtolower($b[0], 'UTF-8'));
});
 


// Uložení dat do CSV
$csvFile = 'reality_slovensko_cz.csv';
$fp = fopen($csvFile, 'w');

// Zápis hlavičky CSV souboru
fputcsv($fp, ['Název', 'E-mail', 'Telefon'], ';');

// Zápis jednotlivých záznamů do CSV
foreach ($data as $radek) {
    fputcsv($fp, $radek, ';');
}

fclose($fp);

echo "<br>Scraper dokončen. Data byla uložena do souboru $csvFile.<br>";

?>
