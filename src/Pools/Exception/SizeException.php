<?php

namespace Utopia\Pools\Exception;

use Utopia\Pools\Exception;

class SizeException extends Exception
{
    public function __construct(string $message = '', int $totalAttemptTime = 0)
    {
        parent::__construct($message, $totalAttemptTime);
    }
}
