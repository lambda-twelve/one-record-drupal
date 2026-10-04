<?php

declare(strict_types=1);

namespace Drupal\Tests\one_record\Kernel\Store\SdkContract;

use Drupal\Core\Database\Connection;
use Drupal\KernelTests\KernelTestBase;
use LambdaTwelve\OneRecord\Testing\FixedClock;

/**
 * Runs one of the SDK's store contracts against a database store.
 *
 * The SDK ships every store contract as a trait for hosts whose test cases
 * must extend a framework base class; a kernel test is one. Each subclass
 * uses one trait and builds the store it proves from the test database. The
 * module's own StoreContractTestBase adds what this module promises on top
 * (byte-exact IRIs, the outbox row model); these are the SDK's words.
 */
abstract class SdkContractTestBase extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['one_record'];

  /**
   * The clock the stores read.
   */
  protected FixedClock $clock;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->clock = new FixedClock();
    $this->installSchema('one_record', [
      'one_record_logistics_objects',
      'one_record_logistics_object_revisions',
      'one_record_logistics_events',
      'one_record_action_requests',
      'one_record_action_request_objects',
      'one_record_subscription_offers',
      'one_record_grants',
      'one_record_outbox',
    ]);
  }

  /**
   * The test database.
   */
  protected function connection(): Connection {
    $connection = $this->container->get('database');
    self::assertInstanceOf(Connection::class, $connection);
    return $connection;
  }

}
