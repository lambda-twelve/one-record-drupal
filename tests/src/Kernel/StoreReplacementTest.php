<?php

declare(strict_types=1);

namespace Drupal\Tests\one_record\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use LambdaTwelve\OneRecord\Api\Subscription;
use LambdaTwelve\OneRecord\Api\SubscriptionEventType;
use LambdaTwelve\OneRecord\Api\TopicType;
use LambdaTwelve\OneRecord\Rdf\Iri;
use LambdaTwelve\OneRecord\Server\DataHolder;
use LambdaTwelve\OneRecord\Server\InMemory\InMemoryActionRequestStore;
use LambdaTwelve\OneRecord\Server\Spi\ActionRequestStore;
use LambdaTwelve\OneRecord\Server\Spi\SubscriptionStore;
use LambdaTwelve\OneRecord\Vocabulary\Generated\Cargo;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * A store can be replaced on its own; the others follow the container.
 *
 * The README promises that each store service stands alone. The
 * subscription store derives subscribers from accepted requests, so it is
 * the one that could quietly keep reading the module's table after a site
 * moved its action requests elsewhere (adversarial review AR2-004).
 */
#[Group('one_record')]
#[RunTestsInSeparateProcesses]
final class StoreReplacementTest extends OneRecordKernelTestBase {

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    // A site keeping its action requests elsewhere: here, in memory.
    $container->getDefinition('one_record.store.action_requests')
      ->setClass(InMemoryActionRequestStore::class)
      ->setArguments([]);
  }

  /**
   * Tests that subscribers come from the configured request store.
   */
  public function testSubscribersFollowTheConfiguredRequestStore(): void {
    $requests = $this->container->get(ActionRequestStore::class);
    self::assertInstanceOf(InMemoryActionRequestStore::class, $requests, 'Only the request store was replaced');
    $holder = $this->container->get('one_record.data_holder');
    self::assertInstanceOf(DataHolder::class, $holder);
    $piece = $this->piece();
    $holder->create($piece);
    $holder->subscribe(new Subscription(new Iri(self::PARTNER), TopicType::Identifier, $piece->iri->value, [SubscriptionEventType::LogisticsObjectUpdated]));

    $subscriptions = $this->container->get(SubscriptionStore::class);
    self::assertInstanceOf(SubscriptionStore::class, $subscriptions);
    $subscribers = $subscriptions->subscribersOf($piece->iri, [Cargo::Piece], $this->clock->now());
    self::assertCount(1, $subscribers, 'The default subscription store reads the replaced request store');
    self::assertSame(self::PARTNER, $subscribers[0]['subscription']->subscriber->value);
    self::assertSame(0, (int) $this->container->get('database')->select('one_record_action_requests')->countQuery()->execute()?->fetchField(), 'Nothing was written to the module\'s table');
  }

}
