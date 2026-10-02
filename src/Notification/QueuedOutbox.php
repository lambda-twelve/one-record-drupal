<?php

declare(strict_types=1);

namespace Drupal\one_record\Notification;

use Drupal\Core\Database\Connection;
use Drupal\Core\Queue\QueueFactory;
use Drupal\one_record\Store\DatabaseNotificationOutbox;
use LambdaTwelve\OneRecord\Server\Spi\NotificationOutbox;
use LambdaTwelve\OneRecord\Server\Spi\OutboundNotification;

/**
 * The outbox the server writes to: a database row, then a queue item.
 *
 * The row is the record; the queue item only asks a worker to look at it
 * soon. It is created after the surrounding transaction commits, so a
 * worker never sees a notification that is about to be rolled back, and
 * cron's sweep of due rows covers a queue item that gets lost.
 */
final class QueuedOutbox implements NotificationOutbox {

  public const QUEUE = 'one_record_outbox';

  public function __construct(
    private readonly DatabaseNotificationOutbox $outbox,
    private readonly Connection $connection,
    private readonly QueueFactory $queues,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function enqueue(OutboundNotification $notification): void {
    $id = $this->outbox->insert($notification);
    $schedule = function (bool $success = TRUE) use ($id): void {
      if ($success) {
        $this->queues->get(self::QUEUE)->createItem($id);
      }
    };
    if ($this->connection->inTransaction()) {
      $this->connection->transactionManager()->addPostTransactionCallback($schedule);
    }
    else {
      $schedule();
    }
  }

}
