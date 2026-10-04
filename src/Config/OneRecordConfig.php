<?php

declare(strict_types=1);

namespace Drupal\one_record\Config;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use LambdaTwelve\OneRecord\Rdf\Iri;
use LambdaTwelve\OneRecord\Server\ServerConfig;
use LambdaTwelve\OneRecord\Server\Spi\Decision;
use LambdaTwelve\OneRecord\Spec\ApiVersion;
use LambdaTwelve\OneRecord\Spec\DataModelVersion;

/**
 * Reads one_record.settings and translates it into the SDK's value objects.
 *
 * The SDK's ServerConfig validates what it receives, so this class only
 * maps shapes: strings to IRIs and enums, empty lists to "all versions".
 */
final class OneRecordConfig {

  public const NAME = 'one_record.settings';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Whether the two settings without a sensible default have been set.
   */
  public function isConfigured(): bool {
    return $this->string('base_url') !== '' && $this->string('data_holder') !== '';
  }

  /**
   * What the SDK finds wrong with the stored settings, in plain sentences.
   *
   * Empty when serverConfig() would succeed. Asked before anything is
   * constructed, so a status page can say what is missing on an install
   * that is not configured yet.
   *
   * @return list<string>
   *   The problems.
   */
  public function problems(): array {
    return ServerConfig::problems([
      'baseUrl' => $this->string('base_url'),
      'dataHolder' => $this->string('data_holder'),
      'basePath' => $this->basePath(),
      'apiVersions' => $this->list('api_versions') ?: NULL,
      'dataModelVersions' => $this->list('data_model_versions') ?: NULL,
      'languages' => $this->list('languages') ?: ['en-US'],
      'maxBodyBytes' => $this->int('max_body_bytes', 1_048_576),
      'embeddedDepth' => $this->int('embedded_depth', 3),
    ]);
  }

  /**
   * The SDK server configuration.
   *
   * @throws \Drupal\one_record\Config\NotConfiguredException
   * @throws \InvalidArgumentException
   *   When a value is not acceptable to the SDK.
   */
  public function serverConfig(): ServerConfig {
    if (!$this->isConfigured()) {
      throw new NotConfiguredException();
    }
    return new ServerConfig(
      $this->string('base_url'),
      new Iri($this->string('data_holder')),
      $this->basePath(),
      self::versions($this->list('api_versions'), ApiVersion::tryFromString(...)),
      self::versions($this->list('data_model_versions'), DataModelVersion::tryFromString(...)),
      $this->list('languages') ?: ['en-US'],
      $this->int('max_body_bytes', 1_048_576),
      $this->int('embedded_depth', 3),
      (bool) $this->config()->get('bulk_logistics_events'),
      $this->string('data_holder_type') ?: NULL,
    );
  }

  /**
   * The path prefix the server is mounted on, without a trailing slash.
   */
  public function basePath(): string {
    return rtrim($this->string('base_path'), '/');
  }

  /**
   * The server's endpoint URL once configured.
   */
  public function endpoint(): string {
    return $this->string('base_url') . $this->basePath();
  }

  /**
   * How the access policy answers a denied request.
   */
  public function denial(): Decision {
    return $this->string('denial') === 'hide' ? Decision::Hide : Decision::Forbid;
  }

  /**
   * Agents with full access: the configured ones and the data holder itself.
   *
   * A stored value that is not an IRI is left out rather than thrown on:
   * the access policy is built with the container, before any request
   * could answer 503 for it, and problems() is where a rejected holder is
   * reported.
   *
   * @return list<\LambdaTwelve\OneRecord\Rdf\Iri>
   *   The agent IRIs.
   */
  public function internalAgents(): array {
    $agents = $this->list('internal_agents');
    $holder = $this->string('data_holder');
    if ($holder !== '' && !in_array($holder, $agents, TRUE)) {
      $agents[] = $holder;
    }
    $iris = [];
    foreach ($agents as $agent) {
      try {
        $iris[] = new Iri($agent);
      }
      catch (\InvalidArgumentException) {
        // Reported by problems(); nothing to grant to.
      }
    }
    return $iris;
  }

  /**
   * The audience bearer tokens must carry, or NULL to skip the check.
   */
  public function audience(): ?string {
    return $this->string('authentication.audience') ?: NULL;
  }

  /**
   * Clock leeway when verifying tokens.
   */
  public function leewaySeconds(): int {
    return $this->int('authentication.leeway_seconds', 30);
  }

