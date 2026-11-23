# LessorMQ transport for Symfony Messenger

A Messenger transport on [LessorMQ](../../docs/protocol.md), built on
[lessormq/client](../php). The queue decides what happens to a failed message
(retries, backoff, the dead-letter set); the transport only claims, acks and fails.

```sh
composer require lessormq/messenger
```

With Flex the bundle registers itself; otherwise add
`LessorMQ\Messenger\LessorMQMessengerBundle::class => ['all' => true]` to
`config/bundles.php`. Each transport discovers a PSR-18 client of its own rather than
using the app's, because `--keepalive` extends the lease from a signal handler while your
handler may be in the middle of a request on the app's client, which Symfony HttpClient
does not allow. `symfony/http-client` is all it needs, the PSR-7 messages come with
lessormq/client.

## Configure

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        transports:
            mail:
                dsn: 'lessormq://%env(LESSORMQ_KEY)%@mq.example.com/mail'
                options:
                    wait_seconds: 5
                # Required: retries are the queue's, not Messenger's.
                retry_strategy:
                    max_retries: 0
        routing:
            App\Message\SendMail: mail
```

`lessormq://` speaks HTTPS; `lessormq+http://` plain HTTP, for a server inside the
same cluster. Options, in the DSN query or under `options:`:

| Option | Default | |
|---|---|---|
| `queue` | DSN path | the queue |
| `api_key` | DSN user | a key with the publish and/or consume scope |
| `wait_seconds` | 5 | how long a claim waits for a message (at most 20) |
| `lease_seconds` | the queue's | lease per claim, and per keepalive |
| `consumer` | host:pid | shown to administrators, in the server's list of workers |
| `route` | none | the path this worker runs jobs through (VPN, proxy, account) |
| `route_file` | none | a file holding the route, read on every claim |
| `route_required` | false | skip claiming while there is no route |
| `http_retries` | 3 | retries after network errors, 5xx and 429 |

There is no option for retries, delays or dead-lettering: set those on the queue
(`PATCH /v1/queues/{queue}`).

## How it behaves

- A handler that succeeds: the message is acked.
- A handler that throws: the message is failed with the exception's class and message.
  `UnrecoverableMessageHandlingException` (anything implementing
  `UnrecoverableExceptionInterface`) and `LessorMQ\PermanentFailure` are reported as
  permanent; a message that cannot be decoded too. What follows is the queue's
  decision.
- Messenger's own retry is refused with an exception: it would publish a copy and ack
  the original, so the queue would never count the attempt. Hence `max_retries: 0`.
- A failure transport is not needed: failed messages stay in the queue's dead-letter
  set, where they can be inspected and replayed.
- Long handlers: run `messenger:consume --keepalive` and the lease is renewed (for
  `lease_seconds`, or the queue's lease) every few seconds. A failed renewal is logged
  and tried again at the next one; it does not fail the message.
- `DelayStamp` becomes the message's delay. Dispatch with
  `new LessorMQ\Messenger\DedupKeyStamp('order-7')` for at most one open message per
  key, and `new MaxAttemptsStamp(3)` to ask for fewer or more attempts than the queue's
  default.
- A JSON body (the Symfony serializer) is stored as JSON, so messages can be read in the
  queue; any other body travels as a string.
- `messenger:stats` shows the queue's ready plus delayed messages.

## Moving from another transport

- Let the queue decide about retries: set the transport's `retry_strategy.max_retries`
  to 0 and the queue's `max_attempts` (and `backoff`) to what the retry strategy did.
  The transport refuses Messenger's own redelivery, so a message is never tried twice
  for one failure.
- A `failed` transport is not needed: messages that ran out of attempts stay in the
  queue's dead-letter set, where an administrator can look at and replay them.
- Deduplication is a `DedupKeyStamp` on dispatch.
- Workers on different network paths (VPN exits, proxies) pass theirs as `route`, or
  `route_file` when it can change while the worker runs; see [routes](../../docs/concepts.md#routes).
- Run the old and the new transport side by side and move one message class at a time
  in `framework.messenger.routing`.
