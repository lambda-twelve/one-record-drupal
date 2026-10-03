<?php

declare(strict_types=1);

namespace Drupal\one_record\Notification;

use Drupal\one_record\Client\ClientFactory;
use Drupal\one_record\Client\UnknownPartnerException;
use LambdaTwelve\OneRecord\Client\ClientException;
use LambdaTwelve\OneRecord\Client\OneRecordHttpException;

/**
 * Delivers notifications with the SDK client and the partner's credentials.
 *
 * The recipient's endpoint comes from the partner registry or, failing
 * that, from the agent IRI as the SDK suggests. Transport trouble and 5xx,
 * 408 and 429 answers are retried; any other 4xx is final.
 */
final class ClientNotificationDeliverer implements NotificationDelivererInterface {

  public function __construct(
    private readonly ClientFactory $clients,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function deliver(PendingNotification $notification): void {
    $suggested = $notification->endpoint === NULL ? NULL : substr($notification->endpoint, 0, -strlen('/notifications'));
    try {
      $client = $this->clients->forPartner($notification->recipient, $suggested ?: NULL);
    }
    catch (UnknownPartnerException $e) {
      throw new DeliveryRejected($e->getMessage(), 0, $e);
    }
    try {
      // The SDK's id travels as the Idempotency-Key, so a retried delivery is recognised (spec question 28).
      $client->sendNotification($notification->notification, idempotencyKey: $notification->notificationId);
    }
    catch (OneRecordHttpException $e) {
      if ($e->status >= 500 || $e->status === 408 || $e->status === 429) {
        throw new DeliveryFailed($e->getMessage(), $e->status, $e);
      }
      throw new DeliveryRejected($e->getMessage(), $e->status, $e);
    }
    catch (ClientException $e) {
      throw new DeliveryFailed($e->getMessage(), 0, $e);
    }
  }

}
