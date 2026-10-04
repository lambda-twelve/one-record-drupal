<?php

declare(strict_types=1);

namespace Drupal\one_record\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\one_record\Config\OneRecordConfig;
use Drupal\one_record\Server\ServicesFactory;
use LambdaTwelve\OneRecord\Server\ServerBuilder;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The module's line on the status report.
 *
 * Whether the server is configured, and what the SDK finds amiss in the
 * wiring (ServerBuilder::check()), shown where an operator looks rather than
 * in the request log. Drupal 11.2 and later call runtimeRequirements()
 * through the OOP hook; older releases reach the same method through the
 * legacy one_record_requirements() in the install file.
 */
final class RequirementsHooks {

  use StringTranslationTrait;

  public function __construct(
    #[Autowire(service: 'one_record.config')]
    private readonly OneRecordConfig $config,
    #[Autowire(service: 'one_record.services_factory')]
    private readonly ServicesFactory $services,
  ) {}

  /**
   * Implements hook_runtime_requirements().
   *
   * @return array<string, array<string, mixed>>
   *   The requirements.
   */
  #[Hook('runtime_requirements')]
  public function runtimeRequirements(): array {
    $problems = $this->config->problems();
    if ($problems !== []) {
      // Unset settings are a warning (the module answers 503 meanwhile);
      // settings the SDK rejects are an error.
      return [
        'one_record' => [
          'title' => $this->t('ONE Record'),
          'value' => $this->config->isConfigured() ? $this->t('Settings rejected') : $this->t('Not configured'),
          'description' => implode(' ', $problems) . ' ' . $this->t('Until then requests under the base path answer 503.'),
          'severity' => $this->config->isConfigured() ? REQUIREMENT_ERROR : REQUIREMENT_WARNING,
        ],
      ];
    }
    $findings = ServerBuilder::check($this->services->create());
    return [
      'one_record' => [
        'title' => $this->t('ONE Record'),
        'value' => $findings === [] ? $this->t('Serving at @endpoint', ['@endpoint' => $this->config->endpoint()]) : $this->t('Wiring problems'),
        'description' => $findings === [] ? NULL : implode(' ', $findings),
        'severity' => $findings === [] ? REQUIREMENT_OK : REQUIREMENT_ERROR,
      ],
    ];
  }

}
