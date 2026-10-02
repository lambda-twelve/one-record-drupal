<?php

declare(strict_types=1);

namespace Drupal\one_record\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\one_record\Auth\DatabaseClientCredentials;
use Drupal\one_record\Auth\SignerFactory;
use Drupal\one_record\Config\OneRecordConfig;
use LambdaTwelve\OneRecord\Auth\TokenEndpoint;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Serves the SDK's OAuth 2.0 client-credentials token endpoint.
 */
final class TokenController implements ContainerInjectionInterface {

  public function __construct(
    private readonly TokenEndpoint $endpoint,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $config = $container->get('one_record.config');
    $signer = $container->get('one_record.signer_factory');
    $psr17 = $container->get('one_record.psr17_factory');
    assert($config instanceof OneRecordConfig && $signer instanceof SignerFactory);
    assert($psr17 instanceof ResponseFactoryInterface && $psr17 instanceof StreamFactoryInterface);
    $credentials = $container->get('one_record.client_credentials');
    $logger = $container->get('logger.channel.one_record');
    assert($credentials instanceof DatabaseClientCredentials && $logger instanceof LoggerInterface);
    return new static(new TokenEndpoint($credentials, $signer->create(), $psr17, $psr17, $config->tokenTtlSeconds(), $config->tokenAudience(), $logger));
  }

  /**
   * Issues a token for valid client credentials.
   */
  public function handle(ServerRequestInterface $request): ResponseInterface {
    return $this->endpoint->handle($request);
  }

}
