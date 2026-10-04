<?php

declare(strict_types=1);

namespace Drupal\Tests\one_record\Kernel\Store\SdkContract;

use Drupal\one_record\Store\DatabaseAccessDelegationStore;
use LambdaTwelve\OneRecord\Server\Spi\AccessDelegationStore;
use LambdaTwelve\OneRecord\Testing\Contract\AccessDelegationStoreContractTests;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The database grant store passes the SDK's AccessDelegationStore contract.
 */
#[Group('one_record')]
#[RunTestsInSeparateProcesses]
final class AccessDelegationStoreContractTest extends SdkContractTestBase {

  use AccessDelegationStoreContractTests;

  /**
   * {@inheritdoc}
   */
  protected function createStore(): AccessDelegationStore {
    return new DatabaseAccessDelegationStore($this->connection(), $this->clock);
  }

}
