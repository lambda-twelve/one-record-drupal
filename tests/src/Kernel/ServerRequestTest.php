<?php

declare(strict_types=1);

namespace Drupal\Tests\one_record\Kernel;

use Drupal\one_record\Config\OneRecordConfig;
use Drupal\one_record\Hook\RequirementsHooks;
use Drupal\one_record\Notification\QueuedOutbox;
use Drupal\one_record\Store\DatabaseNotificationOutbox;
use LambdaTwelve\OneRecord\Api\Permission;
use LambdaTwelve\OneRecord\Api\Subscription;
use LambdaTwelve\OneRecord\Api\SubscriptionEventType;
use LambdaTwelve\OneRecord\Api\TopicType;
use LambdaTwelve\OneRecord\Change\ChangeBuilder;
use LambdaTwelve\OneRecord\Rdf\Iri;
use LambdaTwelve\OneRecord\Server\DataHolder;
use LambdaTwelve\OneRecord\Server\Event\LogisticsObjectCreated;
use LambdaTwelve\OneRecord\Server\GrantAccessPolicy;
use LambdaTwelve\OneRecord\Vocabulary\Generated\Cargo;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Drupal request in, SDK processing, Drupal response out.
 */
#[Group('one_record')]
#[RunTestsInSeparateProcesses]
final class ServerRequestTest extends OneRecordKernelTestBase {

  /**
   * Tests the server information endpoint through Drupal's routing.
   */
  public function testServerInformation(): void {
    $response = $this->request('GET', '/one-record');
    self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    self::assertStringStartsWith('application/ld+json; version=2.3.0', (string) $response->headers->get('Content-Type'));
    $body = self::json($response);
    self::assertSame('api:ServerInformation', $body['@type']);
    self::assertSame(self::BASE . '/one-record', $body['api:hasServerEndpoint']['@id'] ?? $body['api:hasServerEndpoint']);
    self::assertSame('no-store, private', $response->headers->get('Cache-Control'), 'Never cached, and said explicitly so core leaves the validators alone');

    $hooks = $this->container->get(RequirementsHooks::class);
    self::assertInstanceOf(RequirementsHooks::class, $hooks, 'Registered as an OOP hook class');
    $requirements = $hooks->runtimeRequirements();
    self::assertSame(REQUIREMENT_OK, $requirements['one_record']['severity'], 'The SDK finds nothing amiss in the wiring: the unit of work is bound');
    self::assertNull($requirements['one_record']['description']);
    $this->container->get('module_handler')->loadInclude('one_record', 'install');
    self::assertEquals($requirements, \one_record_requirements('runtime'), 'The legacy hook for older Drupal says the same');
  }

  /**
   * Tests that stored settings the SDK rejects answer 503, not an exception.
   *
   * The form refuses such values; a configuration import does not
   * (readiness review B6-001).
   */
  public function testRejectedStoredSettingsAnswer503(): void {
    $this->config(OneRecordConfig::NAME)->set('max_body_bytes', 0)->save();
    $this->container->get('kernel')->rebuildContainer();
    $hooks = $this->container->get(RequirementsHooks::class);
    self::assertInstanceOf(RequirementsHooks::class, $hooks);
    $requirement = $hooks->runtimeRequirements()['one_record'];
    self::assertSame(REQUIREMENT_ERROR, $requirement['severity'], 'Set but rejected is an error, not the unconfigured warning');
    self::assertStringContainsString('positive number of bytes', (string) $requirement['description']);

    $response = $this->request('GET', '/one-record', agent: NULL);
    self::assertSame(503, $response->getStatusCode(), (string) $response->getContent());
    self::assertStringContainsString('rejected', (string) $response->getContent());
    self::assertStringNotContainsString('bytes', (string) $response->getContent(), 'The problem itself stays off the wire');
  }

  /**
   * Tests that a malformed stored holder IRI does not break construction.
   *
   * The access policy is built with the container and lists the holder as
   * an internal agent; a value that is not an IRI must not throw there,
   * before the 503 handler could be chosen (readiness recheck of B6-001).
   */
  public function testMalformedStoredHolderAnswers503(): void {
    $this->config(OneRecordConfig::NAME)->set('data_holder', 'https://holder.example/invalid value')->save();
    $this->container->get('kernel')->rebuildContainer();
    $config = $this->container->get('one_record.config');
    self::assertInstanceOf(OneRecordConfig::class, $config);
    self::assertSame([], $config->internalAgents(), 'A holder that is not an IRI is nobody to grant to');
    self::assertStringContainsString('not a valid IRI', implode(' ', $config->problems()));

    $hooks = $this->container->get(RequirementsHooks::class);
    self::assertInstanceOf(RequirementsHooks::class, $hooks);
    self::assertSame(REQUIREMENT_ERROR, $hooks->runtimeRequirements()['one_record']['severity']);
    $this->container->get('module_handler')->loadInclude('one_record', 'install');
    self::assertSame(REQUIREMENT_ERROR, \one_record_requirements('runtime')['one_record']['severity'], 'The legacy hook builds too');

    $response = $this->request('GET', '/one-record', agent: NULL);
    self::assertSame(503, $response->getStatusCode(), (string) $response->getContent());
    self::assertStringContainsString('rejected', (string) $response->getContent());
  }

