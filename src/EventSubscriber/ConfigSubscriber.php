<?php

declare(strict_types=1);

namespace Drupal\one_record\EventSubscriber;

use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Routing\RouteBuilderInterface;
use Drupal\one_record\Config\OneRecordConfig;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Rebuilds the router when settings that shape the routes change.
 */
final class ConfigSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly RouteBuilderInterface $routeBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [ConfigEvents::SAVE => 'onSave'];
  }

  /**
   * Marks the router for rebuilding when the base path or token path moved.
   */
  public function onSave(ConfigCrudEvent $event): void {
    if ($event->getConfig()->getName() !== OneRecordConfig::NAME) {
      return;
    }
    if ($event->isChanged('base_path') || $event->isChanged('token_endpoint.enabled') || $event->isChanged('token_endpoint.path')) {
      $this->routeBuilder->setRebuildNeeded();
    }
  }

}
