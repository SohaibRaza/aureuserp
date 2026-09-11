<?php

namespace App\Enums\Recon;

enum ReconcilingItemStatus: string
{
    case Open = 'open';
    case CarriedForward = 'carried_forward';
    case Resolved = 'resolved';
    case Excluded = 'excluded';
}
