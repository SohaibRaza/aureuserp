<?php

use App\Services\Recon\CsvStatementParser;

it('maps a headed csv and preserves source fields and exact controls', function (): void {
    $result = app(CsvStatementParser::class)->parse(<<<'CSV'
Transaction Date,Value Date,Amount,Reference,External ID,Account Number,Currency,Transaction Code,Partner
2026-09-03,2026-09-04,-10.2500,FEE-1,bank-1,PK00ABC,PKR,FEE,Example Bank
2026-09-05,2026-09-05,25.5000,DEP-1,bank-2,PK00ABC,PKR,DEP,Customer
CSV);

    expect($result['errors'])->toBe([])
        ->and($result['encoding'])->toBe('UTF-8')
        ->and($result['mapping']['transaction_date'])->toBe('Transaction Date')
        ->and($result['rows'])->toHaveCount(2)
        ->and($result['rows'][0]['value_date'])->toBe('2026-09-04')
        ->and($result['rows'][0]['external_id'])->toBe('bank-1')
        ->and($result['rows'][0]['account_identifier'])->toBe('PK00ABC')
        ->and($result['rows'][0]['currency_code'])->toBe('PKR')
        ->and($result['debit_total'])->toBe('10.2500')
        ->and($result['credit_total'])->toBe('25.5000')
        ->and($result['net_total'])->toBe('15.2500');
});

it('reports invalid encoding and malformed rows instead of silently dropping them', function (): void {
    $invalidEncoding = app(CsvStatementParser::class)->parse("date,amount\n2026-09-03,1.00\xFF");
    $malformed = app(CsvStatementParser::class)->parse("date,amount,reference\n2026-09-03\n");

    expect($invalidEncoding['errors'])->toContain('The CSV file is not valid UTF-8. Convert it to UTF-8 and try again.')
        ->and($malformed['errors'])->toContain('Row 2 has fewer columns than the header and was not imported.')
        ->and($malformed['rows'])->toBe([]);
});

it('parses quoted multiline and escaped fields as one logical CSV record', function (): void {
    $result = app(CsvStatementParser::class)->parse("Transaction Date,Amount,Reference\r\n2026-09-03,-12.50,\"Bank fee\r\nmonthly \"\"service\"\" charge\"\r\n");

    expect($result['errors'])->toBe([])
        ->and($result['rows'])->toHaveCount(1)
        ->and($result['rows'][0]['reference'])->toBe("Bank fee\r\nmonthly \"service\" charge")
        ->and($result['debit_total'])->toBe('12.5000')
        ->and($result['net_total'])->toBe('-12.5000');
});
