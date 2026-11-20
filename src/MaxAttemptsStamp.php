<?php

declare(strict_types=1);

namespace LessorMQ\Messenger;

use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

/**
 * Dispatch with this stamp to ask for a different number of attempts than the queue's
 * default; the queue caps it.
 */
final class MaxAttemptsStamp implements NonSendableStampInterface
{
    public function __construct(public readonly int $maxAttempts)
    {
    }
}
