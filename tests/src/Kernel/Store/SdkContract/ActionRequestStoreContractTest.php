<?php

declare(strict_types=1);

namespace Drupal\Tests\one_record\Kernel\Store\SdkContract;

use Drupal\one_record\Store\DatabaseActionRequestStore;
use LambdaTwelve\OneRecord\Server\Spi\ActionRequestStore;
use LambdaTwelve\OneRecord\Testing\Contract\ActionRequestStoreContractTests;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The database request store passes the SDK's ActionRequestStore contract.
 */
#[Group('one_record')]
#[RunTestsInSeparateProcesses]
final class ActionRequestStoreContractTest extends SdkContractTestBase {

  use ActionRequestStoreContractTests;

  /**
   * {@inheritdoc}
   */
  protected function createStore(): ActionRequestStore {
    return new DatabaseActionRequestStore($this->connection(), $this->clock);
  }

}
