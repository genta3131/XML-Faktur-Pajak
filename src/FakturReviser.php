<?php

require_once __DIR__ . '/Matcher.php';

/**
 * FakturReviser — menerapkan hasil Matcher ke XML faktur:
 * mengubah BuyerDocument / BuyerDocumentNumber (dan BuyerTin untuk NPWP)
 * pada setiap <TaxInvoice> yang RefDesc-nya ada di mapping.
 */
final class FakturReviser
{
    /**
     * @param string $xmlPath          file faktur sumber
     * @param array  $matchesByRefdesc kunci = RefDesc penuh, value = hasil Matcher::matchRefDesc()
     * @param string $outputPath       lokasi file hasil
     * @return array{updated: int, untouched: int, sanitized: int, invalidLeft: int}
     */
    public static function apply(string $xmlPath, array $matchesByRefdesc, string $outputPath): array
    {
        $prev = libxml_use_internal_errors(true);
        $xml = simplexml_load_file($xmlPath);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if ($xml === false) {
            throw new RuntimeException("Gagal membaca XML: $xmlPath");
        }

        $updated = 0;
        $untouched = 0;
        $sanitized = 0;
        $invalidLeft = 0;

        foreach ($xml->ListOfTaxInvoice->TaxInvoice as $invoice) {
            $refDesc = (string) $invoice->RefDesc;
            $match = $matchesByRefdesc[$refDesc] ?? null;

            if ($match !== null && $match['status'] === 'matched') {
                $number = Matcher::normalizeNumber((string) $match['number']);
                $invoice->BuyerDocument = $match['docType'];
                $invoice->BuyerDocumentNumber = $number;
                if ($match['docType'] === 'NPWP') {
                    $invoice->BuyerTin = $number;
                } else {
                    unset($invoice->BuyerTin);
                }
                if (!Matcher::validNumber((string) $match['docType'], $number)) {
                    $invalidLeft++;
                }
                $updated++;
                continue;
            }

            $untouched++;

            // Hanya nomor bermasalah yang dibersihkan, dan hanya bila hasilnya valid.
            $docType = (string) $invoice->BuyerDocument;
            $raw = (string) $invoice->BuyerDocumentNumber;
            if ($raw === '' || !in_array($docType, ['NPWP', 'National ID'], true)) {
                continue;
            }
            if (preg_match('/\D/', $raw)) {
                $digits = Matcher::normalizeNumber($raw);
                if (Matcher::validNumber($docType, $digits)) {
                    $invoice->BuyerDocumentNumber = $digits;
                    $sanitized++;
                } else {
                    $invalidLeft++;
                }
            } elseif (!Matcher::validNumber($docType, $raw)) {
                $invalidLeft++;
            }
        }

        if ($xml->asXML($outputPath) === false) {
            throw new RuntimeException("Gagal menyimpan XML: $outputPath");
        }

        return [
            'updated' => $updated,
            'untouched' => $untouched,
            'sanitized' => $sanitized,
            'invalidLeft' => $invalidLeft,
        ];
    }
}