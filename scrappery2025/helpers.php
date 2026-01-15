<?php
/**
 * Shared helper functions for scrapers
 * - write_grouped_xlsx(): write grouped data (category => rows) to .xlsx using PhpSpreadsheet if available
 * - write_csv_per_category(): fallback CSV writer when PhpSpreadsheet isn't installed
 * - safe_sheet_title(): sanitize sheet title (<=31 chars)
 * - resolve_relative_url(): helper to resolve relative links
 */

function ensure_composer_autoload() {
    $path = __DIR__ . '/../vendor/autoload.php';
    return file_exists($path) ? $path : false;
}

function safe_sheet_title($title) {
    $t = trim($title);
    $t = preg_replace('/[\\\/\*\?\[\]:]/u', '_', $t);
    if (mb_strlen($t) > 31) $t = mb_substr($t, 0, 31);
    return $t ?: 'Sheet';
}

function write_grouped_xlsx(array $grouped, $filename = 'out.xlsx') {
    $autoload = ensure_composer_autoload();
    if (!$autoload) return false;
    require_once $autoload;
    $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheetIndex = 0;
    foreach ($grouped as $cat => $rows) {
        if ($sheetIndex === 0) {
            $sheet = $ss->getActiveSheet();
            $sheet->setTitle(safe_sheet_title($cat));
        } else {
            $sheet = $ss->createSheet();
            $sheet->setTitle(safe_sheet_title($cat));
        }
        $sheet->setCellValue('A1', 'Název');
        $sheet->setCellValue('B1', 'E-mail');
        $sheet->setCellValue('C1', 'Telefon');
        $r = 2;
        foreach ($rows as $row) {
            $sheet->setCellValue('A' . $r, $row[0] ?? '');
            $sheet->setCellValue('B' . $r, $row[1] ?? '');
            $sheet->setCellValue('C' . $r, $row[2] ?? '');
            $r++;
        }
        $sheetIndex++;
    }
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss);
    $writer->save($filename);
    return $filename;
}

function write_csv_per_category(array $grouped, $prefix = 'zoznam') {
    $masterFile = $prefix . '.csv';
    $master = fopen($masterFile, 'w');
    // write UTF-8 BOM so Excel on Windows recognizes UTF-8
    fwrite($master, "\xEF\xBB\xBF");
    fputcsv($master, array_map(function($v){ return mb_convert_encoding($v, 'UTF-8', 'auto'); }, ['Název', 'E-mail', 'Telefon', 'Kategorie']), ';');
    $files = [];
    foreach ($grouped as $cat => $rows) {
        $safe = preg_replace('/[^A-Za-z0-9_\-]/u', '_', mb_substr($cat, 0, 30));
        $fn = $prefix . '_' . ($safe ?: 'uncategorized') . '.csv';
        $fp = fopen($fn, 'w');
        // write UTF-8 BOM for compatibility with Excel
        fwrite($fp, "\xEF\xBB\xBF");
        fputcsv($fp, array_map(function($v){ return mb_convert_encoding($v, 'UTF-8', 'auto'); }, ['Název', 'E-mail', 'Telefon']), ';');
        foreach ($rows as $row) {
            $outRow = [ $row[0] ?? '', $row[1] ?? '', $row[2] ?? '' ];
            $outRow = array_map(function($v){ return mb_convert_encoding($v, 'UTF-8', 'auto'); }, $outRow);
            fputcsv($fp, $outRow, ';');

            $masterRow = [ $row[0] ?? '', $row[1] ?? '', $row[2] ?? '', $cat ];
            $masterRow = array_map(function($v){ return mb_convert_encoding($v, 'UTF-8', 'auto'); }, $masterRow);
            fputcsv($master, $masterRow, ';');
        }
        fclose($fp);
        $files[] = $fn;
    }
    fclose($master);
    return ['master' => $masterFile, 'files' => $files];
}

function resolve_relative_url($href, $baseDomain, $currentUrl = null) {
    $href = trim($href);
    if ($href === '') return null;
    if (strpos($href, '//') === 0) return 'https:' . $href;
    if (preg_match('#^https?://#i', $href)) return $href;
    if (strpos($href, '/') === 0) return rtrim($baseDomain, '/') . $href;
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

// convenience wrapper: try xlsx then fallback to csvs
function save_grouped_output(array $grouped, $xlsxFile = 'out.xlsx', $csvPrefix = 'out') {
    $autoload = ensure_composer_autoload();
    if ($autoload) {
        $res = write_grouped_xlsx($grouped, $xlsxFile);
        if ($res) return ['type' => 'xlsx', 'file' => $res];
    }
    $files = write_csv_per_category($grouped, $csvPrefix);
    return ['type' => 'csv', 'files' => $files];
}

?>