  /**
   * The token issuers this server trusts.
   *
   * @return list<array{issuer: string, jwks_url: ?string, public_keys: list<string>}>
   *   One entry per issuer.
   */
  public function issuers(): array {
    $out = [];
    foreach ($this->rows('authentication.issuers') as $row) {
      $issuer = trim((string) ($row['issuer'] ?? ''));
      if ($issuer === '') {
        continue;
      }
      $keys = array_values(array_filter(array_map('strval', (array) ($row['public_keys'] ?? [])), static fn(string $k): bool => trim($k) !== ''));
      $out[] = [
        'issuer' => $issuer,
        'jwks_url' => trim((string) ($row['jwks_url'] ?? '')) ?: NULL,
        'public_keys' => $keys,
      ];
    }
    return $out;
  }

  /**
   * Whether this server issues tokens itself.
   */
  public function tokenEndpointEnabled(): bool {
    return (bool) $this->config()->get('token_endpoint.enabled');
  }

  /**
   * The Drupal path of the token endpoint.
   */
  public function tokenPath(): string {
    return '/' . trim($this->string('token_endpoint.path') ?: '/one-record/oauth/token', '/');
  }

  /**
   * The issuer written into tokens; the server endpoint by default.
   */
  public function tokenIssuer(): string {
    return $this->string('token_endpoint.issuer') ?: $this->endpoint();
  }

  /**
   * The lifetime of issued tokens.
   */
  public function tokenTtlSeconds(): int {
    return $this->int('token_endpoint.ttl_seconds', 3600);
  }

  /**
   * The audience claim written into tokens, if any.
   */
  public function tokenAudience(): ?string {
    return $this->string('token_endpoint.audience') ?: NULL;
  }

  /**
   * The kid header of issued tokens, if any.
   */
  public function tokenKeyId(): ?string {
    return $this->string('token_endpoint.key_id') ?: NULL;
  }

  /**
   * The partners this host delivers notifications to.
   *
   * @return list<array{agent: string, endpoint: ?string, token_url: string, client_id: string, scope: ?string, basic_auth: bool}>
   *   One entry per partner.
   */
  public function partners(): array {
    $out = [];
    foreach ($this->rows('partners') as $row) {
      $agent = trim((string) ($row['agent'] ?? ''));
      if ($agent === '') {
        continue;
      }
      $out[] = [
        'agent' => $agent,
        'endpoint' => rtrim(trim((string) ($row['endpoint'] ?? '')), '/') ?: NULL,
        'token_url' => trim((string) ($row['token_url'] ?? '')),
        'client_id' => trim((string) ($row['client_id'] ?? '')),
        'scope' => trim((string) ($row['scope'] ?? '')) ?: NULL,
        'basic_auth' => (bool) ($row['basic_auth'] ?? FALSE),
      ];
    }
    return $out;
  }

  /**
   * How long a delivery attempt may hold a notification.
   */
  public function deliveryLeaseSeconds(): int {
    return $this->int('delivery.lease_seconds', 300);
  }

  /**
   * How many attempts before a notification is given up.
   */
  public function deliveryMaxAttempts(): int {
    return $this->int('delivery.max_attempts', 10);
  }

  /**
   * The settings object.
   */
  private function config(): ImmutableConfig {
    return $this->configFactory->get(self::NAME);
  }

  /**
   * A trimmed string setting.
   */
  private function string(string $key): string {
    $value = $this->config()->get($key);
    return is_scalar($value) ? trim((string) $value) : '';
  }

  /**
   * An integer setting.
   */
  private function int(string $key, int $default): int {
    $value = $this->config()->get($key);
    return is_numeric($value) ? (int) $value : $default;
  }

  /**
   * A list of non-empty strings.
   *
   * @return list<string>
   *   The values.
   */
  private function list(string $key): array {
    $value = $this->config()->get($key);
    if (!is_array($value)) {
      return [];
    }
    return array_values(array_filter(array_map(static fn(mixed $v): string => is_scalar($v) ? trim((string) $v) : '', $value), static fn(string $v): bool => $v !== ''));
  }

  /**
   * A list of mappings.
   *
   * @return list<array<string, mixed>>
   *   The rows.
   */
  private function rows(string $key): array {
    $value = $this->config()->get($key);
    if (!is_array($value)) {
      return [];
    }
    return array_values(array_filter($value, 'is_array'));
  }

  /**
   * Maps version strings through an enum parser; NULL means "all".
   *
   * @param list<string> $values
   *   The configured strings.
   * @param callable(string): ?T $parse
   *   The enum's tryFromString.
   *
   * @return list<T>|null
   *   The versions, or NULL when none are configured.
   *
   * @template T of \UnitEnum
   */
  private static function versions(array $values, callable $parse): ?array {
    if ($values === []) {
      return NULL;
    }
    $out = [];
    foreach ($values as $value) {
      $out[] = $parse($value) ?? throw new \InvalidArgumentException(sprintf('"%s" is not a supported version.', $value));
    }
    return $out;
  }

}
