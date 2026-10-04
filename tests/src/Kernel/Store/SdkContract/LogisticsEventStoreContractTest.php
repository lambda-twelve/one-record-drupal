<?php

declare(strict_types=1);

namespace Drupal\Tests\one_record\Kernel\Store\SdkContract;

use Drupal\one_record\Store\DatabaseLogisticsEventStore;
use LambdaTwelve\OneRecord\Server\Spi\LogisticsEventStore;
use LambdaTwelve\OneRecord\Testing\Contract\LogisticsEventStoreContractTests;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The database event store passes the SDK's LogisticsEventStore contract.
 */
#[Group('one_record')]
#[RunTestsInSeparateProcesses]
final class LogisticsEventStoreContractTest extends SdkContractTestBase {

  use LogisticsEventStoreContractTests;

  /**
   * {@inheritdoc}
   */
  protected function createStore(): LogisticsEventStore {
    return new DatabaseLogisticsEventStore($this->connection());
  }

}
