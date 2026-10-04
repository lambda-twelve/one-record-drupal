<?php

declare(strict_types=1);

namespace Drupal\one_record\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\one_record\Config\OneRecordConfig;
use LambdaTwelve\OneRecord\Rdf\Iri;
use LambdaTwelve\OneRecord\Server\ServerConfig;
use LambdaTwelve\OneRecord\Spec\ApiVersion;
use LambdaTwelve\OneRecord\Spec\DataModelVersion;

/**
 * The server settings.
 *
 * What the SDK's ServerConfig needs, plus the access and token-endpoint
 * switches.
 *
 * Trusted issuers and partners are structured lists; they are managed
 * through configuration management (one_record.settings) rather than here.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'one_record_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [OneRecordConfig::NAME];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(OneRecordConfig::NAME);

    $form['server'] = [
      '#type' => 'details',
      '#title' => $this->t('Server'),
      '#open' => TRUE,
    ];
    $form['server']['base_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Base URL'),
      '#description' => $this->t('Scheme and host of this server, for example <code>https://1r.example.com</code>. Logistics object IRIs are built from it and stored, so change it only before publishing data.'),
      '#default_value' => $config->get('base_url'),
      '#required' => TRUE,
    ];
    $form['server']['base_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Base path'),
      '#description' => $this->t('The path the API is served under, starting with a slash. The server endpoint is the base URL followed by this path.'),
      '#default_value' => $config->get('base_path'),
    ];
    $form['server']['data_holder'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Data holder IRI'),
      '#description' => $this->t('The IRI of the organisation operating this server, normally a logistics object served here, such as <code>https://1r.example.com/one-record/logistics-objects/holder</code>.'),
      '#default_value' => $config->get('data_holder'),
      '#required' => TRUE,
    ];
    $form['server']['data_holder_type'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Data holder type'),
      '#description' => $this->t('The class of the data holder for server information, for example <code>https://onerecord.iata.org/ns/cargo#Company</code>. Leave empty to read it from the stored holder object.'),
      '#default_value' => $config->get('data_holder_type'),
    ];
    $form['server']['api_versions'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('API versions offered'),
      '#description' => $this->t('Leave all unchecked to offer every version the SDK implements.'),
      '#options' => array_combine(array_map(static fn(ApiVersion $v): string => $v->value, ApiVersion::cases()), array_map(static fn(ApiVersion $v): string => $v->value, ApiVersion::cases())),
      '#default_value' => $config->get('api_versions') ?? [],
    ];
    $form['server']['data_model_versions'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Data model versions listed'),
      '#options' => array_combine(array_map(static fn(DataModelVersion $v): string => $v->value, DataModelVersion::cases()), array_map(static fn(DataModelVersion $v): string => $v->value, DataModelVersion::cases())),
      '#default_value' => $config->get('data_model_versions') ?? [],
    ];
    $form['server']['languages'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Content languages'),
      '#description' => $this->t('Comma-separated language tags; must include en-US.'),
      '#default_value' => implode(', ', (array) $config->get('languages')),
    ];
    $form['server']['max_body_bytes'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum request body (bytes)'),
      '#min' => 1024,
      '#default_value' => $config->get('max_body_bytes'),
    ];
    $form['server']['embedded_depth'] = [
      '#type' => 'number',
      '#title' => $this->t('Embedding depth'),
      '#description' => $this->t('How many links to follow for <code>?embedded=true</code>.'),
      '#min' => 0,
      '#max' => 10,
      '#default_value' => $config->get('embedded_depth'),
    ];
    $form['server']['bulk_logistics_events'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Serve the bulk logistics events endpoint (API 2.3)'),
      '#default_value' => $config->get('bulk_logistics_events'),
    ];

    $form['access'] = [
      '#type' => 'details',
      '#title' => $this->t('Access'),
      '#open' => TRUE,
    ];
    $form['access']['denial'] = [
      '#type' => 'radios',
      '#title' => $this->t('Denied requests'),
      '#options' => [
        'forbid' => $this->t('Answer 403 Forbidden'),
        'hide' => $this->t('Answer 404 Not Found, so existence is not confirmed'),
      ],
      '#default_value' => $config->get('denial') ?? 'forbid',
    ];
    $form['access']['internal_agents'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Internal agents'),
      '#description' => $this->t('One agent IRI per line. Internal agents have full access, including creating objects and deciding action requests. The data holder is always internal.'),
      '#default_value' => implode("\n", (array) $config->get('internal_agents')),
    ];
    $form['access']['audience'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Expected token audience'),
      '#description' => $this->t('The <code>aud</code> claim bearer tokens must carry. Leave empty to skip the check.'),
      '#default_value' => $config->get('authentication.audience'),
    ];
    $form['access']['leeway_seconds'] = [
      '#type' => 'number',
      '#title' => $this->t('Clock leeway (seconds)'),
      '#min' => 0,
      '#default_value' => $config->get('authentication.leeway_seconds'),
    ];

    $form['token'] = [
      '#type' => 'details',
      '#title' => $this->t('Token endpoint'),
      '#description' => $this->t('Issue OAuth 2.0 client-credentials tokens for partners and internal systems. The RS256 private key is read from settings.php.'),
      '#open' => (bool) $config->get('token_endpoint.enabled'),
    ];
    $form['token']['token_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Serve the token endpoint'),
      '#default_value' => $config->get('token_endpoint.enabled'),
    ];
    $form['token']['token_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Path'),
      '#default_value' => $config->get('token_endpoint.path'),
    ];
    $form['token']['token_issuer'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Issuer'),
      '#description' => $this->t('The <code>iss</code> claim. Leave empty for the server endpoint URL.'),
      '#default_value' => $config->get('token_endpoint.issuer'),
    ];
    $form['token']['token_ttl_seconds'] = [
      '#type' => 'number',
      '#title' => $this->t('Token lifetime (seconds)'),
      '#min' => 60,
      '#default_value' => $config->get('token_endpoint.ttl_seconds'),
    ];
    $form['token']['token_audience'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Audience written into tokens'),
      '#default_value' => $config->get('token_endpoint.audience'),
    ];
    $form['token']['token_key_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Key id'),
      '#description' => $this->t('The <code>kid</code> header, if partners need one.'),
      '#default_value' => $config->get('token_endpoint.key_id'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    // The SDK says what is wrong with each setting, without constructing
    // anything; each sentence lands on the field it is about.
    $problems = ServerConfig::problems([
      'baseUrl' => trim((string) $form_state->getValue('base_url')),
      'dataHolder' => trim((string) $form_state->getValue('data_holder')),
      'basePath' => rtrim(trim((string) $form_state->getValue('base_path')), '/'),
      'apiVersions' => self::checked($form_state->getValue('api_versions')) ?: NULL,
      'dataModelVersions' => self::checked($form_state->getValue('data_model_versions')) ?: NULL,
      'languages' => self::languages($form_state->getValue('languages')),
      'maxBodyBytes' => (int) $form_state->getValue('max_body_bytes'),
      'embeddedDepth' => (int) $form_state->getValue('embedded_depth'),
    ]);
    foreach ($problems as $problem) {
      $form_state->setErrorByName(self::fieldFor($problem), $problem);
    }
    if ($problems !== []) {
      return;
    }
    try {
      new ServerConfig(
        trim((string) $form_state->getValue('base_url')),
        new Iri(trim((string) $form_state->getValue('data_holder'))),
        rtrim(trim((string) $form_state->getValue('base_path')), '/'),
        self::versions($form_state->getValue('api_versions'), ApiVersion::tryFromString(...)),
        self::versions($form_state->getValue('data_model_versions'), DataModelVersion::tryFromString(...)),
        self::languages($form_state->getValue('languages')),
        (int) $form_state->getValue('max_body_bytes'),
        (int) $form_state->getValue('embedded_depth'),
        (bool) $form_state->getValue('bulk_logistics_events'),
        trim((string) $form_state->getValue('data_holder_type')) ?: NULL,
      );
    }
    catch (\InvalidArgumentException $e) {
      $form_state->setErrorByName('base_url', $this->t('The SDK rejected these settings: @message', ['@message' => $e->getMessage()]));
    }
    foreach (self::lines($form_state->getValue('internal_agents')) as $agent) {
      if (!filter_var($agent, FILTER_VALIDATE_URL) && !str_contains($agent, ':')) {
        $form_state->setErrorByName('internal_agents', $this->t('"@agent" is not an IRI.', ['@agent' => $agent]));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config(OneRecordConfig::NAME)
      ->set('base_url', trim((string) $form_state->getValue('base_url')))
      ->set('base_path', rtrim(trim((string) $form_state->getValue('base_path')), '/'))
      ->set('data_holder', trim((string) $form_state->getValue('data_holder')))
      ->set('data_holder_type', trim((string) $form_state->getValue('data_holder_type')))
      ->set('api_versions', self::checked($form_state->getValue('api_versions')))
      ->set('data_model_versions', self::checked($form_state->getValue('data_model_versions')))
      ->set('languages', self::languages($form_state->getValue('languages')))
      ->set('max_body_bytes', (int) $form_state->getValue('max_body_bytes'))
      ->set('embedded_depth', (int) $form_state->getValue('embedded_depth'))
      ->set('bulk_logistics_events', (bool) $form_state->getValue('bulk_logistics_events'))
      ->set('denial', $form_state->getValue('denial') === 'hide' ? 'hide' : 'forbid')
      ->set('internal_agents', self::lines($form_state->getValue('internal_agents')))
      ->set('authentication.audience', trim((string) $form_state->getValue('audience')))
      ->set('authentication.leeway_seconds', (int) $form_state->getValue('leeway_seconds'))
      ->set('token_endpoint.enabled', (bool) $form_state->getValue('token_enabled'))
      ->set('token_endpoint.path', '/' . trim(trim((string) $form_state->getValue('token_path')), '/'))
      ->set('token_endpoint.issuer', trim((string) $form_state->getValue('token_issuer')))
      ->set('token_endpoint.ttl_seconds', (int) $form_state->getValue('token_ttl_seconds'))
      ->set('token_endpoint.audience', trim((string) $form_state->getValue('token_audience')))
      ->set('token_endpoint.key_id', trim((string) $form_state->getValue('token_key_id')))
      ->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * The form field a problem sentence from the SDK is about.
   */
  private static function fieldFor(string $problem): string {
    $fields = [
      'base URL' => 'base_url',
      'data holder' => 'data_holder',
      'base path' => 'base_path',
      'API version' => 'api_versions',
      'data model version' => 'data_model_versions',
      'en-US' => 'languages',
      'body limit' => 'max_body_bytes',
      'embedding depth' => 'embedded_depth',
    ];
    foreach ($fields as $needle => $field) {
      if (str_contains($problem, $needle)) {
        return $field;
      }
    }
    return 'base_url';
  }

  /**
   * The checked options of a checkboxes element.
   *
   * @return list<string>
   *   The values.
   */
  private static function checked(mixed $value): array {
    return is_array($value) ? array_values(array_filter(array_map('strval', $value), static fn(string $v): bool => $v !== '0' && $v !== '')) : [];
  }

  /**
   * Checked versions parsed through an enum, or NULL for "all".
   *
   * @param mixed $value
   *   The checkboxes value.
   * @param callable(string): ?T $parse
   *   The enum's tryFromString.
   *
   * @return list<T>|null
   *   The versions.
   *
   * @template T of \UnitEnum
   */
  private static function versions(mixed $value, callable $parse): ?array {
    $out = [];
    foreach (self::checked($value) as $version) {
      $parsed = $parse($version);
      if ($parsed !== NULL) {
        $out[] = $parsed;
      }
    }
    return $out === [] ? NULL : $out;
  }

  /**
   * Comma-separated language tags.
   *
   * @return list<string>
   *   The tags.
   */
  private static function languages(mixed $value): array {
    return array_values(array_filter(array_map('trim', explode(',', (string) $value)), static fn(string $v): bool => $v !== ''));
  }

  /**
   * Non-empty lines of a textarea.
   *
   * @return list<string>
   *   The lines.
   */
  private static function lines(mixed $value): array {
    return array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $value) ?: []), static fn(string $v): bool => $v !== ''));
  }

}
