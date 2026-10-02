<?php

declare(strict_types=1);

namespace Drupal\one_record\Clock;

use Drupal\Component\Datetime\TimeInterface;
use Psr\Clock\ClockInterface;

/**
 * The SDK's PSR-20 clock, read from Drupal's time service.
 *
 * Going through datetime.time keeps the SDK on Drupal's notion of "now", so
 * tests that swap that service move the ONE Record server with it. The instant
 * is truncated to whole milliseconds: the SDK writes xsd:dateTime literals
 * with millisecond precision, and a clock that never produces finer values
 * makes every stored document round-trip exactly.
 */
final class TimeClock implements ClockInterface {

  public function __construct(
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function now(): \DateTimeImmutable {
    $micro = $this->time->getCurrentMicroTime();
    $seconds = (int) floor($micro);
    $millis = (int) floor(($micro - $seconds) * 1000);
    $now = \DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%03d000', $seconds, $millis), new \DateTimeZone('UTC'));
    if ($now === FALSE) {
      throw new \UnexpectedValueException('The time service returned an unusable value.');
    }
    return $now;
  }

}
