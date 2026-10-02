<?php

declare(strict_types=1);

namespace Drupal\Tests\one_record\Support;

use Drupal\one_record\Store\DatabaseSubscriptionStore;
use LambdaTwelve\OneRecord\Server\InMemory\InMemorySubscriptionStore;
use LambdaTwelve\OneRecord\Server\Spi\AccessDelegationStore;
use LambdaTwelve\OneRecord\Server\Spi\ActionRequestStore;
use LambdaTwelve\OneRecord\Server\Spi\LogisticsEventStore;
use LambdaTwelve\OneRecord\Server\Spi\LogisticsObjectStore;
use LambdaTwelve\OneRecord\Server\Spi\NotificationOutbox;

/**
 * One complete set of SPI implementations under test.
 */
final class Stores {

  public function __construct(
    public readonly LogisticsObjectStore $objects,
    public readonly LogisticsEventStore $events,
    public readonly ActionRequestStore $actionRequests,
    public readonly InMemorySubscriptionStore|DatabaseSubscriptionStore $subscriptions,
    public readonly AccessDelegationStore $delegations,
    public readonly NotificationOutbox $outbox,
  ) {}

}
