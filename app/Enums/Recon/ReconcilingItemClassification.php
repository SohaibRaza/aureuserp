<?php

namespace App\Enums\Recon;

enum ReconcilingItemClassification: string
{
    case OutstandingPayment = 'outstanding_payment';
    case DepositInTransit = 'deposit_in_transit';
    case MissingBookEntry = 'missing_book_entry';
    case BankError = 'bank_error';
    case Duplicate = 'duplicate';
    case ReturnedPayment = 'returned_payment';
    case Transfer = 'transfer';
    case Unidentified = 'unidentified';
    case Disputed = 'disputed';
    case InvestigationRequired = 'investigation_required';
}
