<?php
declare(strict_types=1);

namespace App\Core;

defined('NX_ROOT') || exit;

/** Minimal XLSX writer (single sheet, inline strings, numeric cells). */
final class Xlsx
{
    public static function build(array $headers, array $rows, string $sheet = 'Export'): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'nxx');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . self::x(mb_substr($sheet, 0, 31)) . '" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="2"><xf fontId="0"/><xf fontId="1" applyFont="1"/></cellXfs></styleSheet>');

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetData>';
        $xml .= self::row(1, $headers, true);
        $i = 2;
        foreach ($rows as $r) {
            $xml .= self::row($i++, array_values($r), false);
        }
        $xml .= '</sheetData></worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
        $zip->close();
        $data = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $data;
    }

    private static function row(int $n, array $cells, bool $bold): string
    {
        $out = '<row r="' . $n . '">';
        foreach ($cells as $i => $v) {
            $ref = self::col($i) . $n;
            $s = $bold ? ' s="1"' : '';
            if (is_int($v) || is_float($v) || (is_string($v) && preg_match('/^-?\d{1,15}(\.\d+)?$/', $v) && !preg_match('/^0\d/', $v))) {
                $out .= '<c r="' . $ref . '"' . $s . '><v>' . $v . '</v></c>';
            } else {
                $out .= '<c r="' . $ref . '" t="inlineStr"' . $s . '><is><t xml:space="preserve">' . self::x((string) $v) . '</t></is></c>';
            }
        }
        return $out . '</row>';
    }

    private static function col(int $i): string
    {
        $s = '';
        $i++;
        while ($i > 0) {
            $m = ($i - 1) % 26;
            $s = chr(65 + $m) . $s;
            $i = intdiv($i - $m, 26);
        }
        return $s;
    }

    private static function x(string $s): string
    {
        $s = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $s) ?? '';
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** CSV with formula-injection protection (cells starting with = + - @ are prefixed). */
    public static function csv(array $headers, array $rows): string
    {
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, "\xEF\xBB\xBF");
        $safe = static fn ($v) => is_string($v) && $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) && !is_numeric($v) ? "'" . $v : $v;
        fputcsv($fh, $headers, ',', '"', '');
        foreach ($rows as $r) {
            fputcsv($fh, array_map($safe, array_values($r)), ',', '"', '');
        }
        rewind($fh);
        $out = (string) stream_get_contents($fh);
        fclose($fh);
        return $out;
    }
}
