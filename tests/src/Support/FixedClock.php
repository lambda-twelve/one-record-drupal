<?php

declare(strict_types=1);

namespace Drupal\Tests\one_record\Support;

use Psr\Clock\ClockInterface;

/**
 * A clock that moves only when a test says so.
 */
final class FixedClock implements ClockInterface {

  /**
   * The current instant.
   */
  private \DateTimeImmutable $now;

  public function __construct(string|\DateTimeImmutable $now = '2026-10-02T12:00:00.000Z') {
    $this->now = $now instanceof \DateTimeImmutable ? $now : new \DateTimeImmutable($now);
  }

  /**
   * {@inheritdoc}
   */
  public function now(): \DateTimeImmutable {
    return $this->now;
  }

  /**
   * Moves the clock by a relative interval such as "+1 hour".
   */
  public function advance(string $interval): void {
    $this->now = $this->now->modify($interval);
  }

  /**
   * Sets the clock to an instant.
   */
  public function set(string $now): void {
    $this->now = new \DateTimeImmutable($now);
  }

}
