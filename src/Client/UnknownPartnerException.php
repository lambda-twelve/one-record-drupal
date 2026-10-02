<?php

declare(strict_types=1);

namespace Drupal\one_record\Client;

use LambdaTwelve\OneRecord\Rdf\Iri;

/**
 * Thrown when no partner is registered for an agent.
 */
final class UnknownPartnerException extends \RuntimeException {

  public function __construct(Iri $agent, string $reason = 'it is not in the partner registry') {
    parent::__construct(sprintf('Cannot build a client for %s: %s.', $agent->value, $reason));
  }

}
