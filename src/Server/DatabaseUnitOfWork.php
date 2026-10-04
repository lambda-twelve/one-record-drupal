<?php

declare(strict_types=1);

namespace Drupal\one_record\Server;

use Drupal\Core\Database\Connection;
use LambdaTwelve\OneRecord\Server\Spi\UnitOfWork;

/**
 * The SDK's unit of work on a Drupal database transaction.
 *
 * The server runs every mutating request through run(), and DataHolder and
 * ActionRequests run each of their operations through it, so everything an
 * operation writes stands or falls together: when the work throws, the
 * transaction is rolled back before the SDK turns the exception into a
 * response. That covers the competing decision, where an acceptance has
 * written grants before its compare-and-set status update fails: the SDK
 * answers 409 and the grants are gone, where a transaction committed on the
 * status code would have kept them.
 *
 * Calls nest (an operation inside a request, create and accept inside
 * DataHolder::change()); Drupal's transactions nest as savepoints, so an
 * inner failure unwinds to its savepoint and the outer unit goes on.
 */
final class DatabaseUnitOfWork implements UnitOfWork {

  public function __construct(
    private readonly Connection $connection,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function run(callable $work): mixed {
    $transaction = $this->connection->startTransaction();
    try {
      $result = $work();
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
    // Letting the transaction object go commits, or releases the savepoint.
    unset($transaction);
    return $result;
  }

}
