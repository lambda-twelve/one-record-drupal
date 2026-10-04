<?php

declare(strict_types=1);

namespace Drupal\one_record\Cache;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * A PSR-16 cache over a Drupal cache bin.
 *
 * The SDK caches JWKS documents, partner tokens and server information
 * through PSR-16; this adapter puts them in the one_record cache bin.
 * Expiry is computed from Drupal's time service, the clock the cache
 * backends themselves live by, not from the SDK's clock. It is also
 * enforced here, against the current time: Drupal's backends compare an
 * item's expiry with the request start time, which stands still for the
 * length of a queue run or a Drush command, and PSR-16 promises an item is
 * gone once its TTL has passed.
 */
final class SimpleCache implements CacheInterface {

  public function __construct(
    private readonly CacheBackendInterface $backend,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function get(string $key, mixed $default = NULL): mixed {
    $item = $this->live(self::key($key));
    return $item === NULL ? $default : $item->data;
  }

  /**
   * {@inheritdoc}
   */
  public function set(string $key, mixed $value, null|int|\DateInterval $ttl = NULL): bool {
    $expire = $this->expire($ttl);
    if ($expire !== CacheBackendInterface::CACHE_PERMANENT && $expire <= $this->time->getCurrentTime()) {
      // A TTL of zero or less means "not cached", not "cached until now".
      $this->backend->delete(self::key($key));
      return TRUE;
    }
    $this->backend->set(self::key($key), $value, $expire);
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function delete(string $key): bool {
    $this->backend->delete(self::key($key));
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function clear(): bool {
    $this->backend->deleteAll();
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getMultiple(iterable $keys, mixed $default = NULL): iterable {
    $out = [];
    foreach ($keys as $key) {
      $out[$key] = $this->get($key, $default);
    }
    return $out;
  }

  /**
   * {@inheritdoc}
   *
   * @param iterable<string, mixed> $values
   *   Values keyed by cache key.
   * @param null|int|\DateInterval $ttl
   *   The time to live, or NULL to keep the items until cleared.
   */
  public function setMultiple(iterable $values, null|int|\DateInterval $ttl = NULL): bool {
    foreach ($values as $key => $value) {
      $this->set((string) $key, $value, $ttl);
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function deleteMultiple(iterable $keys): bool {
    foreach ($keys as $key) {
      $this->delete($key);
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function has(string $key): bool {
    return $this->live(self::key($key)) !== NULL;
  }

  /**
   * The stored item, unless it has expired by the current time.
   */
  private function live(string $key): ?\stdClass {
    $item = $this->backend->get($key);
    if (!$item instanceof \stdClass) {
      return NULL;
    }
    $expire = (int) $item->expire;
    if ($expire !== CacheBackendInterface::CACHE_PERMANENT && $expire <= $this->time->getCurrentTime()) {
      $this->backend->delete($key);
      return NULL;
    }
    return $item;
  }

  /**
   * Validates a key as PSR-16 requires.
   */
  private static function key(string $key): string {
    if ($key === '' || preg_match('#[{}()/\\\\@:]#', $key) === 1) {
      throw new InvalidKeyException(sprintf('"%s" is not a valid cache key.', $key));
    }
    return $key;
  }

  /**
   * The expiry timestamp of an item.
   */
  private function expire(null|int|\DateInterval $ttl): int {
    if ($ttl === NULL) {
      return CacheBackendInterface::CACHE_PERMANENT;
    }
    $now = (new \DateTimeImmutable())->setTimestamp($this->time->getCurrentTime());
    $expires = $ttl instanceof \DateInterval ? $now->add($ttl) : $now->modify(sprintf('%+d seconds', $ttl));
    return $expires->getTimestamp();
  }

}
