<?php

declare(strict_types=1);

namespace App\Enum;

enum IssueSeverity: string
{
    case Error = 'error';
    case Warning = 'warning';
    case Info = 'info';
}
