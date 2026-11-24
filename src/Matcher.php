<?php

/**
 * Matcher — mencocokkan RefDesc faktur (`CardCode-Invoice-Seq-Nama`)
 * dengan data master customer, lalu menentukan dokumen yang dipakai:
 *
 * - NPWP terisi  -> BuyerDocument = NPWP,          number = NPWP
 * - NPWP kosong, KTP terisi -> BuyerDocument = National ID, number = KTP
 * - dua-duanya kosong       -> status both_empty (faktur tidak disentuh)
 * - tidak ada di master     -> status not_found
 */
final class Matcher
{
    /** Hanya digit — buang spasi, titik, strip. */
    public static function normalizeNumber(string $number): string
    {
        return preg_replace('/\D+/', '', $number) ?? '';
    }

    /** NPWP 15-16 digit (format lama & baru), National ID (NIK) 16 digit. */
    public static function validNumber(string $docType, string $number): bool
    {
        $digits = self::normalizeNumber($number);
        if ($docType === 'NPWP') {
            return strlen($digits) === 15 || strlen($digits) === 16;
        }
        if ($docType === 'National ID') {
            return strlen($digits) === 16;
        }
        return false;
    }
    /**
     * @param array[] $rows hasil MasterReader::read()
     * @return array Kunci "cardCode|invoice" (di-trim) => row master
     */
    public static function buildIndex(array $rows): array
    {
        $index = [];
        foreach ($rows as $row) {
            $key = $row['cardCode'] . '|' . $row['invoice'];
            if (trim($key, '|') === '') {
                continue;
            }
            $index[$key] = $row;
        }
        return $index;
    }

    /**
     * @return array{refdesc: string, cardCode: ?string, invoice: ?string,
     *               name: ?string, docType: ?string, number: ?string,
     *               status: 'matched'|'both_empty'|'not_found'}
     */
    public static function matchRefDesc(string $refDesc, array $index): array
    {
        $result = [
            'refdesc'  => $refDesc,
            'cardCode' => null,
            'invoice'  => null,
            'name'     => null,
            'docType'  => null,
            'number'   => null,
            'status'   => 'not_found',
        ];

        // Format RefDesc: CardCode-Invoice-Seq-Nama
        $parts = explode('-', $refDesc, 3);
        if (count($parts) < 2 || trim($parts[0]) === '' || trim($parts[1]) === '') {
            return $result;
        }

        $cardCode = trim($parts[0]);
        $invoice  = trim($parts[1]);
        $result['cardCode'] = $cardCode;
        $result['invoice']  = $invoice;

        $row = $index[$cardCode . '|' . $invoice] ?? null;
        if ($row === null) {
            return $result;
        }

        $result['name'] = $row['custName'] !== '' ? $row['custName']
            : ($row['foreignName'] !== '' ? $row['foreignName'] : null);

        if ($row['npwp'] !== '') {
            $result['docType'] = 'NPWP';
            $result['number']  = $row['npwp'];
            $result['status']  = 'matched';
        } elseif ($row['ktp'] !== '') {
            $result['docType'] = 'National ID';
            $result['number']  = $row['ktp'];
            $result['status']  = 'matched';
        } else {
            $result['status'] = 'both_empty';
        }

        return $result;
    }

    /**
     * Bangun hasil match untuk seluruh invoice dalam XML, memakai baris master
     * yang sudah dibaca (tidak membaca ulang master dari file).
     *
     * @param string $xmlPath
     * @param array  $masterRows hasil MasterReader::read()
     * @return array<string,array> kunci RefDesc => hasil matchRefDesc()
     *
     * @throws \InvalidArgumentException bila $masterRows kosong
     * @throws \RuntimeException bila XML tidak bisa dibaca
     */
    public static function matchesFromXml(string $xmlPath, array $masterRows): array
    {
        if (!$masterRows) {
            throw new \InvalidArgumentException('Master rows kosong.');
        }
        $index = self::buildIndex($masterRows);

        $prev = libxml_use_internal_errors(true);
        $xml = simplexml_load_file($xmlPath);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if ($xml === false) {
            throw new \RuntimeException('File XML faktur tidak bisa dibaca.');
        }

        $matches = [];
        foreach ($xml->ListOfTaxInvoice->TaxInvoice as $invoice) {
            $refDesc = (string) $invoice->RefDesc;
            if ($refDesc === '' || isset($matches[$refDesc])) {
                continue;
            }
            $matches[$refDesc] = self::matchRefDesc($refDesc, $index);
        }
        return $matches;
    }
}
