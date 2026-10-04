<?php

declare(strict_types=1);

namespace Odden\Core\Enums;

/**
 * Package-neutral view of a deal's status, so packages can reason about deals without
 * depending on getodden/crm-sales.
 */
enum DealOutcome: string
{
    case Open = 'open';
    case Won = 'won';
    case Lost = 'lost';
}
