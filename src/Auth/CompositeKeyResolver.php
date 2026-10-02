<?php

declare(strict_types=1);

namespace Drupal\one_record\Auth;

use LambdaTwelve\OneRecord\Auth\Jwt\KeyResolver;

/**
 * Asks several key resolvers in turn; the first that knows the issuer wins.
 *
 * The SDK ships a static resolver and a JWKS resolver but nothing that
 * combines them, while a real deployment trusts some issuers by pinned key
 * and others by discovery. This belongs in the SDK; it lives here until it
 * does.
 */
final class CompositeKeyResolver implements KeyResolver {

  /**
   * The resolvers, in the order they are asked.
   *
   * @var list<\LambdaTwelve\OneRecord\Auth\Jwt\KeyResolver>
   */
  private readonly array $resolvers;

  public function __construct(KeyResolver ...$resolvers) {
    $this->resolvers = array_values($resolvers);
  }

  /**
   * {@inheritdoc}
   */
  public function publicKeys(string $issuer, ?string $keyId): array {
    foreach ($this->resolvers as $resolver) {
      $keys = $resolver->publicKeys($issuer, $keyId);
      if ($keys !== []) {
        return $keys;
      }
    }
    return [];
  }

}
