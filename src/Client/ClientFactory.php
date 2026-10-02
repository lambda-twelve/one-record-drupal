<?php

declare(strict_types=1);

namespace Drupal\one_record\Client;

use Drupal\one_record\Notification\Partner;
use Drupal\one_record\Notification\PartnerRegistryInterface;
use LambdaTwelve\OneRecord\Client\ClientCredentialsTokenProvider;
use LambdaTwelve\OneRecord\Client\OneRecordClient;
use LambdaTwelve\OneRecord\Client\TokenProvider;
use LambdaTwelve\OneRecord\Rdf\Iri;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Builds SDK clients for partners' servers from Drupal's HTTP client and cache.
 *
 * The SDK client is one instance per partner endpoint with that partner's
 * token provider, so there is no single global client: ask the factory.
 */
final class ClientFactory {

  public function __construct(
    private readonly ClientInterface $http,
    private readonly RequestFactoryInterface $requests,
    private readonly StreamFactoryInterface $streams,
    private readonly ClockInterface $clock,
    private readonly CacheInterface $cache,
    private readonly LoggerInterface $logger,
    private readonly PartnerRegistryInterface $partners,
  ) {}

  /**
   * A client for a registered partner.
   *
   * @param \LambdaTwelve\OneRecord\Rdf\Iri $agent
   *   The partner's agent IRI.
   * @param string|null $endpoint
   *   The server endpoint, when the registry does not know it.
   *
   * @throws \Drupal\one_record\Client\UnknownPartnerException
   */
  public function forPartner(Iri $agent, ?string $endpoint = NULL): OneRecordClient {
    $partner = $this->partners->partner($agent) ?? throw new UnknownPartnerException($agent);
    $endpoint = $partner->endpoint ?? $endpoint ?? throw new UnknownPartnerException($agent, 'its server endpoint is not configured and cannot be derived');
    return $this->create($endpoint, $this->tokens($partner));
  }

  /**
   * A client for any endpoint with the given token source.
   */
  public function create(string $endpoint, TokenProvider $tokens): OneRecordClient {
    return new OneRecordClient($this->http, $this->requests, $this->streams, $tokens, $endpoint, $this->cache, $this->clock, $this->logger);
  }

  /**
   * A client-credentials token provider for a partner.
   */
  public function tokens(Partner $partner): TokenProvider {
    return new ClientCredentialsTokenProvider(
      $this->http,
      $this->requests,
      $this->streams,
      $this->clock,
      $partner->tokenUrl,
      $partner->clientId,
      $partner->clientSecret,
      $this->cache,
      $partner->scope,
      $partner->basicAuth,
    );
  }

}
