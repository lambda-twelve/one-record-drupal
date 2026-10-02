<?php

declare(strict_types=1);

namespace Drupal\one_record\Routing;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\one_record\Config\OneRecordConfig;
use Drupal\one_record\Controller\JwksController;
use Drupal\one_record\Controller\ServerController;
use Drupal\one_record\Controller\TokenController;
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
 * The pattern list mirrors ServerBuilder, which does not expose its table.
 * Every HTTP method is declared on each route: Drupal would otherwise limit
 * routes to GET and POST (RouteMethodSubscriber), and the SDK must stay the
 * one that answers 405 with the spec's error body and Allow header.
 */
final class Routes implements ContainerInjectionInterface {

  /**
   * Endpoint patterns relative to the base path, keyed by route suffix.
   */
  private const ENDPOINTS = [
    'server_information' => '',
    'logistics_objects' => '/logistics-objects',
    'logistics_object' => '/logistics-objects/{id}',
    'audit_trail' => '/logistics-objects/{id}/audit-trail',
    'logistics_events' => '/logistics-objects/{id}/logistics-events',
    'logistics_event' => '/logistics-objects/{id}/logistics-events/{event}',
    'notifications' => '/notifications',
    'subscriptions' => '/subscriptions',
    'access_delegations' => '/access-delegations',
    'action_request' => '/action-requests/{id}',
    'bulk_logistics_events' => '/logistics-events',
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
    foreach (self::ENDPOINTS as $name => $pattern) {
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
      $routes['one_record.token'] = new Route(
        $this->config->tokenPath(),
        ['_controller' => TokenController::class . '::handle'],
        ['_access' => 'TRUE'],
        ['no_cache' => TRUE],
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
