<?php

require_once __DIR__ . '/../lib/simplexlsxgen.php';

if (!class_exists('SimpleXLSXGen')) {
    class_alias(\Shuchkin\SimpleXLSXGen::class, 'SimpleXLSXGen');
}

/**
 * FakturExporter — mengonversi XML faktur menjadi XLSX dengan 2 sheet:
 *   1. Ringkasan  — 1 baris per faktur (total DPP/PPN/STLG dihitung dari barang)
 *   2. Detail Barang — 1 baris per <GoodService>
 */
final class FakturExporter
{
    private const RINGKASAN_HEADER = [
        'No', 'TaxInvoiceDate', 'RefDesc', 'BuyerName', 'BuyerDocument',
        'BuyerDocumentNumber', 'BuyerIDTKU', 'Jumlah Barang', 'Total DPP', 'Total PPN', 'Total STLG',
    ];

    private const DETAIL_HEADER = [
        'No Faktur', 'RefDesc', 'Nama Barang', 'Qty', 'Unit', 'Harga', 'Diskon', 'DPP', 'PPN',
    ];

    /**
     * @return array{invoices: int, items: int}
     */
    public static function export(string $xmlPath, string $xlsxOut): array
    {
        $prev = libxml_use_internal_errors(true);
        $xml = simplexml_load_file($xmlPath);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if ($xml === false) {
            throw new RuntimeException("Gagal membaca XML: $xmlPath");
        }

        $ringkasan = [self::RINGKASAN_HEADER];
        $detail = [self::DETAIL_HEADER];
        $no = 0;
        $items = 0;

        foreach ($xml->ListOfTaxInvoice->TaxInvoice as $invoice) {
            $no++;
            $refDesc = (string) $invoice->RefDesc;

            $totalDpp = 0.0;
            $totalPpn = 0.0;
            $totalStlg = 0.0;
            $itemCount = 0;

            if (isset($invoice->ListOfGoodService->GoodService)) {
                foreach ($invoice->ListOfGoodService->GoodService as $g) {
                    $itemCount++;
                    $items++;
                    $totalDpp += (float) $g->TaxBase;
                    $totalPpn += (float) $g->VAT;
                    $totalStlg += (float) $g->STLG;

                    $detail[] = [
                        $no,
                        $refDesc,
                        trim((string) $g->Name),
                        (string) $g->Qty,
                        (string) $g->Unit,
                        (string) $g->Price,
                        (string) $g->TotalDiscount,
                        (string) $g->TaxBase,
                        (string) $g->VAT,
                    ];
                }
            }

            $ringkasan[] = [
                $no,
                (string) $invoice->TaxInvoiceDate,
                $refDesc,
                (string) $invoice->BuyerName,
                (string) $invoice->BuyerDocument,
                (string) $invoice->BuyerDocumentNumber,
                (string) $invoice->BuyerIDTKU,
                $itemCount,
                number_format($totalDpp, 2, '.', ''),
                number_format($totalPpn, 2, '.', ''),
                number_format($totalStlg, 2, '.', ''),
            ];
        }

        $gen = new SimpleXLSXGen();
        $gen->addSheet($ringkasan, 'Ringkasan');
        $gen->addSheet($detail, 'Detail Barang');
        $gen->saveAs($xlsxOut);

        return ['invoices' => $no, 'items' => $items];
    }
}
