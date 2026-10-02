<?php

declare(strict_types=1);

namespace Drupal\one_record\Server;

use Drupal\Core\Database\Connection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Runs each mutating request inside one database transaction.
 *
 * The SDK calls several stores per request and has no unit of work of its
 * own; it also turns unexpected exceptions into a 500 response rather than
 * letting them escape. So the transaction is committed on anything below
 * 500 (a 4xx may legitimately have stored a failed action request) and
 * rolled back on a 5xx or an escaping exception. Reads run without one.
 */
final class TransactionalHandler implements RequestHandlerInterface {

  public function __construct(
    private readonly RequestHandlerInterface $inner,
    private readonly Connection $connection,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function handle(ServerRequestInterface $request): ResponseInterface {
    if (in_array(strtoupper($request->getMethod()), ['GET', 'HEAD', 'OPTIONS'], TRUE)) {
      return $this->inner->handle($request);
    }
    $transaction = $this->connection->startTransaction();
    try {
      $response = $this->inner->handle($request);
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
    if ($response->getStatusCode() >= 500) {
      $transaction->rollBack();
    }
    unset($transaction);
    return $response;
  }

}
