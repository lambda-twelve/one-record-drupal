<?php

declare(strict_types=1);

namespace Drupal\one_record\Server;

use LambdaTwelve\OneRecord\Vocabulary\Generated\Api;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Answers 503 until the module has a base URL and a data holder.
 */
final class UnconfiguredHandler implements RequestHandlerInterface {

  public function __construct(
    private readonly ResponseFactoryInterface $responses,
    private readonly StreamFactoryInterface $streams,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function handle(ServerRequestInterface $request): ResponseInterface {
    $body = json_encode([
      '@context' => ['api' => Api::NAMESPACE],
      '@type' => 'api:Error',
      'api:hasTitle' => 'This ONE Record server is not configured yet.',
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    return $this->responses->createResponse(503)
      ->withHeader('Content-Type', 'application/ld+json')
      ->withHeader('Retry-After', '300')
      ->withBody($this->streams->createStream($body));
  }

}
