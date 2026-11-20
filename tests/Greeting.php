<?php

declare(strict_types=1);

namespace LessorMQ\Messenger\Tests;

final class Greeting
{
    public function __construct(public string $name = '')
    {
    }
}
