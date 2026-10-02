<?php

declare(strict_types=1);

namespace Drupal\one_record\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\one_record\Auth\SignerFactory;
use Drupal\one_record\Config\OneRecordConfig;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Publishes the public key of the tokens this server issues.
 *
 * Partners verify our tokens through this document, at the location the
 * SDK's JWKS resolver assumes by default: {issuer}/.well-known/jwks.json.
 */
final class JwksController implements ContainerInjectionInterface {

  public function __construct(
    private readonly SignerFactory $signer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('one_record.signer_factory'));
  }

  /**
   * The JWKS document.
   */
  public function document(): CacheableJsonResponse {
    $response = new CacheableJsonResponse(['keys' => [$this->signer->create()->publicJwk()]]);
    $response->addCacheableDependency((new CacheableMetadata())
      ->setCacheMaxAge(3600)
      ->setCacheTags(['config:' . OneRecordConfig::NAME]));
    return $response;
  }

}
