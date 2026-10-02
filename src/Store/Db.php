<?php

declare(strict_types=1);

namespace Drupal\one_record\Store;

use LambdaTwelve\OneRecord\Rdf\Iri;

/**
 * Column conventions shared by the database stores.
 *
 * IRIs are compared byte for byte in the SDK (Iri::equals), so every lookup
 * goes through a SHA-256 hash column rather than a collated text index.
 * Timestamps are stored as UTC microseconds since the epoch: they compare and
 * sort as integers on every database Drupal supports and round-trip exactly.
 */
final class Db {

  /**
   * The hash column value of an IRI.
   */
  public static function hash(Iri|string $iri): string {
    return hash('sha256', $iri instanceof Iri ? $iri->value : $iri);
  }

  /**
   * A timestamp column value.
   */
  public static function micros(\DateTimeInterface $time): int {
    return (int) $time->format('Uu');
  }

  /**
   * The instant a timestamp column holds, in UTC.
   */
  public static function time(int|string $micros): \DateTimeImmutable {
    $micros = (int) $micros;
    $seconds = intdiv($micros, 1_000_000);
    $fraction = $micros - $seconds * 1_000_000;
    $time = \DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%06d', $seconds, $fraction), new \DateTimeZone('UTC'));
    if ($time === FALSE) {
      throw new \UnexpectedValueException(sprintf('Not a stored timestamp: %s', $micros));
    }
    return $time;
  }

  /**
   * A nullable timestamp column value.
   */
  public static function microsOrNull(?\DateTimeInterface $time): ?int {
    return $time === NULL ? NULL : self::micros($time);
  }

  /**
   * The instant a nullable timestamp column holds.
   */
  public static function timeOrNull(int|string|null $micros): ?\DateTimeImmutable {
    return $micros === NULL ? NULL : self::time($micros);
  }

}
