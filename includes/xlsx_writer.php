<?php
/**
 * Candace Management System - tiny .xlsx writer.
 *
 * Builds real Excel workbooks (several sheets, header styling, peso/date
 * formats, frozen header row, filters, formulas) with no Composer packages -
 * only PHP's built-in "zip" extension (ZipArchive), which XAMPP enables by
 * default. Rows are streamed to temp files, so big tables don't eat memory.
 */

final class Xlsx
{
    // Style ids - these must match the <cellXfs> list in XlsxWorkbook::stylesXml().
    public const S_DEFAULT = 0;
    public const S_HEADER = 1;
    public const S_TEXT = 2;
    public const S_PESO = 3;
    public const S_DATE = 4;
    public const S_DATETIME = 5;
    public const S_INT = 6;
    public const S_PCT = 7;
    public const S_BOLD = 8;
    public const S_PESO_BOLD = 9;
    public const S_MONTH = 10;
    public const S_TITLE = 11;
    public const S_ID = 12;

    /** Text cell. Stored as a string, so Excel never treats it as a formula (e.g. a note starting with "="). */
    public static function text($value, int $style = self::S_TEXT): ?array
    {
        return $value === null ? null : ['k' => 's', 'v' => (string) $value, 's' => $style];
    }

    public static function num($value, int $style = self::S_DEFAULT): ?array
    {
        return $value === null || $value === '' ? null : ['k' => 'n', 'v' => (float) $value, 's' => $style];
    }

    /** 'YYYY-MM-DD' -> Excel date. */
    public static function date(?string $ymd, int $style = self::S_DATE): ?array
    {
        if ($ymd === null || $ymd === '') return null;
        $ts = strtotime(substr($ymd, 0, 10) . ' 00:00:00 UTC');
        return $ts === false ? null : ['k' => 'n', 'v' => (float) (intdiv($ts, 86400) + 25569), 's' => $style];
    }

    /** 'YYYY-MM-DD HH:MM:SS' -> Excel date+time (no timezone shifting). */
    public static function datetime(?string $dt, int $style = self::S_DATETIME): ?array
    {
        if ($dt === null || $dt === '') return null;
        $ts = strtotime($dt . ' UTC');
        return $ts === false ? null : ['k' => 'n', 'v' => $ts / 86400 + 25569, 's' => $style];
    }

    /** A live formula (no leading "="). $cached is the value shown before Excel recalculates. */
    public static function formula(string $formula, $cached, int $style = self::S_DEFAULT): array
    {
        return ['k' => 'f', 'f' => $formula, 'v' => $cached, 's' => $style];
    }

    /** Column number (1-based) -> letters. */
    public static function col(int $n): string
    {
        $s = '';
        while ($n > 0) {
            $m = ($n - 1) % 26;
            $s = chr(65 + $m) . $s;
            $n = intdiv($n - 1, 26);
        }
        return $s;
    }

    public static function esc(string $s): string
    {
        // Drop bytes that aren't valid UTF-8, then characters XML 1.0 forbids.
        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $s);
        if ($clean === false) $clean = '';
        $clean = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $clean) ?? '';
        return htmlspecialchars($clean, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}

final class XlsxSheet
{
    public string $name;
    public string $bodyPath;
    private $fh;
    private int $rows = 0;
    private int $maxCol = 1;
    private array $widths;
    private bool $tableMode;

    public function __construct(string $name, array $widths, bool $tableMode)
    {
        $this->name = $name;
        $this->widths = $widths;
        $this->tableMode = $tableMode; // true: freeze header row + autofilter
        $this->bodyPath = tempnam(sys_get_temp_dir(), 'xlsx_sheet_');
        $this->fh = fopen($this->bodyPath, 'wb');
    }

    /** Number of the row that the next row() call will write. */
    public function nextRow(): int { return $this->rows + 1; }
    public function lastRow(): int { return $this->rows; }

    /** Header row (dark, white bold text). */
    public function header(array $titles): int
    {
        $cells = [];
        foreach ($titles as $t) $cells[] = Xlsx::text($t, Xlsx::S_HEADER);
        return $this->row($cells);
    }

    /** Writes one row. $cells is a list (index = column, null = empty cell). Returns the row number. */
    public function row(array $cells): int
    {
        $r = ++$this->rows;
        $xml = '<row r="' . $r . '">';
        foreach (array_values($cells) as $i => $c) {
            if ($c === null) continue;
            $ref = Xlsx::col($i + 1) . $r;
            $this->maxCol = max($this->maxCol, $i + 1);
            $s = (int) $c['s'];
            switch ($c['k']) {
                case 's':
                    $xml .= '<c r="' . $ref . '" s="' . $s . '" t="inlineStr"><is><t xml:space="preserve">' . Xlsx::esc($c['v']) . '</t></is></c>';
                    break;
                case 'n':
                    $xml .= '<c r="' . $ref . '" s="' . $s . '"><v>' . self::numStr($c['v']) . '</v></c>';
                    break;
                case 'f':
                    $v = $c['v'];
                    if (is_string($v)) {
                        $xml .= '<c r="' . $ref . '" s="' . $s . '" t="str"><f>' . Xlsx::esc($c['f']) . '</f><v>' . Xlsx::esc($v) . '</v></c>';
                    } else {
                        $xml .= '<c r="' . $ref . '" s="' . $s . '"><f>' . Xlsx::esc($c['f']) . '</f><v>' . self::numStr((float) $v) . '</v></c>';
                    }
                    break;
            }
        }
        $xml .= '</row>';
        fwrite($this->fh, $xml);
        return $r;
    }

