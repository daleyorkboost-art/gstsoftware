<?php
declare(strict_types=1);
namespace App;
/** Minimal genuine XLSX with inline strings: no formula execution, no dependency on Excel. */
final class SpreadsheetService
{
    public function fromCsv(string $source, string $target): void
    {
        $xml = $target . ".xml";
        $out = fopen($xml, "wb");
        $in = fopen($source, "rb");
        fwrite(
            $out,
            '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>',
        );
        $rowNumber = 0;
        while (($row = fgetcsv($in)) !== false) {
            $rowNumber++;
            fwrite($out, '<row r="' . $rowNumber . '">');
            foreach ($row as $index => $value) {
                if ($rowNumber === 1 && $index === 0) {
                    $value = preg_replace('/^\xEF\xBB\xBF/', "", $value);
                }
                $value = preg_replace(
                    '/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}]/u',
                    "",
                    (string) $value,
                );
                $column = "";
                $n = $index + 1;
                while ($n > 0) {
                    $n--;
                    $column = chr(65 + ($n % 26)) . $column;
                    $n = intdiv($n, 26);
                }
                fwrite(
                    $out,
                    '<c r="' .
                        $column .
                        $rowNumber .
                        '" t="inlineStr"><is><t xml:space="preserve">' .
                        htmlspecialchars(
                            $value,
                            ENT_XML1 | ENT_QUOTES,
                            "UTF-8",
                        ) .
                        "</t></is></c>",
                );
            }
            fwrite($out, "</row>");
        }
        fwrite($out, "</sheetData></worksheet>");
        fclose($out);
        fclose($in);
        $zip = new \ZipArchive();
        if (
            $zip->open(
                $target,
                \ZipArchive::CREATE | \ZipArchive::OVERWRITE,
            ) !== true
        ) {
            throw new HttpError(500, "Unable to generate Excel workbook.");
        }
        $zip->addFromString(
            "[Content_Types].xml",
            '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
        );
        $zip->addFromString(
            "_rels/.rels",
            '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        );
        $zip->addFromString(
            "xl/workbook.xml",
            '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Invoices" sheetId="1" r:id="rId1"/></sheets></workbook>',
        );
        $zip->addFromString(
            "xl/_rels/workbook.xml.rels",
            '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
        );
        $zip->addFile($xml, "xl/worksheets/sheet1.xml");
        $zip->close();
        unlink($xml);
    }
}
