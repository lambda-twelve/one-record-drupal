<?php

declare(strict_types=1);

namespace Drupal\Tests\one_record\Kernel\Store;

use Drupal\Tests\one_record\Support\FixedClock;
use Drupal\Tests\one_record\Support\Stores;
use LambdaTwelve\OneRecord\Server\InMemory\InMemoryState;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The SDK's in-memory stores pass the contract: it describes their semantics.
 */
#[Group('one_record')]
#[RunTestsInSeparateProcesses]
final class InMemoryStoresTest extends StoreContractTestBase {

  /**
   * The in-memory stores.
   */
  private InMemoryState $state;

  /**
   * {@inheritdoc}
   */
  protected function makeStores(FixedClock $clock): Stores {
    $this->state = new InMemoryState($clock);
    return new Stores($this->state->objects, $this->state->events, $this->state->actionRequests, $this->state->subscriptions, $this->state->delegations, $this->state->outbox);
  }

  /**
   * {@inheritdoc}
   */
  protected function outboxContents(): array {
    return $this->state->outbox->all();
  }

}
