<?php

declare(strict_types=1);

namespace Drupal\one_record\Server;

use Drupal\one_record\Config\OneRecordConfig;
use LambdaTwelve\OneRecord\Server\ServerBuilder;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Builds the request handler the controller mounts.
 *
 * A site whose settings the SDK accepts gets the SDK server; one with
 * settings missing, or stored settings the SDK rejects (a configuration
 * import can store what the form would refuse), gets a handler that answers
 * 503, instead of a container error when the first request arrives. The
 * decision is the SDK's ServerConfig::problems(), the same one the status
 * report shows. Transactions are the SDK's business through the unit of
 * work it is given, so nothing wraps the server here.
 */
final class HandlerFactory {

  public function __construct(
    private readonly OneRecordConfig $config,
    private readonly ServicesFactory $services,
    private readonly ResponseFactoryInterface $responses,
    private readonly StreamFactoryInterface $streams,
  ) {}

  /**
   * The handler for the current configuration.
   */
  public function create(): RequestHandlerInterface {
    if ($this->config->problems() !== []) {
      return new UnconfiguredHandler($this->responses, $this->streams, rejected: $this->config->isConfigured());
    }
    return ServerBuilder::build($this->services->create());
  }

}
