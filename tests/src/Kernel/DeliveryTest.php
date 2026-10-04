<?php

declare(strict_types=1);

namespace Drupal\Tests\one_record\Kernel;

use Drupal\one_record\Config\OneRecordConfig;
use Drupal\one_record\Notification\Delivery;
use Drupal\one_record\Notification\DeliveryOutcome;
use Drupal\one_record\Notification\QueuedOutbox;
use Drupal\one_record\Plugin\QueueWorker\OutboxWorker;
use Drupal\one_record\Store\DatabaseNotificationOutbox;
use Drupal\Tests\one_record\Support\MockHttp;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response;
use LambdaTwelve\OneRecord\Api\Notification;
use LambdaTwelve\OneRecord\Api\NotificationEventType;
use LambdaTwelve\OneRecord\Api\ServerInformation;
use LambdaTwelve\OneRecord\Rdf\Iri;
use LambdaTwelve\OneRecord\Server\Spi\OutboundNotification;
use LambdaTwelve\OneRecord\Spec\ApiVersion;
use LambdaTwelve\OneRecord\Vocabulary\Generated\Cargo;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Outbox rows are delivered with the SDK client, retried, and given up.
 */
#[Group('one_record')]
#[RunTestsInSeparateProcesses]
final class DeliveryTest extends OneRecordKernelTestBase {

  private const PARTNER_ENDPOINT = 'https://1r.partner.example';

