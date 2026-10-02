<?php

declare(strict_types=1);

namespace Drupal\Tests\one_record\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\one_record\Config\OneRecordConfig;
use Drupal\one_record\Server\UnconfiguredHandler;
use GuzzleHttp\Psr7\ServerRequest;
use LambdaTwelve\OneRecord\Server\Spi\LogisticsObjectStore;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Installing the module gives a complete, if unconfigured, server.
 */
#[Group('one_record')]
#[RunTestsInSeparateProcesses]
final class InstallTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['one_record'];

  /**
   * Tests default configuration, container wiring and the unconfigured state.
   */
  public function testInstall(): void {
    $this->installConfig(['one_record']);
    $config = $this->container->get('one_record.config');
    self::assertInstanceOf(OneRecordConfig::class, $config);
    self::assertFalse($config->isConfigured());
    self::assertSame('/one-record', $config->basePath());
    self::assertSame([], $config->issuers());

    self::assertInstanceOf(LogisticsObjectStore::class, $this->container->get(LogisticsObjectStore::class), 'SDK interfaces are container aliases');

    $handler = $this->container->get('one_record.handler');
    self::assertInstanceOf(UnconfiguredHandler::class, $handler);
    $response = $handler->handle(new ServerRequest('GET', 'https://1r.example.com/one-record'));
    self::assertSame(503, $response->getStatusCode());
    self::assertStringContainsString('not configured', (string) $response->getBody());

    $schema = $this->container->get('database')->schema();
    $this->container->get('module_handler')->loadInclude('one_record', 'install');
    foreach (array_keys(\one_record_schema()) as $table) {
      self::assertFalse($schema->tableExists($table), 'Kernel tests install schema explicitly');
    }
    $this->installSchema('one_record', ['one_record_logistics_objects']);
    self::assertTrue($schema->tableExists('one_record_logistics_objects'));
  }

}
