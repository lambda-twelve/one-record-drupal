<?php

declare(strict_types=1);

namespace Drupal\one_record\EventSubscriber;

use Drupal\one_record\Controller\ServerController;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Puts the SDK's protocol headers back after core's response pass.
 *
 * FinishResponseSubscriber sets Content-Language to Drupal's current
 * language on every response, which is not the language the SDK negotiated
 * for the representation, and strips the validators of a response it deems
 * uncacheable. The controller records the headers the SDK sent; this runs
 * after core and restores the ones that describe the representation.
 */
final class ResponseHeadersSubscriber implements EventSubscriberInterface {

  /**
   * The headers that describe the SDK's representation, not Drupal's page.
   */
  private const PROTOCOL = ['Content-Language', 'Last-Modified', 'ETag', 'Vary'];

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // After FinishResponseSubscriber::onRespond(), which runs at 0.
    return [KernelEvents::RESPONSE => ['onResponse', -16]];
  }

  /**
   * Restores the recorded headers.
   */
  public function onResponse(ResponseEvent $event): void {
    $sent = $event->getRequest()->attributes->get(ServerController::HEADERS);
    if (!is_array($sent)) {
      return;
    }
    $response = $event->getResponse();
    foreach (self::PROTOCOL as $name) {
      $values = $sent[strtolower($name)] ?? NULL;
      if (is_array($values)) {
        $response->headers->set($name, $values);
      }
    }
  }

}
