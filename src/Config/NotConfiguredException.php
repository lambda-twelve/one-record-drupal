<?php

declare(strict_types=1);

namespace Drupal\one_record\Config;

/**
 * Thrown when the server is used before its base URL and data holder are set.
 */
final class NotConfiguredException extends \RuntimeException {

  public function __construct() {
    parent::__construct('ONE Record is not configured: set base_url and data_holder in one_record.settings.');
  }

}
