<?php

declare(strict_types=1);

namespace Drupal\one_record\Notification;

use Drupal\one_record\Config\OneRecordConfig;
use Drupal\one_record\Store\DatabaseNotificationOutbox;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs delivery attempts against the outbox.
 *
 * Each attempt claims the row (so concurrent workers do not both send),
 * delivers, and records the outcome under its attempt number, so a worker
 * that outlived its lease cannot overwrite a later attempt's state. Retries
 * back off from a minute to a day and give up after the configured number
 * of attempts; the row keeps the last error either way.
 */
final class Delivery {

  /**
   * Seconds until the next attempt, by number of attempts so far.
   */
  private const BACKOFF = [60, 300, 900, 3600, 14400, 43200, 86400];

  public function __construct(
    private readonly DatabaseNotificationOutbox $outbox,
    private readonly NotificationDelivererInterface $deliverer,
    private readonly ClockInterface $clock,
    private readonly OneRecordConfig $config,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Attempts one notification.
   */
  public function attempt(int $id): DeliveryOutcome {
    $now = $this->clock->now();
    $pending = $this->outbox->claim($id, $now, $this->config->deliveryLeaseSeconds());
    if ($pending === NULL) {
      return DeliveryOutcome::Skipped;
    }
    try {
      $this->deliverer->deliver($pending);
    }
    catch (DeliveryRejected $e) {
      $this->outbox->markFailed($id, $pending->attempts, $now, $e->getMessage());
      $this->logger->warning('Notification @id to @recipient was rejected: @error', $this->context($pending, $e));
      return DeliveryOutcome::GivenUp;
    }
    catch (\Throwable $e) {
      if ($pending->attempts >= $this->config->deliveryMaxAttempts()) {
        $this->outbox->markFailed($id, $pending->attempts, $now, $e->getMessage());
        $this->logger->error('Notification @id to @recipient given up after @attempts attempts: @error', $this->context($pending, $e));
        return DeliveryOutcome::GivenUp;
      }
      $delay = self::BACKOFF[min($pending->attempts, count(self::BACKOFF)) - 1];
      $this->outbox->markRetry($id, $pending->attempts, $now->modify(sprintf('+%d seconds', $delay)), $e->getMessage());
      $this->logger->notice('Notification @id to @recipient failed (attempt @attempts), retrying in @delay s: @error', $this->context($pending, $e) + ['@delay' => $delay]);
      return DeliveryOutcome::Retrying;
    }
    if (!$this->outbox->markDelivered($id, $pending->attempts, $now)) {
      $this->logger->warning('Notification @id to @recipient was delivered after its lease ran out; a later attempt owns the row now', $this->context($pending));
      return DeliveryOutcome::Delivered;
    }
    $this->logger->info('Notification @id delivered to @recipient', $this->context($pending));
    return DeliveryOutcome::Delivered;
  }

  /**
   * Attempts every due notification.
   *
   * @return array<string, int>
   *   Outcome counts keyed by DeliveryOutcome case name.
   */
  public function deliverDue(int $limit = 50): array {
    $counts = array_fill_keys(array_map(static fn(DeliveryOutcome $o): string => $o->name, DeliveryOutcome::cases()), 0);
    foreach ($this->outbox->due($this->clock->now(), $limit) as $id) {
      $counts[$this->attempt($id)->name]++;
    }
    return $counts;
  }

  /**
   * Log context for a notification.
   *
   * @return array<string, mixed>
   *   The placeholders.
   */
  private function context(PendingNotification $pending, ?\Throwable $e = NULL): array {
    return [
      '@id' => $pending->id,
      '@recipient' => $pending->recipient->value,
      '@attempts' => $pending->attempts,
      '@error' => $e?->getMessage() ?? '',
      'exception' => $e,
    ];
  }

}
