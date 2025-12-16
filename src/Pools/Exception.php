<?php

namespace Utopia\Pools;

class Exception extends \Exception
{
    public function __construct(string $message, protected int $totalAttemptTime)
    {
        parent::__construct($message);
    }

    public function getTotalAttemptTime(): int
    {
        return $this->totalAttemptTime;
    }
}
