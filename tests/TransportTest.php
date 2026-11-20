<?php

declare(strict_types=1);

namespace LessorMQ\Messenger\Tests;

use LessorMQ\Client;
use LessorMQ\ClientOptions;
use LessorMQ\Messenger\DedupKeyStamp;
use LessorMQ\Messenger\LessorMQReceivedStamp;
use LessorMQ\Messenger\LessorMQTransport;
use LessorMQ\Messenger\LessorMQTransportFactory;
use LessorMQ\Messenger\MaxAttemptsStamp;
use LessorMQ\Messenger\TransportOptions;
use LessorMQ\Tests\Fake\FakeHttp;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\Serialization\Serializer;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

class TransportTest extends TestCase
{
    private FakeHttp $http;

    protected function setUp(): void
    {
        $this->http = new FakeHttp();
    }

    public function testSendStoresAJsonBodyAsJsonWithItsHeadersAndStamps(): void
    {
        $this->http->reply(201, $this->published());

        $sent = $this->transport()->send(new Envelope(new Greeting('Ada'), [
            new DelayStamp(2500),
            new DedupKeyStamp('greet-ada'),
            new MaxAttemptsStamp(3),
        ]));

        $body = $this->http->body(0);
        $this->assertSame('POST /v1/queues/greetings/messages', $this->http->path(0));
        $this->assertSame(['name' => 'Ada'], $body['body']);
        $this->assertSame(Greeting::class, $body['headers']['type']);
        $this->assertSame('greet-ada', $body['dedup_key']);
        $this->assertSame(3, $body['delay_seconds'], '2.5 s rounds up');
        $this->assertSame(3, $body['max_attempts']);
        $this->assertSame('m-1', $sent->last(TransportMessageIdStamp::class)->getId());
    }

    public function testGetDecodesTheEnvelopeAndKeepsTheLease(): void
    {
        $this->http->reply(201, $this->published());
        $this->transport()->send(new Envelope(new Greeting('Ada')));
        $published = $this->http->body(0);

        $this->http->reply(200, $this->lease($published['body'], $published['headers']));
        $received = iterator_to_array($this->transport()->get());

        $this->assertCount(1, $received);
        $this->assertEquals(new Greeting('Ada'), $received[0]->getMessage());
        $this->assertSame('r-1', $received[0]->last(LessorMQReceivedStamp::class)->lease->receipt);
        $this->assertSame('m-1', $received[0]->last(TransportMessageIdStamp::class)->getId());
        $this->assertSame(
            ['consumer' => 'host-1', 'wait_seconds' => 5],
            $this->http->body(1),
        );
    }

    public function testANonJsonBodyTravelsAsAStringAndComesBackIntact(): void
    {
        $serializer = new PhpSerializer();
        $envelope = new Envelope(new Greeting("Zo\xEB"));
        $this->http->reply(201, $this->published());

        $this->transport(serializer: $serializer)->send($envelope);
        $published = $this->http->body(0);

        $this->assertIsString($published['body']);

        $this->http->reply(200, $this->lease($published['body'], $published['headers'] ?? []));
        $received = iterator_to_array($this->transport(serializer: $serializer)->get());

        $this->assertSame("Zo\xEB", $received[0]->getMessage()->name);
    }

    public function testAnUndecodableMessageIsFailedAsPermanent(): void
    {
        $this->http->reply(200, $this->lease(['name' => 'x'], ['type' => 'App\\Gone']))
            ->reply(200, ['outcome' => 'dead_lettered', 'available_at' => null, 'attempt' => 1, 'max_attempts' => 7]);

        $this->assertSame([], iterator_to_array($this->transport()->get()));
        $this->assertSame('POST /v1/messages/m-1/fail', $this->http->path(1));
        $this->assertTrue($this->http->body(1)['permanent']);
    }

    public function testRejectReportsTheErrorAndOnlyUnrecoverableIsPermanent(): void
    {
        $this->http->reply(200, $this->failed())->reply(200, $this->failed());
        $lease = $this->receivedEnvelope();

        $this->transport()->reject($lease->with(ErrorDetailsStamp::create(new \RuntimeException('upstream 503'))));
        $this->transport()->reject($lease->with(ErrorDetailsStamp::create(
            new UnrecoverableMessageHandlingException('no such user'),
        )));

        $this->assertSame(['message' => 'upstream 503', 'type' => 'RuntimeException'], $this->http->body(0)['error']);
        $this->assertFalse($this->http->body(0)['permanent']);
        $this->assertTrue($this->http->body(1)['permanent']);
    }

    public function testALostLeaseOnAckIsLoggedNotThrown(): void
    {
        $this->http->reply(409, ['code' => 'lease_lost']);

        $this->transport()->ack($this->receivedEnvelope());

        $this->assertCount(1, $this->http->sent);
    }

