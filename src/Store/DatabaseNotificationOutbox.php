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
   * @return \Drupal\one_record\Notification\PendingNotification|null
   *   The row, or NULL when it is not due, already leased, or finished.
   */
  public function claim(int $id, \DateTimeImmutable $now, int $leaseSeconds = 300): ?PendingNotification {
    $claimed = $this->connection->update(self::TABLE)
      ->expression('attempts', 'attempts + 1')
      ->fields(['next_attempt_at' => Db::micros($now->modify(sprintf('+%d seconds', $leaseSeconds)))])
      ->condition('id', $id)
      ->isNull('delivered_at')
      ->isNull('failed_at')
      ->condition('next_attempt_at', Db::micros($now), '<=')
      ->execute();
    return $claimed === 1 ? $this->find($id) : NULL;
  }

  /**
   * Records that the partner confirmed the notification.
   */
  public function markDelivered(int $id, \DateTimeImmutable $now): void {
    $this->connection->update(self::TABLE)
      ->fields(['delivered_at' => Db::micros($now), 'last_error' => NULL])
      ->condition('id', $id)
      ->execute();
  }

  /**
   * Records a failed attempt and when to try again.
   */
  public function markRetry(int $id, \DateTimeImmutable $next, string $error): void {
    $this->connection->update(self::TABLE)
      ->fields(['next_attempt_at' => Db::micros($next), 'last_error' => mb_substr($error, 0, 2000)])
      ->condition('id', $id)
      ->execute();
  }

  /**
   * Gives up on a notification.
   */
  public function markFailed(int $id, \DateTimeImmutable $now, string $error): void {
    $this->connection->update(self::TABLE)
      ->fields(['failed_at' => Db::micros($now), 'last_error' => mb_substr($error, 0, 2000)])
      ->condition('id', $id)
      ->execute();
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
