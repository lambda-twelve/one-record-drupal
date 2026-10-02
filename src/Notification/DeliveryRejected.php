<?php

declare(strict_types=1);

namespace Drupal\one_record\Notification;

/**
 * A delivery the partner will never accept: a 4xx other than 408 or 429.
 */
final class DeliveryRejected extends \RuntimeException {
}
