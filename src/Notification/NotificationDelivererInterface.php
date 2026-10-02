<?php

declare(strict_types=1);

namespace Drupal\one_record\Notification;

/**
 * Sends one outbox notification to its recipient.
 *
 * The SDK stops at the outbox on purpose: how a host reaches a partner, with
 * which credentials and through which egress, is the host's business. The
 * default implementation uses the SDK client; a site with its own transport
 * replaces the one_record.deliverer service.
 */
interface NotificationDelivererInterface {

  /**
   * Delivers the notification or throws.
   *
   * @throws \Drupal\one_record\Notification\DeliveryFailed
   *   When the attempt should be retried later.
   * @throws \Drupal\one_record\Notification\DeliveryRejected
   *   When the notification should be given up.
   */
  public function deliver(PendingNotification $notification): void;

}
