<?php

declare(strict_types=1);

namespace Drupal\Tests\one_record\Kernel\Store;

use Drupal\one_record\Store\DatabaseAccessDelegationStore;
use Drupal\one_record\Store\DatabaseActionRequestStore;
use Drupal\one_record\Store\DatabaseLogisticsEventStore;
use Drupal\one_record\Store\DatabaseLogisticsObjectStore;
use Drupal\one_record\Store\DatabaseNotificationOutbox;
use Drupal\one_record\Store\DatabaseSubscriptionStore;
use Drupal\Tests\one_record\Support\FixedClock;
use Drupal\Tests\one_record\Support\Stores;
use LambdaTwelve\OneRecord\Server\Spi\OutboundNotification;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The database stores pass the same contract as the in-memory reference.
 */
#[Group('one_record')]
#[RunTestsInSeparateProcesses]
final class DatabaseStoresTest extends StoreContractTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['one_record'];

  /**
   * The outbox under test, for reading back what was enqueued.
   */
  private DatabaseNotificationOutbox $outbox;

  /**
   * {@inheritdoc}
   */
  protected function makeStores(FixedClock $clock): Stores {
    $this->installSchema('one_record', [
      'one_record_logistics_objects',
      'one_record_logistics_object_revisions',
      'one_record_logistics_events',
      'one_record_action_requests',
      'one_record_action_request_objects',
      'one_record_subscription_offers',
      'one_record_grants',
      'one_record_outbox',
    ]);
    $connection = $this->container->get('database');
    $this->outbox = new DatabaseNotificationOutbox($connection);
    return new Stores(
      new DatabaseLogisticsObjectStore($connection),
      new DatabaseLogisticsEventStore($connection),
      new DatabaseActionRequestStore($connection, $clock),
      new DatabaseSubscriptionStore($connection, $clock),
      new DatabaseAccessDelegationStore($connection, $clock),
      $this->outbox,
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function outboxContents(): array {
    $out = [];
    foreach ($this->outbox->due(new \DateTimeImmutable('2100-01-01'), 100) as $id) {
      $pending = $this->outbox->find($id);
      self::assertNotNull($pending);
      $out[] = new OutboundNotification($pending->recipient, $pending->notification, $pending->createdAt, (string) $pending->id);
    }
    return $out;
  }

}
