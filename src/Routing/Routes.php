<?php

declare(strict_types=1);

namespace Drupal\one_record\Routing;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\one_record\Authentication\Provider\TokenEndpointProvider;
use Drupal\one_record\Config\OneRecordConfig;
use Drupal\one_record\Controller\JwksController;
use Drupal\one_record\Controller\ServerController;
use Drupal\one_record\Controller\TokenController;
use LambdaTwelve\OneRecord\Server\ServerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Routing\Route;

/**
 * Registers one Drupal route per ONE Record endpoint under the base path.
 *
 * Every endpoint route points at the same controller; the SDK does the real
 * routing, including 405 and version gating. Registering each pattern as a
 * named Drupal route, instead of one catch-all, lets a site alter access,
 * authentication or options per endpoint with an ordinary RouteSubscriber,
 * and keeps Drupal's router responsible for what lies outside the API.
 *
 * The patterns come from ServerBuilder::routes(), so an endpoint added to
 * the SDK appears here without a change. Every HTTP method is declared on
 * each route: Drupal would otherwise limit routes to GET and POST
 * (RouteMethodSubscriber), and the SDK must stay the one that answers 405
 * with the spec's error body and Allow header.
 */
final class Routes implements ContainerInjectionInterface {

  /**
   * Drupal route suffixes for the SDK's route names.
   *
   * Listed where they differ from the derived form (dashes and dots become
   * underscores), so the names sites may already refer to stay stable.
   */
  private const NAMES = [
    'logistics-objects.create' => 'logistics_objects',
    'logistics-object.audit-trail' => 'audit_trail',
    'logistics-object.events' => 'logistics_events',
    'logistics-object.event' => 'logistics_event',
    'logistics-events.bulk' => 'bulk_logistics_events',
  ];

  /**
   * Every method the SDK may be asked about, so Drupal never answers 405.
   */
  private const METHODS = ['GET', 'HEAD', 'POST', 'PATCH', 'PUT', 'DELETE', 'OPTIONS'];

  public function __construct(
    private readonly OneRecordConfig $config,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('one_record.config'));
  }

  /**
   * The routes for the current configuration.
   *
   * @return array<string, \Symfony\Component\Routing\Route>
   *   Routes keyed by name.
   */
  public function routes(): array {
    $base = $this->config->basePath();
    $routes = [];
    // The bulk endpoint is registered whether or not it is enabled: the SDK
    // answers for it either way, with 404 when the configuration says no.
    foreach (ServerBuilder::routes(TRUE) as $endpoint) {
      $name = self::NAMES[$endpoint->name] ?? str_replace(['-', '.'], '_', $endpoint->name);
      $pattern = $endpoint->pattern === '/' ? '' : $endpoint->pattern;
      $routes['one_record.' . $name] = new Route(
        ($base . $pattern) ?: '/',
        ['_controller' => ServerController::class . '::handle'],
        ['_access' => 'TRUE'],
        ['no_cache' => TRUE],
        '',
        [],
        self::METHODS,
      );
    }
    if ($this->config->tokenEndpointEnabled()) {
      // Basic credentials on this route belong to the SDK's token endpoint,
      // not to Drupal's basic_auth; see TokenEndpointProvider.
      $routes['one_record.token'] = new Route(
        $this->config->tokenPath(),
        ['_controller' => TokenController::class . '::handle'],
        ['_access' => 'TRUE'],
        ['no_cache' => TRUE, '_auth' => [TokenEndpointProvider::ID]],
        '',
        [],
        self::METHODS,
      );
      $routes['one_record.jwks'] = new Route(
        $base . '/.well-known/jwks.json',
        ['_controller' => JwksController::class . '::document'],
        ['_access' => 'TRUE'],
        [],
        '',
        [],
        ['GET', 'HEAD'],
      );
    }
    return $routes;
  }

}
