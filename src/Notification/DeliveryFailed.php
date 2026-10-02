<?php

declare(strict_types=1);

namespace Drupal\one_record\Notification;

/**
 * A delivery attempt that may succeed later: a timeout, a 5xx, a 429.
 */
final class DeliveryFailed extends \RuntimeException {
}
