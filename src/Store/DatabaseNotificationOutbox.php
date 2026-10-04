<?php

declare(strict_types=1);

namespace Drupal\one_record\Store;

use Drupal\Core\Database\Connection;
use Drupal\one_record\Notification\PendingNotification;
use LambdaTwelve\OneRecord\Api\Notification;
use LambdaTwelve\OneRecord\JsonLd\Json;
use LambdaTwelve\OneRecord\Rdf\Iri;
use LambdaTwelve\OneRecord\Server\Spi\NotificationOutbox;
use LambdaTwelve\OneRecord\Server\Spi\OutboundNotification;

/**
 * The notification outbox in Drupal's database.
 *
 * The SDK enqueues; this store keeps every notification until a partner has
 * confirmed it, and offers the delivery worker what it needs: due rows, a
 * lease while an attempt runs, and the outcome of each attempt. Retries are
 * driven by the next_attempt_at column, so a lost queue item cannot lose a
 * notification.
 *
 * A claim is an attempt number, and every outcome names the attempt it
 * belongs to: a worker whose lease ran out, and whose row another worker
 * has since claimed, can no longer record anything over the newer attempt.
 * Delivery is therefore at least once; the Idempotency-Key lets the partner
 * drop the repeat.
 */
final class DatabaseNotificationOutbox implements NotificationOutbox {

  private const TABLE = 'one_record_outbox';

