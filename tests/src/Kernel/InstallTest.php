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

    $this->container->get('module_handler')->loadInclude('one_record', 'install');
    $requirement = \one_record_requirements('runtime')['one_record'];
    self::assertSame(REQUIREMENT_WARNING, $requirement['severity'], 'The status report says so too');
    self::assertStringContainsString('The base URL is not set.', (string) $requirement['description'], 'In the SDK\'s words');
    self::assertStringContainsString('The data holder is not set', (string) $requirement['description']);
    self::assertSame(['The base URL is not set.', 'The data holder is not set: the IRI of the organisation this server speaks for.'], $config->problems());
    self::assertSame([], \one_record_requirements('install'));

    $schema = $this->container->get('database')->schema();
    foreach (array_keys(\one_record_schema()) as $table) {
      self::assertFalse($schema->tableExists($table), 'Kernel tests install schema explicitly');
    }
    $this->installSchema('one_record', ['one_record_logistics_objects']);
    self::assertTrue($schema->tableExists('one_record_logistics_objects'));
  }

}
