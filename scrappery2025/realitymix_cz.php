<?php

// Načtení knihovny Simple HTML DOM Parser
include_once 'simple_html_dom.php';

// Base URL a stránkování
$baseUrl = 'https://realitymix.cz/adresar-realitni-kancelare.php?stranka=';

$data = [];

echo "<h2>realitymix.cz</h2>";
echo "<table border='1' cellspacing='0'><tr><th>Název</th><th>E-mail</th><th>Telefon</th></tr>";

// Procházení stránek
$page = 1;
while (true) {
    $url = $baseUrl . $page;
    
    // Stažení HTML obsahu stránky
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $htmlContent = curl_exec($ch);
    curl_close($ch);
    
    if (!$htmlContent) {
        echo "Chyba při stahování stránky: $url<br>";
        break;
    }
    
    $html = str_get_html($htmlContent);
    
    // Procházení všech odkazů na detail kanceláře
    $links = $html->find('.company-list h3 a');
    if (empty($links)) {
        break;
    }
    
    foreach ($links as $link) {
        $detailUrl = trim($link->href);
        
        // Kontrola, zda URL obsahuje požadovaný řetězec
        if (strpos($detailUrl, 'https://realitymix.cz/detail-realitni-kancelare/') === false) {
            continue;
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
        
        // Extrakce telefonu
        $telefonElement = $detailHtml->find('[itemprop="telephone"]', 0);
        $telefon = $telefonElement ? trim($telefonElement->plaintext) : '';
        
        $data[] = [$nazev, $email, $telefon];
        
        // Výpis do tabulky ihned
        echo "<tr><td>{$nazev}</td><td>{$email}</td><td>{$telefon}</td></tr>";
    }
    
    $page++;
}

echo "</table>";

echo "<p>Počet nalezených záznamů: " . count($data) . "</p>";

// Odstranění duplicit
$data = array_map("unserialize", array_unique(array_map("serialize", $data)));

// Řazení dat podle české abecedy
setlocale(LC_COLLATE, 'cs_CZ.UTF-8');
usort($data, function($a, $b) {
    return strcoll(mb_strtolower($a[0], 'UTF-8'), mb_strtolower($b[0], 'UTF-8'));
});
 

// Uložení dat do CSV
$csvFile = 'realitymix_cz.csv';
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