  /**
   * {@inheritdoc}
   */
  protected bool $mockHttp = TRUE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->config(OneRecordConfig::NAME)
      ->set('partners', [
        [
          'agent' => self::PARTNER,
          'endpoint' => self::PARTNER_ENDPOINT,
          'token_url' => self::PARTNER_ENDPOINT . '/oauth/token',
          'client_id' => 'drupal-holder',
        ],
      ])
      ->set('delivery.max_attempts', 2)
      ->save();
    $this->setSetting('one_record.partner_secrets', ['drupal-holder' => 'partner-secret']);
  }

  /**
   * Tests the happy path: token, server information, notification, delivered.
   */
  public function testDelivery(): void {
    $id = $this->enqueue();
    $queue = $this->container->get('queue')->get(QueuedOutbox::QUEUE);
    self::assertSame(1, $queue->numberOfItems());

    MockHttp::respond(new Response(200, ['Content-Type' => 'application/json'], json_encode(['access_token' => 'partner-token', 'token_type' => 'Bearer', 'expires_in' => 3600], JSON_THROW_ON_ERROR)));
    MockHttp::respond(new Response(200, ['Content-Type' => 'application/ld+json; version=2.3.0'], json_encode($this->partnerInformation()->toJsonLd(), JSON_THROW_ON_ERROR)));
    MockHttp::respond(new Response(204));

    $item = $queue->claimItem();
    self::assertIsObject($item);
    self::assertObjectHasProperty('data', $item);
    $worker = $this->container->get('plugin.manager.queue_worker')->createInstance(QueuedOutbox::QUEUE);
    self::assertInstanceOf(OutboxWorker::class, $worker);
    $worker->processItem($item->data);
    $queue->deleteItem($item);

    $requests = MockHttp::requests();
    self::assertCount(3, $requests);
    self::assertSame('POST', $requests[0]->getMethod());
    self::assertSame(self::PARTNER_ENDPOINT . '/oauth/token', (string) $requests[0]->getUri());
    parse_str((string) $requests[0]->getBody(), $form);
    self::assertSame(['grant_type' => 'client_credentials', 'client_id' => 'drupal-holder', 'client_secret' => 'partner-secret'], $form);
    self::assertSame('GET', $requests[1]->getMethod());
    self::assertSame(self::PARTNER_ENDPOINT . '/', (string) $requests[1]->getUri());
    self::assertSame('POST', $requests[2]->getMethod());
    self::assertSame(self::PARTNER_ENDPOINT . '/notifications', (string) $requests[2]->getUri());
    self::assertSame('Bearer partner-token', $requests[2]->getHeaderLine('Authorization'));
    self::assertSame('application/ld+json; version=2.3.0', $requests[2]->getHeaderLine('Content-Type'));
    $sent = json_decode((string) $requests[2]->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    self::assertIsArray($sent);
    self::assertSame('api:Notification', $sent['@type']);
    self::assertSame(['@id' => 'api:LOGISTICS_OBJECT_UPDATED'], $sent['api:hasEventType']);

    $row = $this->outbox()->find($id);
    self::assertNotNull($row);
    self::assertFalse($row->isPending());
    self::assertEquals($this->clock->now(), $row->deliveredAt);
    self::assertSame(1, $row->attempts);
    self::assertSame([], $this->outbox()->due($this->clock->now()->modify('+1 year')));
  }

  /**
   * Tests that failures back off and are eventually given up.
   */
  public function testRetriesAndGivesUp(): void {
    $id = $this->enqueue();
    $delivery = $this->container->get('one_record.delivery');
    self::assertInstanceOf(Delivery::class, $delivery);

    MockHttp::respond(new Response(200, ['Content-Type' => 'application/json'], json_encode(['access_token' => 't', 'token_type' => 'Bearer', 'expires_in' => 3600], JSON_THROW_ON_ERROR)));
    MockHttp::respond(new Response(200, ['Content-Type' => 'application/ld+json; version=2.3.0'], json_encode($this->partnerInformation()->toJsonLd(), JSON_THROW_ON_ERROR)));
    MockHttp::respond(new Response(503));
    self::assertSame(DeliveryOutcome::Retrying, $delivery->attempt($id));
    $row = $this->outbox()->find($id);
    self::assertNotNull($row);
    self::assertTrue($row->isPending());
    self::assertSame(1, $row->attempts);
    self::assertEquals($this->clock->now()->modify('+60 seconds'), $row->nextAttemptAt, 'First retry after a minute');
    self::assertStringContainsString('503', (string) $row->lastError);

    self::assertSame(DeliveryOutcome::Skipped, $delivery->attempt($id), 'Not due yet');
    self::assertSame([], $this->outbox()->due($this->clock->now()));

    $this->clock->advance('+61 seconds');
    self::assertSame([$id], $this->outbox()->due($this->clock->now()));
    $queue = $this->container->get('queue')->get(QueuedOutbox::QUEUE);
    while ($item = $queue->claimItem()) {
      $queue->deleteItem($item);
    }
    one_record_cron();
    self::assertSame(1, $queue->numberOfItems(), 'Cron queues due retries');

    // Token and server information are cached: only the notification goes out.
    MockHttp::respond(new Response(503));
    self::assertSame(DeliveryOutcome::GivenUp, $delivery->attempt($id), 'max_attempts is 2');
    $row = $this->outbox()->find($id);
    self::assertNotNull($row);
    self::assertFalse($row->isPending());
    self::assertNotNull($row->failedAt);
    self::assertCount(4, MockHttp::requests());
  }

  /**
   * Tests that a 4xx answer and an unknown partner end delivery at once.
   */
  public function testRejections(): void {
    $delivery = $this->container->get('one_record.delivery');
    self::assertInstanceOf(Delivery::class, $delivery);

    $id = $this->enqueue();
    MockHttp::respond(new Response(200, ['Content-Type' => 'application/json'], json_encode(['access_token' => 't', 'token_type' => 'Bearer', 'expires_in' => 3600], JSON_THROW_ON_ERROR)));
    MockHttp::respond(new Response(200, ['Content-Type' => 'application/ld+json; version=2.3.0'], json_encode($this->partnerInformation()->toJsonLd(), JSON_THROW_ON_ERROR)));
    MockHttp::respond(new Response(400, ['Content-Type' => 'application/ld+json'], '{"@context":{"api":"https://onerecord.iata.org/ns/api#"},"@type":"api:Error","api:hasTitle":"Bad notification"}'));
    self::assertSame(DeliveryOutcome::GivenUp, $delivery->attempt($id));
    $row = $this->outbox()->find($id);
    self::assertNotNull($row);
    self::assertNotNull($row->failedAt);
    self::assertStringContainsString('Bad notification', (string) $row->lastError);

    $stranger = $this->enqueue('https://stranger.example/logistics-objects/x');
    self::assertSame(DeliveryOutcome::GivenUp, $delivery->attempt($stranger));
    self::assertStringContainsString('partner registry', (string) $this->outbox()->find($stranger)?->lastError);
    self::assertCount(3, MockHttp::requests(), 'Nothing was sent to the stranger');
  }

  /**
   * Tests that transport failures and token-endpoint outages are retried.
   *
   * The SDK client wraps a PSR-18 failure in its ClientException and its
   * token provider reports the token endpoint's status in words; neither
   * is what DeliveryVerdict looks at, so the deliverer classifies the cause
   * (adversarial review AR-001).
   */
  public function testTransientFailuresBeforeAndAroundTheNotificationAreRetried(): void {
    $this->config(OneRecordConfig::NAME)->set('delivery.max_attempts', 10)->save();
    $delivery = $this->container->get('one_record.delivery');
    self::assertInstanceOf(Delivery::class, $delivery);

    // The notification itself fails on the wire. The token is short-lived,
    // so every later attempt goes back to the token endpoint.
    $id = $this->enqueue();
    MockHttp::respond(new Response(200, ['Content-Type' => 'application/json'], json_encode(['access_token' => 't', 'token_type' => 'Bearer', 'expires_in' => 60], JSON_THROW_ON_ERROR)));
    MockHttp::respond(new Response(200, ['Content-Type' => 'application/ld+json; version=2.3.0'], json_encode($this->partnerInformation()->toJsonLd(), JSON_THROW_ON_ERROR)));
    MockHttp::respond(new ConnectException('Connection refused', new PsrRequest('POST', self::PARTNER_ENDPOINT . '/notifications')));
    self::assertSame(DeliveryOutcome::Retrying, $delivery->attempt($id), 'A transport failure is transient');
    $row = $this->outbox()->find($id);
    self::assertNotNull($row);
    self::assertTrue($row->isPending());
    self::assertStringContainsString('Connection refused', (string) $row->lastError);

    // The token endpoint is down: nothing was sent, and nothing is final.
    $this->clock->advance('+2 minutes');
    MockHttp::respond(new Response(503));
    self::assertSame(DeliveryOutcome::Retrying, $delivery->attempt($id), 'A 503 from the token endpoint is transient');
    self::assertTrue($this->outbox()->find($id)?->isPending() ?? FALSE);

    $this->clock->advance('+10 minutes');
    MockHttp::respond(new ConnectException('Name resolution failed', new PsrRequest('POST', self::PARTNER_ENDPOINT . '/oauth/token')));
    self::assertSame(DeliveryOutcome::Retrying, $delivery->attempt($id), 'So is a token request that never reached the endpoint');
    self::assertTrue($this->outbox()->find($id)?->isPending() ?? FALSE);

    // Refused credentials are final.
    $this->clock->advance('+20 minutes');
    MockHttp::respond(new Response(401, ['Content-Type' => 'application/json'], '{"error":"invalid_client"}'));
    self::assertSame(DeliveryOutcome::GivenUp, $delivery->attempt($id), 'A 401 from the token endpoint is the partner\'s final word');
    $row = $this->outbox()->find($id);
    self::assertNotNull($row);
    self::assertNotNull($row->failedAt);
    self::assertStringContainsString('401', (string) $row->lastError);
    self::assertCount(6, MockHttp::requests(), 'Token, information, notification, then one token request per later attempt');
  }

  /**
   * Tests that a worker whose lease ran out cannot overwrite a later attempt.
   */
  public function testExpiredLeaseCannotRecordOverLaterAttempt(): void {
    $id = $this->enqueue();
    $outbox = $this->outbox();
    $first = $outbox->claim($id, $this->clock->now(), 300);
    self::assertNotNull($first);
    self::assertSame(1, $first->attempts);
    self::assertNull($outbox->claim($id, $this->clock->now(), 300), 'Leased');

    $this->clock->advance('+301 seconds');
    $second = $outbox->claim($id, $this->clock->now(), 300);
    self::assertNotNull($second, 'The lease ran out; another worker takes the row');
    self::assertSame(2, $second->attempts);

    self::assertFalse($outbox->markFailed($id, $first->attempts, $this->clock->now(), 'the first worker gave up late'));
    self::assertFalse($outbox->markDelivered($id, $first->attempts, $this->clock->now()));
    $row = $outbox->find($id);
    self::assertNotNull($row);
    self::assertTrue($row->isPending(), 'The stale worker changed nothing');
    self::assertNull($row->lastError);

    self::assertTrue($outbox->markRetry($id, $second->attempts, $this->clock->now()->modify('+60 seconds'), 'transient'));
    $row = $outbox->find($id);
    self::assertNotNull($row);
    self::assertTrue($row->isPending());
    self::assertSame('transient', $row->lastError);
    self::assertEquals($this->clock->now()->modify('+60 seconds'), $row->nextAttemptAt, 'The current attempt\'s outcome stands');
  }

  /**
   * Tests that a worker paused inside its claim does not share a number.
   *
   * A claim reads the row, then leases it with a compare-and-set on the
   * attempt number it read. A worker that paused in between finds the row
   * taken and gets nothing, instead of a later worker's attempt number
   * (adversarial review AR2-001).
   */
  public function testClaimPausedBeforeLeasingDoesNotTakeAnotherWorkersNumber(): void {
    $id = $this->enqueue();
    $outbox = $this->outbox();
    $seenByA = $outbox->find($id);
    self::assertNotNull($seenByA);
    self::assertSame(0, $seenByA->attempts);

    $this->clock->advance('+301 seconds');
    $b = $outbox->claim($id, $this->clock->now(), 300);
    self::assertNotNull($b, 'B claims while A is paused between reading and leasing');
    self::assertSame(1, $b->attempts);

    self::assertNull($outbox->lease($seenByA, $this->clock->now(), 300), 'A\'s lease fails: the row moved on since it read it, so A has no number to record under');
    $row = $outbox->find($id);
    self::assertNotNull($row);
    self::assertTrue($row->isPending());
    self::assertSame(1, $row->attempts);
    self::assertTrue($outbox->markDelivered($id, $b->attempts, $this->clock->now()), 'B\'s outcome stands');
  }

  /**
   * Enqueues a notification the way the server does.
   */
  private function enqueue(string $recipient = self::PARTNER): int {
    $outbox = $this->container->get('one_record.outbox');
    self::assertInstanceOf(QueuedOutbox::class, $outbox);
    $notification = new Notification(NotificationEventType::LogisticsObjectUpdated, $this->piece()->iri, Cargo::Piece, new Iri(self::HOLDER), [Cargo::grossWeight]);
    $outbox->enqueue(new OutboundNotification(new Iri($recipient), $notification, $this->clock->now(), 'n-' . bin2hex(random_bytes(4))));
    $due = $this->outbox()->due($this->clock->now());
    return end($due) ?: throw new \LogicException('Nothing enqueued');
  }

  /**
   * The outbox store, for reading rows back.
   */
  private function outbox(): DatabaseNotificationOutbox {
    $outbox = $this->container->get('one_record.store.outbox');
    self::assertInstanceOf(DatabaseNotificationOutbox::class, $outbox);
    return $outbox;
  }

  /**
   * What the partner's server says about itself.
   */
  private function partnerInformation(): ServerInformation {
    return new ServerInformation(new Iri(self::PARTNER_ENDPOINT), new Iri(self::PARTNER), [ApiVersion::V2_3_0->value, ApiVersion::V2_2_0->value]);
  }

}
