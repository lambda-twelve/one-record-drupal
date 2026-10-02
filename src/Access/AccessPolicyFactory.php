<?php

declare(strict_types=1);

namespace Drupal\one_record\Access;

use Drupal\one_record\Config\OneRecordConfig;
use LambdaTwelve\OneRecord\Server\GrantAccessPolicy;
use LambdaTwelve\OneRecord\Server\Spi\AccessDelegationStore;
use Psr\Clock\ClockInterface;

/**
 * The default access policy: the SDK's grant-based policy over the database.
 *
 * GrantAccessPolicy keeps nothing in memory but the
 * list of internal agents and public grants; every other decision comes
 * from the AccessDelegationStore it is given, here the database store. The
 * internal agents are read from configuration. A site with its own rules
 * replaces the one_record.access_policy service.
 */
final class AccessPolicyFactory {

  public function __construct(
    private readonly OneRecordConfig $config,
    private readonly AccessDelegationStore $delegations,
    private readonly ClockInterface $clock,
  ) {}

  /**
   * The policy for the current configuration.
   */
  public function create(): GrantAccessPolicy {
    $policy = new GrantAccessPolicy($this->delegations, $this->clock, $this->config->denial());
    foreach ($this->config->internalAgents() as $agent) {
      $policy->addInternal($agent);
    }
    return $policy;
  }

}
