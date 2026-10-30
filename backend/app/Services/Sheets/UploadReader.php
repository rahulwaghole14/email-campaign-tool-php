<?php
declare(strict_types=1);
namespace App\Services\Sheets;

use InvalidArgumentException;
use ZipArchive;
use DOMDocument;
use DOMXPath;

final class UploadReader
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    public static function parseQuantity(?string $val): int
    {
        if ($val === null || trim($val) === '') return 0;
        $val = trim($val);
        if (function_exists('ini_parse_quantity')) {
            try {
                return (int) ini_parse_quantity($val);
            } catch (\Throwable) {}
        }
        $last = strtolower($val[strlen($val) - 1]);
        $num = (int) $val;
        switch ($last) {
            case 'g': $num *= 1024 * 1024 * 1024; break;
            case 'm': $num *= 1024 * 1024; break;
            case 'k': $num *= 1024; break;
        }
        return $num;
    }

    public static function serverLimit(): int
    {
        $limits = [self::MAX_BYTES];
        $upload = self::parseQuantity(ini_get('upload_max_filesize') ?: '');
        $post = self::parseQuantity(ini_get('post_max_size') ?: '');
        if ($upload > 0) $limits[] = $upload;
        if ($post > 0) $limits[] = max(1, $post - 65536);
        return min($limits);
    }

    public static function read(string $path, string $name): array
    {
        if (filesize($path) > self::MAX_BYTES) throw new InvalidArgumentException('Upload a file no larger than 5 MB.');
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($extension === 'csv') return self::csv($path);
        if ($extension !== 'xlsx') throw new InvalidArgumentException('Choose an .xlsx or UTF-8 .csv file. Save older .xls files as .xlsx first.');
        return self::xlsx($path);
    }

    private static function csv(string $path): array
    {
        $file = fopen($path, 'rb'); $rows = []; $cells = 0;
        try {
            while (($row = fgetcsv($file, 0, ',', '"', '')) !== false) {
                if (count($rows) >= 10000 || count($row) > 100) throw new InvalidArgumentException('Uploads support 10,000 rows and 100 columns per tab.');
                $row = array_map(fn($v) => (string) $v, $row);
                if (!$rows) $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', $row[0]);
                foreach ($row as $value) self::cell($value);
                $cells += count($row);
                if ($cells > 100000) throw new InvalidArgumentException('Upload contains too many populated cells (limit 100,000).');
                $rows[] = $row;
            }
        } finally { fclose($file); }
        if (!$rows) throw new InvalidArgumentException('The uploaded file is empty.');
        return [['title' => 'CSV data', 'rows' => $rows]];
    }

    private static function cell(string $value): void
    {
        if (!mb_check_encoding($value, 'UTF-8')) throw new InvalidArgumentException('Save CSV files using UTF-8 encoding.');
        if (strlen($value) > 45000) throw new InvalidArgumentException('Each cell must contain less than 45 KB of text.');
    }

    private static function xml(ZipArchive $zip, string $path): DOMXPath
    {
        $raw = $zip->getFromName($path);
        if ($raw === false || stripos($raw, '<!DOCTYPE') !== false || stripos($raw, '<!ENTITY') !== false) throw new InvalidArgumentException('Invalid or unsupported Excel XML.');
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try { $ok = $doc->loadXML($raw, LIBXML_NONET | LIBXML_COMPACT); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        if (!$ok) throw new InvalidArgumentException('The Excel workbook contains invalid XML.');
        $xpath = new DOMXPath($doc); $xpath->registerNamespace('m', self::NS); return $xpath;
    }

    private static function xlsx(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new InvalidArgumentException('The file is not a readable .xlsx workbook.');
        try {
            $expanded = 0;
            if ($zip->numFiles > 1000) throw new InvalidArgumentException('Workbook archive is too complex.');
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i); $expanded += $stat['size'];
                if ($stat['size'] > 10 * 1024 * 1024 || $expanded > 25 * 1024 * 1024) throw new InvalidArgumentException('Uncompressed workbook is too large.');
            }
            $strings = [];
            if ($zip->locateName('xl/sharedStrings.xml') !== false) {
                $xml = self::xml($zip, 'xl/sharedStrings.xml');
                foreach ($xml->query('//m:si') as $si) {
                    $text = ''; foreach ($xml->query('.//m:t', $si) as $part) $text .= $part->textContent;
                    self::cell($text); $strings[] = $text;
                }
            }
            $rels = self::xml($zip, 'xl/_rels/workbook.xml.rels'); $targets = [];
            foreach ($rels->query('//*[local-name()="Relationship"]') as $rel) {
                if ($rel->getAttribute('TargetMode') === 'External') continue;
                $target = ltrim($rel->getAttribute('Target'), '/');
                if (!str_starts_with($target, 'xl/')) $target = 'xl/' . $target;
                if (str_contains($target, '..')) continue;
                $targets[$rel->getAttribute('Id')] = $target;
            }
            $book = self::xml($zip, 'xl/workbook.xml'); $tabs = []; $totalCells = 0;
            foreach ($book->query('//m:sheets/m:sheet') as $sheet) {
                if (count($tabs) >= 50) throw new InvalidArgumentException('Upload at most 50 tabs per workbook.');
                $relationship = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
                if (!isset($targets[$relationship])) throw new InvalidArgumentException('Unsupported workbook tab relationship.');
                $xml = self::xml($zip, $targets[$relationship]); $rows = [];
                foreach ($xml->query('//m:sheetData/m:row') as $row) {
                    $number = (int) $row->getAttribute('r');
                    if ($number < 1 || $number > 10000) throw new InvalidArgumentException('Uploads support rows 1 through 10,000.');
                    while (count($rows) < $number) $rows[] = [];
                    foreach ($xml->query('m:c', $row) as $cell) {
                        if (++$totalCells > 100000) throw new InvalidArgumentException('Workbook exceeds 100,000 cells.');
                        if (!preg_match('/^([A-Z]+)[0-9]+$/', $cell->getAttribute('r'), $match)) throw new InvalidArgumentException('Invalid cell address.');
                        $column = 0; foreach (str_split($match[1]) as $letter) $column = $column * 26 + ord($letter) - 64;
                        if ($column > 100) throw new InvalidArgumentException('Uploads support the first 100 columns.');
                        $value = $xml->evaluate('string(m:v)', $cell);
                        if ($xml->query('m:f', $cell)->length) $value = '=' . $xml->evaluate('string(m:f)', $cell);
                        elseif ($cell->getAttribute('t') === 's') $value = $strings[(int) $value] ?? '';
                        elseif ($cell->getAttribute('t') === 'inlineStr') { $value = ''; foreach ($xml->query('m:is//m:t', $cell) as $text) $value .= $text->textContent; }
                        self::cell($value);
                        while (count($rows[$number - 1]) < $column) $rows[$number - 1][] = '';
                        $rows[$number - 1][$column - 1] = $value;
                    }
                }
                $tabs[] = ['title' => $sheet->getAttribute('name'), 'rows' => $rows];
            }
            if (!$tabs) throw new InvalidArgumentException('Workbook has no readable tabs.');
            return $tabs;
        } finally { $zip->close(); }
    }
}
