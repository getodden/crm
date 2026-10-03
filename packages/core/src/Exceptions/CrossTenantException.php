<?php

declare(strict_types=1);

namespace Odden\Core\Exceptions;

use RuntimeException;

/**
 * Thrown when an operation would link or merge records that belong to different tenants, or
 * touch a record that is not the active tenant's.
 */
class CrossTenantException extends RuntimeException {}
