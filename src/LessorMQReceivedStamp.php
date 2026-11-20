<?php

declare(strict_types=1);

namespace LessorMQ\Messenger;

use LessorMQ\Lease;
use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

/**
 * The lease a received envelope is held under; ack, reject and keepalive report on it.
 */
final class LessorMQReceivedStamp implements NonSendableStampInterface
{
    public function __construct(public readonly Lease $lease)
    {
    }
}
