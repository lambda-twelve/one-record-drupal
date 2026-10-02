<?php

declare(strict_types=1);

namespace Drupal\one_record\Notification;

use Drupal\Core\Site\Settings;
use Drupal\one_record\Config\OneRecordConfig;
use LambdaTwelve\OneRecord\Rdf\Iri;

/**
 * Partners from configuration, secrets from settings.php.
 *
 * Exported configuration lists each partner's agent, endpoint, token URL and
 * client id; the matching secret is
 * $settings['one_record.partner_secrets'][client id].
 */
final class ConfigPartnerRegistry implements PartnerRegistryInterface {

  public function __construct(
    private readonly OneRecordConfig $config,
    private readonly Settings $settings,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function partner(Iri $agent): ?Partner {
    foreach ($this->config->partners() as $row) {
      if ($row['agent'] !== $agent->value) {
        continue;
      }
      $secrets = $this->settings->get('one_record.partner_secrets', []);
      $secret = is_array($secrets) ? ($secrets[$row['client_id']] ?? NULL) : NULL;
      return new Partner(
        $row['agent'],
        $row['endpoint'],
        $row['token_url'],
        $row['client_id'],
        is_string($secret) ? $secret : '',
        $row['scope'],
        $row['basic_auth'],
      );
    }
    return NULL;
  }

}
