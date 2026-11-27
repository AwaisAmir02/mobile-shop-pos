<?php

namespace App\Enums;

/** What a vendor credit (money the vendor owes the shop back) came from. */
enum VendorCreditSource: string
{
    case DebitNote = 'debit_note';   // W10: goods returned
    case Claim = 'claim';            // W9: a settled scheme claim
    case Cheque = 'cheque';          // W6: a cheque that paid more than was owed
}