  public function __construct(
    private readonly Connection $connection,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function enqueue(OutboundNotification $notification): void {
    $this->insert($notification);
  }

  /**
   * Stores a notification and returns the row id.
   */
  public function insert(OutboundNotification $notification): int {
    $created = Db::micros($notification->createdAt);
    $id = $this->connection->insert(self::TABLE)
      ->fields([
        'notification_id' => $notification->id,
        'recipient' => $notification->recipient->value,
        'recipient_hash' => Db::hash($notification->recipient),
        'endpoint' => $notification->suggestedEndpoint(),
        'event_type' => $notification->notification->eventType->name,
        'logistics_object' => $notification->notification->logisticsObject?->value,
        'document' => Json::encode($notification->notification->toJsonLd(), FALSE),
        'created_at' => $created,
        'attempts' => 0,
        'next_attempt_at' => $created,
      ])
      ->execute();
    return (int) $id;
  }

  /**
   * One row by id.
   */
  public function find(int $id): ?PendingNotification {
    $row = $this->connection->select(self::TABLE, 'o')
      ->fields('o')
      ->condition('id', $id)
      ->execute()
      ?->fetchAssoc();
    return is_array($row) ? $this->hydrate($row) : NULL;
  }

  /**
   * Ids of the undelivered rows whose next attempt is due.
   *
   * @return list<int>
   *   Oldest due first.
   */
  public function due(\DateTimeImmutable $now, int $limit = 50): array {
    $ids = $this->connection->select(self::TABLE, 'o')
      ->fields('o', ['id'])
      ->isNull('delivered_at')
      ->isNull('failed_at')
      ->condition('next_attempt_at', Db::micros($now), '<=')
      ->orderBy('next_attempt_at')
      ->orderBy('id')
      ->range(0, $limit)
      ->execute()
      ?->fetchCol() ?? [];
    return array_values(array_map(intval(...), $ids));
  }

  /**
   * Takes a lease on a due row so only one worker attempts it.
   *
   * The attempt number of the returned row is the worker's claim: the
   * outcome it records must carry it, and is ignored once a later attempt
   * has claimed the row.
   *
   * @return \Drupal\one_record\Notification\PendingNotification|null
   *   The row, or NULL when it is not due, already leased, or finished.
   */
  public function claim(int $id, \DateTimeImmutable $now, int $leaseSeconds = 300): ?PendingNotification {
    $seen = $this->find($id);
    return $seen === NULL ? NULL : $this->lease($seen, $now, $leaseSeconds);
  }

  /**
   * Leases a row as it was read, or nothing if it has moved on since.
   *
   * The update is a compare-and-set on the attempt number the caller read,
   * so the number it is given back is its own by construction: no second
   * read that could observe a later worker's claim. A worker that paused
   * between reading and leasing finds the row taken and gets NULL.
   *
   * @return \Drupal\one_record\Notification\PendingNotification|null
   *   The leased row with its new attempt number, or NULL.
   */
  public function lease(PendingNotification $seen, \DateTimeImmutable $now, int $leaseSeconds = 300): ?PendingNotification {
    $until = $now->modify(sprintf('+%d seconds', $leaseSeconds));
    $claimed = $this->connection->update(self::TABLE)
      ->fields(['attempts' => $seen->attempts + 1, 'next_attempt_at' => Db::micros($until)])
      ->condition('id', $seen->id)
      ->condition('attempts', $seen->attempts)
      ->isNull('delivered_at')
      ->isNull('failed_at')
      ->condition('next_attempt_at', Db::micros($now), '<=')
      ->execute();
    if ($claimed !== 1) {
      return NULL;
    }
    return new PendingNotification(
      $seen->id,
      $seen->notificationId,
      $seen->recipient,
      $seen->endpoint,
      $seen->notification,
      $seen->createdAt,
      $seen->attempts + 1,
      $until,
      NULL,
      NULL,
      $seen->lastError,
    );
  }

  /**
   * Records that the partner confirmed the notification.
   *
   * @return bool
   *   Whether the attempt still owned the row.
   */
  public function markDelivered(int $id, int $attempt, \DateTimeImmutable $now): bool {
    return $this->outcome($id, $attempt, ['delivered_at' => Db::micros($now), 'last_error' => NULL]);
  }

  /**
   * Records a failed attempt and when to try again.
   *
   * @return bool
   *   Whether the attempt still owned the row.
   */
  public function markRetry(int $id, int $attempt, \DateTimeImmutable $next, string $error): bool {
    return $this->outcome($id, $attempt, [
      'next_attempt_at' => Db::micros($next),
      'last_error' => mb_substr($error, 0, 2000),
    ]);
  }

  /**
   * Gives up on a notification.
   *
   * @return bool
   *   Whether the attempt still owned the row.
   */
  public function markFailed(int $id, int $attempt, \DateTimeImmutable $now, string $error): bool {
    return $this->outcome($id, $attempt, ['failed_at' => Db::micros($now), 'last_error' => mb_substr($error, 0, 2000)]);
  }

  /**
   * Writes an attempt's outcome, unless a later attempt owns the row by now.
   *
   * @param int $id
   *   The row.
   * @param int $attempt
   *   The attempt number the worker was given when it claimed the row.
   * @param array<string, mixed> $fields
   *   The columns to set.
   *
   * @return bool
   *   Whether the row was still this attempt's to update.
   */
  private function outcome(int $id, int $attempt, array $fields): bool {
    $updated = $this->connection->update(self::TABLE)
      ->fields($fields)
      ->condition('id', $id)
      ->condition('attempts', $attempt)
      ->isNull('delivered_at')
      ->isNull('failed_at')
      ->execute();
    return $updated === 1;
  }

  /**
   * Removes delivered and failed rows older than the given instant.
   *
   * @return int
   *   How many rows were removed.
   */
  public function prune(\DateTimeImmutable $before): int {
    $delete = $this->connection->delete(self::TABLE)
      ->condition('created_at', Db::micros($before), '<');
    $delete->condition($delete->orConditionGroup()
      ->isNotNull('delivered_at')
      ->isNotNull('failed_at'));
    return $delete->execute();
  }

  /**
   * How many rows are pending, delivered and failed.
   *
   * @return array{pending: int, delivered: int, failed: int}
   *   The counts.
   */
  public function counts(): array {
    $pending = $this->connection->select(self::TABLE)->isNull('delivered_at')->isNull('failed_at')->countQuery()->execute()?->fetchField();
    $delivered = $this->connection->select(self::TABLE)->isNotNull('delivered_at')->countQuery()->execute()?->fetchField();
    $failed = $this->connection->select(self::TABLE)->isNotNull('failed_at')->countQuery()->execute()?->fetchField();
    return ['pending' => (int) $pending, 'delivered' => (int) $delivered, 'failed' => (int) $failed];
  }

  /**
   * Rebuilds a row.
   *
   * @param array<string, mixed> $row
   *   The row.
   */
  private function hydrate(array $row): PendingNotification {
    return new PendingNotification(
      (int) $row['id'],
      (string) $row['notification_id'],
      new Iri((string) $row['recipient']),
      $row['endpoint'] === NULL ? NULL : (string) $row['endpoint'],
      Notification::fromJsonLd((string) $row['document']),
      Db::time($row['created_at']),
      (int) $row['attempts'],
      Db::time($row['next_attempt_at']),
      Db::timeOrNull($row['delivered_at']),
      Db::timeOrNull($row['failed_at']),
      $row['last_error'] === NULL ? NULL : (string) $row['last_error'],
    );
  }

}
