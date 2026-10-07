<?php

namespace App\Services;

final class InstallerException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $httpStatus = 422)
    {
        parent::__construct($message);
    }
}
