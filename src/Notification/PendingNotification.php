<?php

declare(strict_types=1);

namespace Drupal\one_record\Notification;

use LambdaTwelve\OneRecord\Api\Notification;
use LambdaTwelve\OneRecord\Rdf\Iri;

/**
 * An outbox row: a notification the server queued and its delivery state.
 */
final class PendingNotification {

  public function __construct(
    public readonly int $id,
    public readonly Iri $recipient,
    public readonly ?string $endpoint,
    public readonly Notification $notification,
    public readonly \DateTimeImmutable $createdAt,
    public readonly int $attempts,
    public readonly \DateTimeImmutable $nextAttemptAt,
    public readonly ?\DateTimeImmutable $deliveredAt,
    public readonly ?\DateTimeImmutable $failedAt,
    public readonly ?string $lastError,
  ) {}

  /**
   * Whether delivery is still owed.
   */
  public function isPending(): bool {
    return $this->deliveredAt === NULL && $this->failedAt === NULL;
  }

}
