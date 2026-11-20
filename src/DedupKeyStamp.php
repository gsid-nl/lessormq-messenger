<?php

declare(strict_types=1);

namespace LessorMQ\Messenger;

use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

/**
 * Dispatch with this stamp to publish with a dedup_key: while a message with the key is
 * open in the queue, dispatching again does not add another.
 *
 *   $bus->dispatch($message, [new DedupKeyStamp('invoice-' . $id)]);
 */
final class DedupKeyStamp implements NonSendableStampInterface
{
    public function __construct(public readonly string $key)
    {
    }
}
