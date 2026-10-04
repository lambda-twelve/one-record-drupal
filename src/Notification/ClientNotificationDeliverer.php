<?php

declare(strict_types=1);

namespace Drupal\one_record\Notification;

use Drupal\one_record\Client\ClientFactory;
use Drupal\one_record\Client\UnknownPartnerException;
use LambdaTwelve\OneRecord\Client\DeliveryVerdict;
use LambdaTwelve\OneRecord\Client\OneRecordHttpException;
use LambdaTwelve\OneRecord\Client\TokenEndpointException;

/**
 * Delivers notifications with the SDK client and the partner's credentials.
 *
 * The recipient's endpoint comes from the partner registry or, failing
 * that, from the agent IRI as the SDK suggests. Whether a failure is worth
 * a retry is the SDK's classification (DeliveryVerdict): transport trouble
 * and 5xx, 408 and 429 answers, from the partner or its token endpoint, are
 * retried; anything else is final.
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
      // The SDK's id travels as the Idempotency-Key, so a retried delivery
      // is recognised (spec question 28).
      $client->sendNotification($notification->notification, idempotencyKey: $notification->notificationId);
    }
    catch (\Throwable $e) {
      $status = self::status($e);
      throw DeliveryVerdict::of($e) === DeliveryVerdict::Retry
        ? new DeliveryFailed($e->getMessage(), $status, $e)
        : new DeliveryRejected($e->getMessage(), $status, $e);
    }
  }

  /**
   * The HTTP status behind a failure, 0 when there was no answer.
   */
  private static function status(\Throwable $failure): int {
    for ($cause = $failure; $cause !== NULL; $cause = $cause->getPrevious()) {
      if ($cause instanceof OneRecordHttpException || $cause instanceof TokenEndpointException) {
        return $cause->status;
      }
    }
    return 0;
  }

}
