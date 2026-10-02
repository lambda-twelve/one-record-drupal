<?php

declare(strict_types=1);

namespace Drupal\one_record\Auth;

use Drupal\Core\Site\Settings;
use Drupal\one_record\Config\OneRecordConfig;
use LambdaTwelve\OneRecord\Auth\Jwt\Rs256Signer;
use Psr\Clock\ClockInterface;

/**
 * Builds the RS256 signer for tokens this server issues.
 *
 * The private key is deployment-specific and secret, so it is read from
 * settings.php rather than from exportable configuration: either the PEM
 * itself in $settings['one_record.signing_key'] or a file path in
 * $settings['one_record.signing_key_file'].
 */
final class SignerFactory {

  public function __construct(
    private readonly OneRecordConfig $config,
    private readonly Settings $settings,
    private readonly ClockInterface $clock,
  ) {}

  /**
   * Whether a private key is available.
   */
  public function hasKey(): bool {
    return $this->pem() !== NULL;
  }

  /**
   * The signer.
   *
   * @throws \Drupal\one_record\Auth\MissingSigningKeyException
   * @throws \InvalidArgumentException
   *   When the key is not an RSA key of at least 2048 bits.
   */
  public function create(): Rs256Signer {
    return new Rs256Signer($this->pem() ?? throw new MissingSigningKeyException(), $this->config->tokenIssuer(), $this->clock, $this->config->tokenKeyId());
  }

  /**
   * The private key PEM from settings.php, if any.
   */
  private function pem(): ?string {
    $pem = $this->settings->get('one_record.signing_key');
    if (is_string($pem) && trim($pem) !== '') {
      return $pem;
    }
    $file = $this->settings->get('one_record.signing_key_file');
    if (is_string($file) && $file !== '' && is_readable($file)) {
      $contents = file_get_contents($file);
      return is_string($contents) && trim($contents) !== '' ? $contents : NULL;
    }
    return NULL;
  }

}
