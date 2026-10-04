<?php

declare(strict_types=1);

namespace Drupal\one_record\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Hands ONE Record requests to the SDK server.
 *
 * Drupal's PSR-7 bridge builds the request and converts the response; the
 * controller only connects the two. Everything about the protocol happens
 * in the handler. Two things are done for Drupal's response pipeline: an
 * explicit Cache-Control, so core does not treat the response as its own
 * uncacheable page and strip Last-Modified from it, and a record of the
 * headers the SDK sent, which ResponseHeadersSubscriber restores after core
 * has overwritten Content-Language.
 */
final class ServerController implements ContainerInjectionInterface {

  /**
   * The request attribute holding the SDK response's headers.
   */
  public const HEADERS = 'one_record.response_headers';

  public function __construct(
    private readonly RequestHandlerInterface $handler,
    private readonly RequestStack $requests,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('one_record.handler'), $container->get('request_stack'));
  }

  /**
   * Serves one request.
   */
  public function handle(ServerRequestInterface $request): ResponseInterface {
    $response = $this->handler->handle($request);
    if (!$response->hasHeader('Cache-Control')) {
      // Authenticated API data: never stored by a shared cache or the page
      // cache. Set here rather than left to core, whose default for an
      // uncacheable response also removes the validators partners poll by.
      $response = $response->withHeader('Cache-Control', 'no-store, private');
    }
    $this->requests->getCurrentRequest()?->attributes->set(self::HEADERS, array_change_key_case($response->getHeaders(), CASE_LOWER));
    return $response;
  }

}
