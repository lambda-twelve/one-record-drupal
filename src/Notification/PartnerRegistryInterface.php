<?php

declare(strict_types=1);

namespace Drupal\one_record\Notification;

use LambdaTwelve\OneRecord\Rdf\Iri;

/**
 * Knows the partners this host talks to and their credentials.
 *
 * The default reads one_record.settings and settings.php; a site that keeps
 * partner records elsewhere replaces the one_record.partner_registry service.
 */
interface PartnerRegistryInterface {

  /**
   * The partner acting as the given agent, or NULL when unknown.
   */
  public function partner(Iri $agent): ?Partner;

}
