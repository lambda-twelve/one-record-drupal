<?php

declare(strict_types=1);

namespace Drupal\one_record\Cache;

use Psr\SimpleCache\InvalidArgumentException;

/**
 * A PSR-16 key the adapter refuses.
 */
final class InvalidKeyException extends \InvalidArgumentException implements InvalidArgumentException {
}
