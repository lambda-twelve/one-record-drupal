<?php

declare(strict_types=1);

namespace Drupal\one_record\Notification;

/**
 * How to reach one partner's ONE Record server.
 */
final class Partner {

  public function __construct(
    public readonly string $agent,
    public readonly ?string $endpoint,
    public readonly string $tokenUrl,
    public readonly string $clientId,
    #[\SensitiveParameter]
    public readonly string $clientSecret,
    public readonly ?string $scope = NULL,
    public readonly bool $basicAuth = FALSE,
  ) {}

}
