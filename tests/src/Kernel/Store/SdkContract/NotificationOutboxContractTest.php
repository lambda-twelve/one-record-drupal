<?php

declare(strict_types=1);

namespace Drupal\Tests\one_record\Kernel\Store\SdkContract;

use Drupal\one_record\Store\DatabaseNotificationOutbox;
use LambdaTwelve\OneRecord\Server\Spi\NotificationOutbox;
use LambdaTwelve\OneRecord\Server\Spi\OutboundNotification;
use LambdaTwelve\OneRecord\Testing\Contract\NotificationOutboxContractTests;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The database outbox passes the SDK's NotificationOutbox contract.
 */
#[Group('one_record')]
#[RunTestsInSeparateProcesses]
final class NotificationOutboxContractTest extends SdkContractTestBase {

  use NotificationOutboxContractTests;

  /**
   * {@inheritdoc}
   */
  protected function createOutbox(): NotificationOutbox {
    return new DatabaseNotificationOutbox($this->connection());
  }

  /**
   * {@inheritdoc}
   */
  protected function pending(NotificationOutbox $outbox): array {
    self::assertInstanceOf(DatabaseNotificationOutbox::class, $outbox);
    $out = [];
    foreach ($outbox->due(new \DateTimeImmutable('2100-01-01'), 100) as $id) {
      $row = $outbox->find($id);
      self::assertNotNull($row);
      $out[] = new OutboundNotification($row->recipient, $row->notification, $row->createdAt, $row->notificationId);
    }
    return $out;
  }

}
