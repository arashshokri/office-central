<?php

namespace App\Exceptions;

final class OfficeHelperRequestException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $retryAfter)
    {
        parent::__construct($message, 429);
    }
}
