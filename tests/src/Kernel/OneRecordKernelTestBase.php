<?php

declare(strict_types=1);

namespace Drupal\Tests\one_record\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\KernelTests\KernelTestBase;
use Drupal\one_record\Config\OneRecordConfig;
use Drupal\Tests\one_record\Support\MockHttp;
use GuzzleHttp\Client;
use LambdaTwelve\OneRecord\Model\Builder\ObjectBuilder;
use LambdaTwelve\OneRecord\Model\Builder\Values;
use LambdaTwelve\OneRecord\Model\LogisticsObject;
use LambdaTwelve\OneRecord\Rdf\Iri;
use LambdaTwelve\OneRecord\Testing\FixedClock;
use LambdaTwelve\OneRecord\Testing\HeaderAuthenticator;
use LambdaTwelve\OneRecord\Vocabulary\Generated\Cargo;
use LambdaTwelve\OneRecord\Vocabulary\Generated\CodeLists\MeasurementUnitCode;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A configured ONE Record server inside a kernel test.
 *
 * Only two services are swapped, both for the SDK's test doubles: the
 * authenticator trusts a test header and the clock stands still. Everything
 * else is the module as installed.
 */
abstract class OneRecordKernelTestBase extends KernelTestBase {

  protected const BASE = 'https://1r.example.com';
  protected const HOLDER = 'https://1r.example.com/one-record/logistics-objects/holder';
  protected const PARTNER = 'https://1r.partner.example/logistics-objects/partner';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'one_record'];

  /**
   * The clock the server reads.
   */
  protected FixedClock $clock;

  /**
   * Whether to trust the X-Test-Agent header instead of bearer tokens.
   */
  protected bool $headerAuthentication = TRUE;

  /**
   * Whether to script outgoing HTTP through MockHttp.
   */
  protected bool $mockHttp = FALSE;

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $this->clock = new FixedClock();
    $container->getDefinition('one_record.clock')->setClass(FixedClock::class)->setArguments([])->setSynthetic(TRUE);
    $container->set('one_record.clock', $this->clock);
    if ($this->headerAuthentication) {
      $container->getDefinition('one_record.authenticator')->setClass(HeaderAuthenticator::class)->setFactory(NULL)->setArguments([]);
    }
    if ($this->mockHttp) {
      $container->register('http_client', Client::class)->setFactory([MockHttp::class, 'client']);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['one_record']);
    $this->installSchema('one_record', [
      'one_record_logistics_objects',
      'one_record_logistics_object_revisions',
      'one_record_logistics_events',
      'one_record_action_requests',
      'one_record_action_request_objects',
      'one_record_subscription_offers',
      'one_record_grants',
      'one_record_outbox',
      'one_record_clients',
    ]);
    $this->config(OneRecordConfig::NAME)
      ->set('base_url', self::BASE)
      ->set('base_path', '/one-record')
      ->set('data_holder', self::HOLDER)
      ->save();
  }

  /**
   * Sends a request through Drupal's HTTP kernel.
   *
   * @param string $method
   *   The HTTP method.
   * @param string $path
   *   The path, including the base path.
   * @param string|null $agent
   *   The agent IRI to authenticate as, or NULL for no credentials.
   * @param string|null $body
   *   A JSON-LD body, if any.
   * @param array<string, string> $headers
   *   Extra headers.
   */
  protected function request(string $method, string $path, ?string $agent = self::PARTNER, ?string $body = NULL, array $headers = []): Response {
    $server = ['HTTP_ACCEPT' => 'application/ld+json; version=2.3.0'];
    if ($agent !== NULL) {
      $server['HTTP_X_TEST_AGENT'] = $agent;
    }
    if ($body !== NULL) {
      $server['CONTENT_TYPE'] = 'application/ld+json; version=2.3.0';
    }
    foreach ($headers as $name => $value) {
      $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
    }
    $request = Request::create(self::BASE . $path, $method, [], [], [], $server, $body);
    $kernel = $this->container->get('http_kernel');
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    return $response;
  }

  /**
   * The decoded JSON body of a response.
   *
   * @return array<string, mixed>
   *   The document.
   */
  protected static function json(Response $response): array {
    $decoded = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    self::assertIsArray($decoded);
    return $decoded;
  }

  /**
   * A cargo:Piece served by this host.
   */
  protected function piece(string $id = 'piece-1', float $weight = 20.0): LogisticsObject {
    return ObjectBuilder::of(Cargo::Piece)
      ->set(Cargo::goodsDescription, 'Books')
      ->set(Cargo::coload, FALSE)
      ->set(Cargo::grossWeight, Values::quantity($weight, MeasurementUnitCode::KGM))
      ->build(new Iri(self::BASE . '/one-record/logistics-objects/' . $id));
  }

}
