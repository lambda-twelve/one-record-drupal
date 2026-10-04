<?php

declare(strict_types=1);

namespace Drupal\Tests\one_record\Kernel\Store\SdkContract;

use Drupal\one_record\Store\DatabaseLogisticsObjectStore;
use LambdaTwelve\OneRecord\Server\Spi\LogisticsObjectStore;
use LambdaTwelve\OneRecord\Testing\Contract\LogisticsObjectStoreContractTests;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The database object store passes the SDK's LogisticsObjectStore contract.
 */
#[Group('one_record')]
#[RunTestsInSeparateProcesses]
final class LogisticsObjectStoreContractTest extends SdkContractTestBase {

  use LogisticsObjectStoreContractTests;

  /**
   * {@inheritdoc}
   */
  protected function createStore(): LogisticsObjectStore {
    return new DatabaseLogisticsObjectStore($this->connection());
  }

}
