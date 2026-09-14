# Bank Statement Import Contract

## Supported contract

The current ingestion boundary is staged CSV or normalized feed rows. A source is not accepted merely because it can be parsed.

Required identity and controls:

- reconciliation company, journal, bank account, liquidity account, and currency;
- statement start/end and statement date;
- bank account identifier and currency code;
- declared opening and closing balances;
- optional declared debit total, credit total, and transaction count;
- original filename/source identifier, parser version, and content hash.

Each normalized row must retain transaction date, signed amount, reference/description, external identifier when supplied, source row number, and a stable fingerprint.

## Stages

1. Parse and normalize without posting accounting entries.
2. Preview mappings and control totals.
3. Validate period, identity, numeric precision, required fields, and statement arithmetic.
4. Quarantine the batch on material identity/control errors; accept nothing partially.
5. Persist accepted rows atomically with source evidence.
6. Detect repeated content and repeated rows server-side. A repeated import must not duplicate accepted evidence.

Changing the reconciliation period from imported metadata requires an explicit user choice and recorded reason. Import must not silently overwrite period intent.

## Failure behavior

- Malformed dates/amounts, missing identity, account/currency mismatch, failed controls, and out-of-period rows are reported precisely.
- A quarantined or rejected row cannot become a match source.
- Retrying the same request is idempotent; the UI label “Verify duplicate statement” reflects that behavior.
- Imported rows never create journal entries.

## Deferred adapters

Bank-specific saved mappings, locale-aware profiles, OFX/QFX, MT940, and CAMT.053 are future capabilities. Each adapter must emit this canonical contract, retain its parser/profile version, and pass representative fixture and control-total tests before being advertised as supported.
