<?php
declare(strict_types=1);

namespace FinancingBridge\Security;

enum SignatureResult: string
{
    case Valid = 'valid';
    case Invalid = 'invalid';
    case Stale = 'stale';
    case Malformed = 'malformed';
}
