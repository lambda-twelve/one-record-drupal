<?php

declare(strict_types=1);

namespace Drupal\one_record\Auth;

use Drupal\one_record\Config\OneRecordConfig;
use LambdaTwelve\OneRecord\Auth\Jwt\ChainKeyResolver;
use LambdaTwelve\OneRecord\Auth\Jwt\JwksKeyResolver;
use LambdaTwelve\OneRecord\Auth\Jwt\KeyResolver;
use LambdaTwelve\OneRecord\Auth\Jwt\Rs256Verifier;
use LambdaTwelve\OneRecord\Auth\Jwt\StaticKeyResolver;
use LambdaTwelve\OneRecord\Auth\JwtAuthenticator;
use LambdaTwelve\OneRecord\Server\Spi\Authenticator;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * The default authenticator: the SDK's RS256 bearer-token verification.
 *
 * Trusted issuers come from configuration, each with pinned public keys or
 * a JWKS document; when this server issues tokens itself, its own issuer and
 * public key are trusted too. A site with another token scheme replaces the
 * one_record.authenticator service with its own Authenticator.
 */
final class AuthenticatorFactory {

  public function __construct(
    private readonly OneRecordConfig $config,
    private readonly ClockInterface $clock,
    private readonly ClientInterface $http,
    private readonly RequestFactoryInterface $requests,
    private readonly CacheInterface $cache,
    private readonly LoggerInterface $logger,
    private readonly SignerFactory $signer,
  ) {}

  /**
   * The authenticator for the current configuration.
   */
  public function create(): Authenticator {
    $verifier = new Rs256Verifier($this->keyResolver(), $this->clock, $this->config->audience(), $this->config->leewaySeconds());
    return new JwtAuthenticator($verifier, $this->logger);
  }

  /**
   * The key resolver behind the authenticator.
   */
  public function keyResolver(): KeyResolver {
    $static = [];
    $jwks = [];
    foreach ($this->config->issuers() as $issuer) {
      if ($issuer['public_keys'] !== []) {
        $static[$issuer['issuer']] = $issuer['public_keys'];
      }
      else {
        $jwks[$issuer['issuer']] = $issuer['jwks_url'];
      }
    }
    if ($this->config->tokenEndpointEnabled() && $this->signer->hasKey()) {
      $static[$this->config->tokenIssuer()] = [$this->signer->create()->publicKeyPem()];
    }
    $resolvers = [];
    if ($static !== []) {
      $resolvers[] = new StaticKeyResolver($static);
    }
    if ($jwks !== []) {
      $resolvers[] = new JwksKeyResolver($jwks, $this->http, $this->requests, $this->cache, logger: $this->logger);
    }
    return match (count($resolvers)) {
      0 => new StaticKeyResolver([]),
      1 => $resolvers[0],
      default => new ChainKeyResolver(...$resolvers),
    };
  }

}