    private static function numStr(float $v): string
    {
        // Locale-proof, no exponent surprises for money-sized numbers.
        $s = rtrim(rtrim(number_format($v, 10, '.', ''), '0'), '.');
        return $s === '' || $s === '-0' ? '0' : $s;
    }

    /** Writes the complete sheet XML to $outPath. */
    public function finish(string $outPath, bool $selected): void
    {
        fclose($this->fh);

        $out = fopen($outPath, 'wb');
        fwrite($out, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"' . ($selected ? ' tabSelected="1"' : '') . '>');
        if ($this->tableMode) {
            fwrite($out, '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
                . '<selection pane="bottomLeft" activeCell="A2" sqref="A2"/>');
        }
        fwrite($out, '</sheetView></sheetViews><sheetFormatPr defaultRowHeight="15"/>');

        if ($this->widths) {
            fwrite($out, '<cols>');
            foreach ($this->widths as $i => $w) {
                $n = $i + 1;
                fwrite($out, '<col min="' . $n . '" max="' . $n . '" width="' . (float) $w . '" customWidth="1"/>');
            }
            fwrite($out, '</cols>');
        }

        fwrite($out, '<sheetData>');
        $in = fopen($this->bodyPath, 'rb');
        stream_copy_to_stream($in, $out);
        fclose($in);
        fwrite($out, '</sheetData>');

        if ($this->tableMode && $this->rows >= 1) {
            fwrite($out, '<autoFilter ref="A1:' . Xlsx::col(max($this->maxCol, 1)) . max($this->rows, 1) . '"/>');
        }
        fwrite($out, '</worksheet>');
        fclose($out);
    }
}

final class XlsxWorkbook
{
    /** @var XlsxSheet[] */
    private array $sheets = [];

    public static function available(): bool
    {
        return class_exists('ZipArchive');
    }

    public function addSheet(string $name, array $widths, bool $tableMode = true): XlsxSheet
    {
        // Excel limits: 31 characters, none of  [ ] : * ? / \
        $name = substr(preg_replace('/[\[\]:*?\/\\\\]/', '', $name), 0, 31);
        $sheet = new XlsxSheet($name, $widths, $tableMode);
        $this->sheets[] = $sheet;
        return $sheet;
    }

    public function save(string $path): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the Excel file.');
        }

        $finished = [];
        $overrides = '';
        $sheetEls = '';
        $relEls = '';
        foreach ($this->sheets as $i => $sheet) {
            $n = $i + 1;
            $tmp = tempnam(sys_get_temp_dir(), 'xlsx_final_');
            $sheet->finish($tmp, $i === 0);
            $finished[] = $tmp;
            $zip->addFile($tmp, "xl/worksheets/sheet{$n}.xml");

            $overrides .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $sheetEls .= '<sheet name="' . Xlsx::esc($sheet->name) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
            $relEls .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
        }
        $stylesId = count($this->sheets) + 1;
        $relEls .= '<Relationship Id="rId' . $stylesId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        $head = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $zip->addFromString('[Content_Types].xml', $head
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . $overrides . '</Types>');
        $zip->addFromString('_rels/.rels', $head
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/workbook.xml', $head
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<bookViews><workbookView/></bookViews><sheets>' . $sheetEls . '</sheets><calcPr fullCalcOnLoad="1"/></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', $head
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $relEls . '</Relationships>');
        $zip->addFromString('xl/styles.xml', $head . self::stylesXml());

        $ok = $zip->close(); // the temp sheet files must still exist until here
        foreach ($finished as $f) @unlink($f);
        foreach ($this->sheets as $s) @unlink($s->bodyPath);
        if (!$ok) throw new RuntimeException('Could not write the Excel file.');
    }

    private static function stylesXml(): string
    {
        return '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="5">'
            . '<numFmt numFmtId="164" formatCode="&quot;₱&quot;#,##0.00"/>'
            . '<numFmt numFmtId="165" formatCode="yyyy\-mm\-dd"/>'
            . '<numFmt numFmtId="166" formatCode="yyyy\-mm\-dd\ hh:mm"/>'
            . '<numFmt numFmtId="167" formatCode="0.00&quot;%&quot;"/>'
            . '<numFmt numFmtId="168" formatCode="mmm\ yyyy"/>'
            . '</numFmts>'
            . '<fonts count="4">'
            . '<font><sz val="10"/><name val="Arial"/><family val="2"/></font>'
            . '<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Arial"/><family val="2"/></font>'
            . '<font><b/><sz val="10"/><name val="Arial"/><family val="2"/></font>'
            . '<font><b/><sz val="14"/><name val="Arial"/><family val="2"/></font>'
            . '</fonts>'
            . '<fills count="3">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF17181A"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="13">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'                                                                   // 0 default
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>' // 1 header
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top"/></xf>'                  // 2 text
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'                                           // 3 peso
            . '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="left"/></xf>'  // 4 date
            . '<xf numFmtId="166" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="left"/></xf>'  // 5 datetime
            . '<xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'                                             // 6 integer
            . '<xf numFmtId="167" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'                                           // 7 percent label
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'                                                     // 8 bold
            . '<xf numFmtId="164" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1" applyNumberFormat="1"/>'                             // 9 peso bold
            . '<xf numFmtId="168" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="left"/></xf>'  // 10 month
            . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>'                                                     // 11 title
            . '<xf numFmtId="1" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="left"/></xf>'   // 12 id (plain, no thousands comma)
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }
}