    public function testKeepaliveRenewsForTheLeaseLengthNotTheAlarmInterval(): void
    {
        $this->http->reply(200, ['lease_until' => '2030-01-01T00:10:00Z']);

        $this->transport(new TransportOptions('greetings', 'k', leaseSeconds: 600, consumer: 'host-1'))
            ->keepalive($this->receivedEnvelope(), 5);

        $this->assertSame(['receipt' => 'r-1', 'lease_seconds' => 600], $this->http->body(0));
    }

    public function testRouteFileIsReadOnEveryClaimAndRequiredMeansSkip(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'route');
        $options = new TransportOptions('greetings', 'k', consumer: 'host-1', routeFile: $file, routeRequired: true);

        file_put_contents($file, '');
        $this->assertSame([], iterator_to_array($this->transport($options)->get()));
        $this->assertCount(0, $this->http->sent, 'no route, no claim');

        file_put_contents($file, "vpn-nl-03\n");
        $this->http->reply(204);
        iterator_to_array($this->transport($options)->get());
        $this->assertSame('vpn-nl-03', $this->http->body(0)['route']);

        unlink($file);
    }

    public function testRefusesMessengersOwnRetry(): void
    {
        $this->expectException(\Symfony\Component\Messenger\Exception\LogicException::class);
        $this->expectExceptionMessage('max_retries: 0');

        $this->transport()->send($this->receivedEnvelope()->with(new RedeliveryStamp(1)));
    }

    public function testMessageCountIsReadyPlusDelayed(): void
    {
        $this->http->reply(200, ['stats' => ['ready' => 4, 'delayed' => 2, 'processing' => 9]])
            ->reply(404, ['code' => 'not_found']);

        $this->assertSame(6, $this->transport()->getMessageCount());
        $this->assertSame(0, $this->transport()->getMessageCount());
    }

    public function testFactoryReadsTheDsn(): void
    {
        $factory = new LessorMQTransportFactory($this->http);

        $this->assertTrue($factory->supports('lessormq+http://k@queue-svc/mail', []));
        $this->assertFalse($factory->supports('dbqueue://', []));

        $this->http->reply(204);
        $transport = $factory->createTransport(
            'lessormq+http://lmq_secret@queue-svc:8080/mail?wait_seconds=2',
            ['transport_name' => 'mail', 'route' => 'proxy-1'],
            new PhpSerializer(),
        );
        iterator_to_array($transport->get());

        $request = $this->http->sent[0];
        $this->assertSame('http://queue-svc:8080/v1/queues/mail/claims', (string) $request->getUri());
        $this->assertSame('Bearer lmq_secret', $request->getHeaderLine('Authorization'));
        $this->assertSame(2, $this->http->body(0)['wait_seconds']);
        $this->assertSame('proxy-1', $this->http->body(0)['route']);

        $this->expectException(\InvalidArgumentException::class);
        $factory->createTransport('lessormq://k@host/mail', ['maxRetries' => 6], new PhpSerializer());
    }

    private function transport(
        ?TransportOptions $options = null,
        ?SerializerInterface $serializer = null,
    ): LessorMQTransport {
        $factory = new Psr17Factory();
        $options ??= new TransportOptions('greetings', 'k', consumer: 'host-1');
        $client = new Client(
            'http://lessormq.test',
            'k',
            new ClientOptions(retryDelayMs: 1),
            $this->http,
            $factory,
            $factory,
        );

        return new LessorMQTransport($client, $serializer ?? Serializer::create(), $options->queue, $options);
    }

    private function receivedEnvelope(): Envelope
    {
        $lease = \LessorMQ\Lease::fromArray($this->lease(['name' => 'Ada'], []));

        return new Envelope(new Greeting('Ada'), [new LessorMQReceivedStamp($lease)]);
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    private function lease(mixed $body, array $headers): array
    {
        return [
            'receipt' => 'r-1',
            'lease_until' => '2030-01-01T00:05:00Z',
            'message' => [
                'id' => 'm-1',
                'queue' => 'greetings',
                'body' => $body,
                'headers' => $headers,
                'attempt' => 1,
                'max_attempts' => 7,
                'created_at' => '2030-01-01T00:00:00Z',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function published(): array
    {
        return [
            'id' => 'm-1',
            'queue' => 'greetings',
            'status' => 'pending',
            'created_at' => '2030-01-01T00:00:00Z',
            'available_at' => '2030-01-01T00:00:00Z',
            'duplicate' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function failed(): array
    {
        return ['outcome' => 'retrying', 'available_at' => '2030-01-01T00:00:10Z', 'attempt' => 1, 'max_attempts' => 7];
    }
}
