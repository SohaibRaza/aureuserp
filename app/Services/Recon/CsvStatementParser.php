<?php

namespace App\Services\Recon;

final class CsvStatementParser
{
    private const FIELD_ALIASES = [
        'transaction_date'  => ['date', 'transaction_date', 'transaction date', 'posting_date', 'posting date'],
        'value_date'        => ['value_date', 'value date'],
        'amount'            => ['amount', 'transaction_amount', 'transaction amount'],
        'reference'         => ['reference', 'payment_reference', 'payment reference', 'description', 'memo'],
        'external_id'       => ['external_id', 'external id', 'transaction_id', 'transaction id', 'bank_id', 'bank id'],
        'account_identifier'=> ['account_identifier', 'account identifier', 'account_number', 'account number', 'iban'],
        'currency_code'     => ['currency', 'currency_code', 'currency code'],
        'transaction_code'  => ['transaction_code', 'transaction code', 'transaction_type', 'transaction type', 'type'],
        'partner_name'      => ['partner', 'partner_name', 'partner name', 'payee', 'payer'],
    ];

    /** @return array{rows: array<int, array<string, mixed>>, errors: array<int, string>, mapping: array<string, string>, encoding: string, delimiter: string, debit_total: string, credit_total: string, net_total: string} */
    public function parse(string $contents): array
    {
        $result = [
            'rows'      => [], 'errors' => [], 'mapping' => [], 'encoding' => 'UTF-8',
            'delimiter' => ',', 'debit_total' => '0.0000', 'credit_total' => '0.0000', 'net_total' => '0.0000',
        ];

        if (! mb_check_encoding($contents, 'UTF-8')) {
            $result['errors'][] = 'The CSV file is not valid UTF-8. Convert it to UTF-8 and try again.';

            return $result;
        }

        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        if (trim($contents) === '') {
            $result['errors'][] = 'The CSV file is empty.';

            return $result;
        }

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            $result['errors'][] = 'The CSV file could not be opened for parsing.';

            return $result;
        }
        fwrite($stream, $contents);
        rewind($stream);

        $firstPhysicalLine = strtok($contents, "\r\n") ?: '';
        $delimiter = $this->detectDelimiter($firstPhysicalLine);
        $result['delimiter'] = $delimiter === "\t" ? 'tab' : $delimiter;
        $first = fgetcsv($stream, null, $delimiter, '"', '');
        if ($first === false) {
            fclose($stream);
            $result['errors'][] = 'The CSV file does not contain a readable record.';

            return $result;
        }
        $hasHeader = $this->looksLikeHeader($first);
        $headers = $hasHeader ? array_map(fn (string $header): string => trim($header), $first) : [];
        $mapping = $hasHeader ? $this->resolveMapping($headers) : $this->positionalMapping();
        $result['mapping'] = collect($mapping)->mapWithKeys(function (int $index, string $field) use ($headers): array {
            return [$field => $headers[$index] ?? 'Column '.($index + 1)];
        })->all();

        if (! isset($mapping['transaction_date'], $mapping['amount'])) {
            fclose($stream);
            $result['errors'][] = 'The CSV header must map transaction date and amount columns.';

            return $result;
        }

        $expectedColumns = $hasHeader ? count($headers) : null;
        if (! $hasHeader) {
            rewind($stream);
        }

        $rowNumber = $hasHeader ? 1 : 0;
        while (($columns = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $rowNumber++;
            if ($columns === [null] || collect($columns)->every(fn ($value): bool => trim((string) $value) === '')) {
                continue;
            }

            if ($expectedColumns !== null && count($columns) < $expectedColumns) {
                $result['errors'][] = "Row {$rowNumber} has fewer columns than the header and was not imported.";

                continue;
            }

            $row = [];
            foreach ($mapping as $field => $index) {
                $row[$field] = trim((string) ($columns[$index] ?? ''));
            }
            $result['rows'][] = $row;

            $amount = $row['amount'] ?? '';
            if (is_string($amount) && preg_match('/^-?(?:0|[1-9]\d*)(?:\.\d+)?$/', $amount)) {
                $normalized = bcadd($amount, '0', 4);
                $result['net_total'] = bcadd($result['net_total'], $normalized, 4);
                if (bccomp($normalized, '0', 4) < 0) {
                    $result['debit_total'] = bcadd($result['debit_total'], ltrim($normalized, '-'), 4);
                } else {
                    $result['credit_total'] = bcadd($result['credit_total'], $normalized, 4);
                }
            }
        }
        fclose($stream);

        return $result;
    }

    private function detectDelimiter(string $line): string
    {
        $counts = [',' => substr_count($line, ','), ';' => substr_count($line, ';'), "\t" => substr_count($line, "\t")];
        arsort($counts);

        return (string) array_key_first($counts);
    }

    /** @param array<int, string> $columns */
    private function looksLikeHeader(array $columns): bool
    {
        $normalized = array_map($this->normalizeHeader(...), $columns);

        return count(array_intersect($normalized, ['date', 'transaction_date', 'transaction date', 'amount'])) >= 2;
    }

    /** @param array<int, string> $headers
     * @return array<string, int>
     */
    private function resolveMapping(array $headers): array
    {
        $normalized = array_map($this->normalizeHeader(...), $headers);
        $mapping = [];
        foreach (self::FIELD_ALIASES as $field => $aliases) {
            foreach ($aliases as $alias) {
                $index = array_search($alias, $normalized, true);
                if ($index !== false) {
                    $mapping[$field] = $index;
                    break;
                }
            }
        }

        return $mapping;
    }

    /** @return array<string, int> */
    private function positionalMapping(): array
    {
        return [
            'transaction_date' => 0, 'amount' => 1, 'reference' => 2,
            'partner_name'     => 3, 'transaction_code' => 4, 'value_date' => 5,
            'external_id'      => 6, 'account_identifier' => 7, 'currency_code' => 8,
        ];
    }

    private function normalizeHeader(string $header): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', str_replace(['-', '_'], ' ', $header)) ?? $header));
    }
}
