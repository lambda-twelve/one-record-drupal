<?php

declare(strict_types=1);

namespace Drupal\one_record\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\one_record\Notification\Delivery;
use Drupal\one_record\Notification\QueuedOutbox;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Delivers one outbox notification per queue item.
 *
 * A failed attempt is not re-queued here: the outbox row records when to
 * try again and cron queues it then, so the database stays the single
 * source of truth for retries.
 */
#[QueueWorker(
  id: QueuedOutbox::QUEUE,
  title: new TranslatableMarkup('ONE Record notification delivery'),
  cron: ['time' => 30],
)]
final class OutboxWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin id.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\one_record\Notification\Delivery $delivery
   *   The delivery service.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected Delivery $delivery,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The container.
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin id.
   * @param mixed $plugin_definition
   *   The plugin definition.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('one_record.delivery'));
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    if (is_numeric($data)) {
      $this->delivery->attempt((int) $data);
    }
  }

}
