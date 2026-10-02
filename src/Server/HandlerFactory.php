<?php

declare(strict_types=1);

namespace Drupal\one_record\Server;

use Drupal\Core\Database\Connection;
use Drupal\one_record\Config\OneRecordConfig;
use LambdaTwelve\OneRecord\Server\ServerBuilder;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Builds the request handler the controller mounts.
 *
 * A configured site gets the SDK server wrapped in a database transaction;
 * an unconfigured one gets a handler that says so, instead of a container
 * error when the first request arrives.
 */
final class HandlerFactory {

  public function __construct(
    private readonly OneRecordConfig $config,
    private readonly ServicesFactory $services,
    private readonly Connection $connection,
    private readonly ResponseFactoryInterface $responses,
    private readonly StreamFactoryInterface $streams,
  ) {}

  /**
   * The handler for the current configuration.
   */
  public function create(): RequestHandlerInterface {
    if (!$this->config->isConfigured()) {
      return new UnconfiguredHandler($this->responses, $this->streams);
    }
    return new TransactionalHandler(ServerBuilder::build($this->services->create()), $this->connection);
  }

}
