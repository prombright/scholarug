<?php
declare(strict_types=1);

/**
 * Minimal dependency-free .xlsx reader, copied verbatim from
 * bulksms/lib/XlsxReader.php (self-contained, no bulksms-specific
 * dependencies) -- no Composer/vendor directory exists anywhere in this
 * project, so rather than introduce PhpSpreadsheet as the first
 * dependency, this reads the handful of XML parts inside the .xlsx zip
 * directly using PHP's built-in ZipArchive + SimpleXML -- enough to pull
 * flat rows of text/number cells out of the first worksheet, which is
 * all a contacts import needs.
 */
final class XlsxReader
{
    /** @return array<int, array<int, string>> rows of cell strings, in sheet order */
    public static function readFirstSheet(string $filePath): array
    {
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new RuntimeException('Could not open the file as an .xlsx workbook.');
        }

        $sharedStrings = self::readSharedStrings($zip);
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if ($sheetXml === false) {
            throw new RuntimeException('That .xlsx file has no readable first worksheet.');
        }

        $xml = simplexml_load_string($sheetXml);
        if ($xml === false) {
            throw new RuntimeException('That .xlsx worksheet could not be parsed.');
        }

        $rows = [];
        foreach ($xml->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $cell) {
                $colIndex = self::columnIndexFromRef((string) $cell['r']);
                $type = (string) $cell['t'];
                $rawValue = isset($cell->v) ? (string) $cell->v : '';

                if ($type === 's') {
                    // Shared-string index -> actual text.
                    $value = $sharedStrings[(int) $rawValue] ?? '';
                } elseif ($type === 'inlineStr') {
                    $value = (string) ($cell->is->t ?? '');
                } else {
                    $value = $rawValue;
                }

                $cells[$colIndex] = $value;
            }
            ksort($cells);
            $rows[] = array_values($cells);
        }

        return $rows;
    }

    /** @return array<int, string> */
    private static function readSharedStrings(ZipArchive $zip): array
    {
        $xmlContent = $zip->getFromName('xl/sharedStrings.xml');
        if ($xmlContent === false) {
            return [];
        }

        $xml = simplexml_load_string($xmlContent);
        if ($xml === false) {
            return [];
        }

        $strings = [];
        foreach ($xml->si as $si) {
            // A shared string can be a single <t>, or multiple <r><t> runs
            // (rich text) that need concatenating.
            if (isset($si->t)) {
                $strings[] = (string) $si->t;
            } else {
                $text = '';
                foreach ($si->r as $run) {
                    $text .= (string) $run->t;
                }
                $strings[] = $text;
            }
        }
        return $strings;
    }

    /** Cell ref like "C7" -> zero-based column index 2. */
    private static function columnIndexFromRef(string $ref): int
    {
        $letters = preg_replace('/[0-9]/', '', $ref);
        $index = 0;
        foreach (str_split($letters) as $char) {
            $index = $index * 26 + (ord(strtoupper($char)) - ord('A') + 1);
        }
        return $index - 1;
    }
}
