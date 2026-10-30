<?php

namespace App\Exceptions;

use RuntimeException;

/** A business-rule failure the client can show: `code` is a translation key, never user text. */
class ApiException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $status = 422, public readonly array $context = [])
    {
        parent::__construct($errorCode);
    }
}
