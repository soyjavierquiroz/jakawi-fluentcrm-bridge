<?php

namespace Jakawi\FluentCrmBridge;

final class BridgeError extends \RuntimeException
{
    public function __construct(public string $resultCode, public int $httpStatus)
    {
        parent::__construct($resultCode);
    }
}