  /**
   * Tests that the SDK answers unauthenticated and unknown requests.
   */
  public function testErrorsComeFromTheSdk(): void {
    $response = $this->request('GET', '/one-record', agent: NULL);
    self::assertSame(401, $response->getStatusCode());
    self::assertSame('api:Error', self::json($response)['@type']);

    $response = $this->request('GET', '/one-record/logistics-objects/nothing');
    self::assertSame(404, $response->getStatusCode());
    self::assertSame('api:Error', self::json($response)['@type']);

    $holder = $this->container->get('one_record.data_holder');
    self::assertInstanceOf(DataHolder::class, $holder);
    $holder->create($this->piece());
    $response = $this->request('GET', '/one-record/logistics-objects/piece-1');
    self::assertSame(403, $response->getStatusCode(), 'Denied without a grant');
    self::assertSame('api:Error', self::json($response)['@type']);

    $response = $this->request('DELETE', '/one-record/logistics-objects');
    self::assertSame(405, $response->getStatusCode());
    self::assertSame('POST', $response->headers->get('Allow'), 'The SDK, not Drupal, answers 405');
    self::assertSame('api:Error', self::json($response)['@type']);
  }

  /**
   * Tests creating an object as the holder and reading it as a partner.
   */
  public function testCreateGrantAndRead(): void {
    $piece = $this->piece();
    $response = $this->request('POST', '/one-record/logistics-objects', self::HOLDER, $piece->toJson());
    self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
    $location = (string) $response->headers->get('Location');
    self::assertStringStartsWith(self::BASE . '/one-record/logistics-objects/', $location);
    $iri = new Iri($location);
    $path = substr($location, strlen(self::BASE));

    self::assertSame(403, $this->request('GET', $path)->getStatusCode());

    $policy = $this->container->get('one_record.access_policy');
    self::assertInstanceOf(GrantAccessPolicy::class, $policy);
    $policy->allow(new Iri(self::PARTNER), $iri, [Permission::GetLogisticsObject]);

    $response = $this->request('GET', $path, headers: ['Accept-Language' => 'en-US']);
    self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    self::assertSame('1', $response->headers->get('Revision'));
    self::assertSame(Cargo::Piece, $response->headers->get('Type'));
    self::assertSame('Fri, 02 Oct 2026 12:00:00 GMT', $response->headers->get('Last-Modified'), 'The SDK\'s validator survives core\'s response pass');
    self::assertSame('en-US', $response->headers->get('Content-Language'), 'The negotiated language, not Drupal\'s page language');
    self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
    $body = self::json($response);
    self::assertSame($location, $body['@id']);
    self::assertSame('Books', $body['cargo:goodsDescription']);

    $head = $this->request('HEAD', $path, headers: ['Accept-Language' => 'en-US']);
    self::assertSame(200, $head->getStatusCode());
    self::assertSame('', (string) $head->getContent());
    self::assertSame('Fri, 02 Oct 2026 12:00:00 GMT', $head->headers->get('Last-Modified'));
    self::assertSame('en-US', $head->headers->get('Content-Language'));

    $response = $this->request('GET', $path . '?at=' . rawurlencode('2000-01-01T00:00:00Z'));
    self::assertSame(404, $response->getStatusCode(), 'No revision existed then');
  }

  /**
   * Tests a change request accepted by the holder and the resulting fan-out.
   */
  public function testChangeNotifiesSubscribers(): void {
    $holder = $this->container->get('one_record.data_holder');
    self::assertInstanceOf(DataHolder::class, $holder);
    $piece = $this->piece();
    $events = [];
    $this->container->get('event_dispatcher')->addListener(LogisticsObjectCreated::class, static function (LogisticsObjectCreated $event) use (&$events): void {
      $events[] = $event;
    });
    $stored = $holder->create($piece);
    self::assertSame(1, $stored->revision);
    self::assertCount(1, $events, 'SDK events reach Drupal listeners under their class name');

    $holder->subscribe(new Subscription(new Iri(self::PARTNER), TopicType::Identifier, $piece->iri->value, [SubscriptionEventType::LogisticsObjectUpdated]));
    $policy = $this->container->get('one_record.access_policy');
    self::assertInstanceOf(GrantAccessPolicy::class, $policy);
    $policy->allow(new Iri(self::PARTNER), $piece->iri, [Permission::GetLogisticsObject, Permission::PatchLogisticsObject]);

    // Diff against the stored revision: it carries the embedded node ids.
    $change = (new ChangeBuilder())->diff($stored->object, $this->piece(weight: 25.0), 1, 'Reweighed');
    self::assertNotNull($change);
    $path = substr($piece->iri->value, strlen(self::BASE));
    $response = $this->request('PATCH', $path, self::PARTNER, $change->toJson());
    self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
    $requestIri = (string) $response->headers->get('Location');

    $accepted = $holder->accept(new Iri($requestIri));
    self::assertSame('REQUEST_ACCEPTED', $accepted->status->shortName(), implode('; ', array_map(static fn($e): string => $e->title, $accepted->errors)));
    $response = $this->request('GET', $path);
    self::assertSame('2', $response->headers->get('Revision'));

    $outbox = $this->container->get('one_record.store.outbox');
    self::assertInstanceOf(DatabaseNotificationOutbox::class, $outbox);
    $due = $outbox->due($this->clock->now()->modify('+1 day'));
    self::assertCount(1, $due, 'The accepted change fanned out to the subscriber');
    $pending = $outbox->find($due[0]);
    self::assertNotNull($pending);
    self::assertSame(self::PARTNER, $pending->recipient->value);
    self::assertSame('https://1r.partner.example/notifications', $pending->endpoint);
    self::assertSame('LogisticsObjectUpdated', $pending->notification->eventType->name);
    self::assertSame(1, $this->container->get('queue')->get(QueuedOutbox::QUEUE)->numberOfItems(), 'A delivery job was queued once the transaction committed');

    $trail = $this->request('GET', $path . '/audit-trail');
    self::assertSame(200, $trail->getStatusCode());
    self::assertSame('2', self::json($trail)['api:hasLatestRevision']['@value']);
  }

}
