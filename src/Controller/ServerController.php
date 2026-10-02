<?php

declare(strict_types=1);

namespace Drupal\one_record\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Hands ONE Record requests to the SDK server.
 *
 * Drupal's PSR-7 bridge builds the request and converts the response; the
 * controller only connects the two. Everything about the protocol happens
 * in the handler.
 */
final class ServerController implements ContainerInjectionInterface {

  public function __construct(
    private readonly RequestHandlerInterface $handler,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('one_record.handler'));
  }

  /**
   * Serves one request.
   */
  public function handle(ServerRequestInterface $request): ResponseInterface {
    return $this->handler->handle($request);
  }

}
