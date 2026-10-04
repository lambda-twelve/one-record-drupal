<?php

declare(strict_types=1);

namespace Drupal\Tests\one_record\Kernel;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\one_record\Cache\SimpleCache;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The PSR-16 adapter expires items by the current time, not request time.
 */
#[Group('one_record')]
#[RunTestsInSeparateProcesses]
final class SimpleCacheTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['one_record'];

  /**
   * Tests expiry within one process.
   */
  public function testExpiryFollowsTheCurrentTime(): void {
    $time = new class() implements TimeInterface {

      /**
       * The current time, which a test moves.
       */
      public int $now = 1_800_000_000;

      /**
       * {@inheritdoc}
       */
      public function getRequestTime(): int {
        return 1_800_000_000;
      }

      /**
       * {@inheritdoc}
       */
      public function getRequestMicroTime(): float {
        return 1_800_000_000.0;
      }

      /**
       * {@inheritdoc}
       */
      public function getCurrentTime(): int {
        return $this->now;
      }

      /**
       * {@inheritdoc}
       */
      public function getCurrentMicroTime(): float {
        return (float) $this->now;
      }

    };
    $backend = $this->container->get('cache.one_record');
    self::assertInstanceOf(CacheBackendInterface::class, $backend);
    $cache = new SimpleCache($backend, $time);

    $cache->set('forever', 'kept');
    $cache->set('short', 'fresh', 10);
    $cache->set('interval', 'fresh', new \DateInterval('PT10S'));
    $cache->set('zero', 'never', 0);
    $cache->set('negative', 'never', -5);
    $cache->set('elapsed', 'never', \DateInterval::createFromDateString('-1 second'));
    self::assertSame('kept', $cache->get('forever'));
    self::assertSame('fresh', $cache->get('short'));
    self::assertTrue($cache->has('interval'));
    self::assertSame('missing', $cache->get('zero', 'missing'), 'A zero TTL is not cached');
    self::assertFalse($cache->has('negative'));
    self::assertFalse($cache->has('elapsed'));

    $time->now += 9;
    self::assertSame('fresh', $cache->get('short'), 'Still within the TTL');

    $time->now += 1;
    self::assertSame('missing', $cache->get('short', 'missing'), 'Gone at the TTL, although the request time has not moved');
    self::assertFalse($cache->has('interval'));
    self::assertSame('kept', $cache->get('forever'));
    self::assertFalse($backend->get('short'), 'And removed from the bin');

    $cache->set('short', 'again', 10);
    $cache->set('zero', 'overwritten', 0);
    self::assertSame('again', $cache->get('short'));
    self::assertFalse($cache->has('zero'), 'A zero TTL also removes what was there');
  }

}
