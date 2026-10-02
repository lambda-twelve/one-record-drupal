<?php

declare(strict_types=1);

namespace Drupal\one_record\Notification;

/**
 * What one delivery attempt achieved.
 */
enum DeliveryOutcome {

  case Delivered;
  case Retrying;
  case GivenUp;
  case Skipped;

}
