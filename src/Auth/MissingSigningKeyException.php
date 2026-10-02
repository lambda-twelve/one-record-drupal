<?php

declare(strict_types=1);

namespace Drupal\one_record\Auth;

/**
 * Thrown when the token endpoint is enabled without a private key.
 */
final class MissingSigningKeyException extends \RuntimeException {

  public function __construct() {
    parent::__construct("No signing key: set \$settings['one_record.signing_key'] (a PEM string) or \$settings['one_record.signing_key_file'] (a path) in settings.php.");
  }

}
