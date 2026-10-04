<?php

declare(strict_types=1);

namespace Drupal\Tests\one_record\Kernel\Store\SdkContract;

use Drupal\one_record\Store\DatabaseActionRequestStore;
use Drupal\one_record\Store\DatabaseSubscriptionStore;
use LambdaTwelve\OneRecord\Testing\Contract\SubscriptionStoreContractTests;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The database subscription store passes the SDK's SubscriptionStore contract.
 */
#[Group('one_record')]
#[RunTestsInSeparateProcesses]
final class SubscriptionStoreContractTest extends SdkContractTestBase {

  use SubscriptionStoreContractTests;

  /**
   * {@inheritdoc}
   */
  protected function createStores(): array {
    $connection = $this->connection();
    $requests = new DatabaseActionRequestStore($connection, $this->clock);
    return [
      'requests' => $requests,
      'subscriptions' => new DatabaseSubscriptionStore($connection, $requests, $this->clock),
    ];
  }

}
