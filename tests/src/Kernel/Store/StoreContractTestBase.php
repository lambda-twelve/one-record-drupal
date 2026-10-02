<?php

declare(strict_types=1);

namespace Drupal\Tests\one_record\Kernel\Store;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\one_record\Support\FixedClock;
use Drupal\Tests\one_record\Support\Stores;
use LambdaTwelve\OneRecord\Api\AccessDelegation;
use LambdaTwelve\OneRecord\Api\ActionRequest;
use LambdaTwelve\OneRecord\Api\ActionRequestType;
use LambdaTwelve\OneRecord\Api\Error;
use LambdaTwelve\OneRecord\Api\Notification;
use LambdaTwelve\OneRecord\Api\NotificationEventType;
use LambdaTwelve\OneRecord\Api\Permission;
use LambdaTwelve\OneRecord\Api\RequestStatus;
use LambdaTwelve\OneRecord\Api\Subscription;
use LambdaTwelve\OneRecord\Api\SubscriptionEventType;
use LambdaTwelve\OneRecord\Api\TopicType;
use LambdaTwelve\OneRecord\Api\Verification;
use LambdaTwelve\OneRecord\Change\ChangeBuilder;
use LambdaTwelve\OneRecord\Model\Builder\ObjectBuilder;
use LambdaTwelve\OneRecord\Model\Builder\Values;
use LambdaTwelve\OneRecord\Model\LogisticsEvent;
use LambdaTwelve\OneRecord\Model\LogisticsObject;
use LambdaTwelve\OneRecord\Model\Uuid5EmbeddedIdMinter;
use LambdaTwelve\OneRecord\Rdf\Graph;
use LambdaTwelve\OneRecord\Rdf\Iri;
use LambdaTwelve\OneRecord\Server\Spi\AuditTrailQuery;
use LambdaTwelve\OneRecord\Server\Spi\EventQuery;
use LambdaTwelve\OneRecord\Server\Spi\Grant;
use LambdaTwelve\OneRecord\Server\Spi\OutboundNotification;
use LambdaTwelve\OneRecord\Server\Spi\StoreException;
use LambdaTwelve\OneRecord\Spec\ApiVersion;
use LambdaTwelve\OneRecord\Vocabulary\Generated\Cargo;
use LambdaTwelve\OneRecord\Vocabulary\Generated\CodeLists\MeasurementUnitCode;

/**
 * The behaviour every set of SPI stores must show.
 *
 * The same assertions run against the SDK's in-memory stores (the reference)
 * and against the database stores, so the two cannot drift apart.
 */
abstract class StoreContractTestBase extends KernelTestBase {

  protected const BASE = 'https://1r.example.com';
  protected const HOLDER = 'https://1r.example.com/logistics-objects/holder';
  protected const PARTNER = 'https://1r.partner.example/logistics-objects/partner';
  protected const OTHER = 'https://1r.other.example/logistics-objects/other';

  /**
   * The clock the stores read.
   */
  protected FixedClock $clock;

  /**
   * The stores under test.
   */
  protected Stores $stores;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->clock = new FixedClock();
    $this->stores = $this->makeStores($this->clock);
  }

  /**
   * The stores under test.
   */
  abstract protected function makeStores(FixedClock $clock): Stores;

  /**
   * The IRI of a logistics object served by this host.
   */
  protected function iri(string $id): Iri {
    return new Iri(self::BASE . '/logistics-objects/' . $id);
  }

  /**
   * A cargo:Piece with embedded ids, as the server stores it.
   */
  protected function piece(string $id = 'piece-1', ?float $weight = 20.0, string $description = 'Books'): LogisticsObject {
    $builder = ObjectBuilder::of(Cargo::Piece)->set(Cargo::goodsDescription, $description)->set(Cargo::coload, FALSE);
    if ($weight !== NULL) {
      $builder->set(Cargo::grossWeight, Values::quantity($weight, MeasurementUnitCode::KGM));
    }
    return $builder->build($this->iri($id))->withEmbeddedIds(new Uuid5EmbeddedIdMinter());
  }

  /**
   * A logistics event about an object.
   *
   * @param string $id
   *   The last segment of the event IRI.
   * @param string $objectId
   *   The last segment of the object IRI.
   * @param string $created
   *   When the server received the event.
   * @param string|null $eventDate
   *   The cargo:eventDate, or NULL for an event without one.
   * @param string|array<string, mixed>|null $code
   *   A StatusCode fragment, a JSON-LD value for cargo:eventCode, or none.
   * @param string|null $creationDate
   *   The cargo:creationDate the sender claims, if any.
   */
  protected function event(string $id, string $objectId, string $created, ?string $eventDate, string|array|null $code = 'DEP', ?string $creationDate = NULL): LogisticsEvent {
    $document = [
      '@context' => ['cargo' => Cargo::NAMESPACE],
      '@id' => self::BASE . '/logistics-objects/' . $objectId . '/logistics-events/' . $id,
      '@type' => 'cargo:LogisticsEvent',
      'cargo:eventName' => 'Event ' . $id,
      'cargo:eventTimeType' => ['@id' => 'cargo:ACTUAL'],
    ];
    if ($eventDate !== NULL) {
      $document['cargo:eventDate'] = self::dateTime($eventDate);
    }
    if ($creationDate !== NULL) {
      $document['cargo:creationDate'] = self::dateTime($creationDate);
    }
    if (is_string($code)) {
      $document['cargo:eventCode'] = ['@id' => 'https://onerecord.iata.org/ns/code-lists/StatusCode#' . $code];
    }
    elseif (is_array($code)) {
      $document['cargo:eventCode'] = $code;
    }
    $iri = new Iri($document['@id']);
    if ($eventDate === NULL) {
      // The validating factory insists on an event date; stores must still cope.
      $withDate = $document + ['cargo:eventDate' => self::dateTime('2000-01-01T00:00:00Z')];
      $valid = LogisticsEvent::fromJsonLd(json_encode($withDate, JSON_THROW_ON_ERROR), $iri, $this->iri($objectId), new \DateTimeImmutable($created));
      $graph = new Graph();
      foreach ($valid->graph as $triple) {
        if ($triple->predicate->value !== Cargo::eventDate) {
          $graph->add($triple);
        }
      }
      return new LogisticsEvent($iri, $this->iri($objectId), $graph, new \DateTimeImmutable($created));
    }
    return LogisticsEvent::fromJsonLd(json_encode($document, JSON_THROW_ON_ERROR), $iri, $this->iri($objectId), new \DateTimeImmutable($created));
  }

  /**
   * A JSON-LD xsd:dateTime value.
   *
   * @return array<string, string>
   *   The typed value.
   */
  protected static function dateTime(string $value): array {
    return ['@type' => 'http://www.w3.org/2001/XMLSchema#dateTime', '@value' => $value];
  }

  /**
   * A pending action request.
   */
  protected function request(string $id, mixed $payload, string $requestedBy = self::PARTNER, ?string $at = NULL): ActionRequest {
    return ActionRequest::create(new Iri(self::BASE . '/action-requests/' . $id), $payload, new Iri($requestedBy), $at === NULL ? $this->clock->now() : new \DateTimeImmutable($at));
  }

  /**
   * A pending change request that makes a piece heavier.
   */
  protected function changeRequest(string $id, string $objectId = 'piece-1', int $revision = 1, ?string $at = NULL): ActionRequest {
    $change = (new ChangeBuilder())->diff($this->piece($objectId), $this->piece($objectId, 25.0), $revision, 'Heavier');
    self::assertNotNull($change);
    return $this->request($id, $change, at: $at);
  }

  /**
   * A subscription to updates and events.
   */
  protected function subscription(string $topic, TopicType $type = TopicType::Identifier, ?string $expires = NULL, string $subscriber = self::PARTNER): Subscription {
    return new Subscription(
      new Iri($subscriber),
      $type,
      $topic,
      [SubscriptionEventType::LogisticsObjectUpdated, SubscriptionEventType::LogisticsEventReceived],
      sendLogisticsObjectBody: TRUE,
      description: 'Test subscription',
      expiresAt: $expires === NULL ? NULL : new \DateTimeImmutable($expires),
    );
  }

  /**
   * The local names of action requests, in order.
   *
   * @param list<\LambdaTwelve\OneRecord\Api\ActionRequest> $requests
   *   The requests.
   *
   * @return list<string>
   *   The last path segment of each IRI.
   */
  protected static function names(array $requests): array {
    return array_values(array_map(static fn(ActionRequest $r): string => $r->iri->localName(), $requests));
  }

  /**
   * Tests object revisions and metadata.
   */
  public function testObjectRevisionsAndMetadata(): void {
    $piece = $this->piece();
    $t1 = $this->clock->now();
    $stored = $this->stores->objects->create($piece, $t1);
    self::assertSame(1, $stored->revision);
    self::assertSame(1, $stored->latestRevision);
    self::assertEquals($t1, $stored->createdAt);
    self::assertEquals($t1, $stored->lastModified);
    self::assertTrue($this->stores->objects->exists($piece->iri));
    self::assertFalse($this->stores->objects->exists($this->iri('missing')));

    $this->clock->advance('+1 hour');
    $t2 = $this->clock->now();
    $second = $this->stores->objects->saveRevision($this->piece(weight: 25.0), 1, $t2);
    self::assertSame(2, $second->revision);
    self::assertSame(2, $second->latestRevision);
    self::assertEquals($t1, $second->createdAt);
    self::assertEquals($t2, $second->lastModified);

    $latest = $this->stores->objects->latest($piece->iri);
    self::assertNotNull($latest);
    self::assertSame(2, $latest->revision);
    self::assertTrue($latest->isLatest());
    self::assertTrue($latest->object->isSameAs($this->piece(weight: 25.0)));

    $first = $this->stores->objects->revision($piece->iri, 1);
    self::assertNotNull($first);
    self::assertSame(1, $first->revision);
    self::assertSame(2, $first->latestRevision);
    self::assertFalse($first->isLatest());
    self::assertEquals($t1, $first->lastModified);
    self::assertTrue($first->object->isSameAs($piece), 'Embedded ids and values survive the round trip');
    self::assertSame($piece->toJson(), $first->object->toJson());

    self::assertNull($this->stores->objects->revision($piece->iri, 0));
    self::assertNull($this->stores->objects->revision($piece->iri, 3));
    self::assertNull($this->stores->objects->latest($this->iri('missing')));
  }

  /**
   * Tests object at instant.
   */
  public function testObjectAtInstant(): void {
    $piece = $this->piece();
    $t1 = new \DateTimeImmutable('2026-10-02T12:00:00.000Z');
    $t2 = new \DateTimeImmutable('2026-10-02T13:00:00.000Z');
    $this->stores->objects->create($piece, $t1);
    $this->stores->objects->saveRevision($this->piece(weight: 25.0), 1, $t2);

    self::assertNull($this->stores->objects->at($piece->iri, $t1->modify('-1 second')));
    self::assertSame(1, $this->stores->objects->at($piece->iri, $t1)?->revision, 'Exact instant counts');
    self::assertSame(1, $this->stores->objects->at($piece->iri, $t2->modify('-1 millisecond'))?->revision);
    self::assertSame(2, $this->stores->objects->at($piece->iri, $t2)?->revision);
    self::assertSame(2, $this->stores->objects->at($piece->iri, $t2->modify('+1 year'))?->revision);
    self::assertNull($this->stores->objects->at($this->iri('missing'), $t2));
  }

  /**
   * Tests object conflicts.
   */
  public function testObjectConflicts(): void {
    $piece = $this->piece();
    $this->stores->objects->create($piece, $this->clock->now());
    try {
      $this->stores->objects->create($piece, $this->clock->now());
      self::fail('Expected ALREADY_EXISTS');
    }
    catch (StoreException $e) {
      self::assertSame(StoreException::ALREADY_EXISTS, $e->kind);
    }
    self::assertSame(1, $this->stores->objects->latest($piece->iri)?->latestRevision, 'A refused create leaves the object alone');

    try {
      $this->stores->objects->saveRevision($piece, 2, $this->clock->now());
      self::fail('Expected REVISION_CONFLICT');
    }
    catch (StoreException $e) {
      self::assertSame(StoreException::REVISION_CONFLICT, $e->kind);
      self::assertStringContainsString('is at revision 1, not 2', $e->getMessage());
    }
    try {
      $this->stores->objects->saveRevision($this->piece('missing'), 1, $this->clock->now());
      self::fail('Expected NOT_FOUND');
    }
    catch (StoreException $e) {
      self::assertSame(StoreException::NOT_FOUND, $e->kind);
    }
    self::assertSame(1, $this->stores->objects->latest($piece->iri)?->latestRevision);

    $this->stores->objects->erase($piece->iri);
    self::assertFalse($this->stores->objects->exists($piece->iri));
    self::assertNull($this->stores->objects->latest($piece->iri));
    $this->stores->objects->erase($piece->iri);
    self::assertSame(1, $this->stores->objects->create($piece, $this->clock->now())->revision, 'Erased objects can be created again');
  }

  /**
   * Tests events round trip and last modified.
   */
  public function testEventsRoundTripAndLastModified(): void {
    $event = $this->event('e1', 'piece-1', '2026-10-02T12:00:00.000Z', '2026-10-01T08:00:00Z', creationDate: '2026-10-01T09:00:00Z');
    $this->stores->events->append($event);
    $read = $this->stores->events->get($event->iri);
    self::assertNotNull($read);
    self::assertSame($event->iri->value, $read->iri->value);
    self::assertSame($event->logisticsObject->value, $read->logisticsObject->value);
    self::assertEquals($event->created, $read->created);
    self::assertEquals($event->eventDate(), $read->eventDate());
    self::assertEquals($event->creationDate(), $read->creationDate());
    self::assertSame($event->eventCode(), $read->eventCode());
    self::assertSame($event->toJsonLd(), $read->toJsonLd());
    self::assertNull($this->stores->events->get($this->iri('nothing')));

    self::assertNull($this->stores->events->lastModified($this->iri('piece-2')));
    $later = $this->event('e2', 'piece-1', '2026-10-02T13:00:00.000Z', '2026-10-01T10:00:00Z');
    $this->stores->events->append($later);
    self::assertEquals(new \DateTimeImmutable('2026-10-02T13:00:00.000Z'), $this->stores->events->lastModified($this->iri('piece-1')));
  }

  /**
   * Tests event query filters sorts and pages.
   */
  public function testEventQueryFiltersSortsAndPages(): void {
    // Storage time and cargo:eventDate are deliberately out of step.
    $this->stores->events->append($this->event('a', 'piece-1', '2026-10-02T10:00:00.000Z', '2026-10-01T03:00:00Z', 'DEP'));
    $this->stores->events->append($this->event('b', 'piece-1', '2026-10-02T11:00:00.000Z', '2026-10-01T01:00:00Z', 'ARR', creationDate: '2026-10-02T09:00:00Z'));
    $this->stores->events->append($this->event('c', 'piece-1', '2026-10-02T12:00:00.000Z', NULL, 'dep'));
    $this->stores->events->append($this->event('d', 'piece-1', '2026-10-02T12:00:00.000Z', '2026-10-01T02:00:00Z', ['@value' => 'DEP']));
    $this->stores->events->append($this->event('x', 'piece-2', '2026-10-02T12:00:00.000Z', '2026-10-01T02:00:00Z', 'DEP'));
    $piece = $this->iri('piece-1');
    $ids = fn(EventQuery $q): array => array_map(static fn(LogisticsEvent $e): string => $e->iri->localName(), $this->stores->events->query($piece, $q));

    self::assertSame(['b', 'a', 'c', 'd'], $ids(EventQuery::all()), 'Default sort: cargo:creationDate (falling back to storage time) ascending, then IRI');
    self::assertSame(['d', 'c', 'a', 'b'], $ids(new EventQuery(sort: EventQuery::SORT_CREATED_DESC)));
    self::assertSame(['b', 'd', 'a', 'c'], $ids(new EventQuery(sort: EventQuery::SORT_EVENT_ASC)), 'Null event dates fall back to storage time');
    self::assertSame(['c', 'a', 'd', 'b'], $ids(new EventQuery(sort: EventQuery::SORT_EVENT_DESC)));

    self::assertSame(['a', 'd'], $ids(new EventQuery(eventCodes: ['DEP'])), 'Codes match the IRI fragment and plain strings, case-sensitively');
    self::assertSame(['b', 'a', 'd'], $ids(new EventQuery(eventCodes: ['DEP', 'ARR'])));
    self::assertSame(['a', 'd'], $ids(new EventQuery(eventCodes: ['https://onerecord.iata.org/ns/code-lists/StatusCode#DEP', 'DEP'])));
    self::assertSame([], $ids(new EventQuery(eventCodes: ['EP'])));

    self::assertSame(['c', 'd'], $ids(new EventQuery(createdAfter: new \DateTimeImmutable('2026-10-02T11:00:00Z'))), 'Bounds are strict');
    self::assertSame(['b', 'a'], $ids(new EventQuery(createdBefore: new \DateTimeImmutable('2026-10-02T12:00:00Z'))));
    self::assertSame(['b'], $ids(new EventQuery(createdAfter: new \DateTimeImmutable('2026-10-02T08:00:00Z'), createdBefore: new \DateTimeImmutable('2026-10-02T10:00:00Z'))), 'cargo:creationDate wins over storage time');
    self::assertSame(['a'], $ids(new EventQuery(occurredAfter: new \DateTimeImmutable('2026-10-01T02:00:00Z'))), 'Events without an event date are excluded by occurrence filters');
    self::assertSame(['b', 'd'], $ids(new EventQuery(occurredBefore: new \DateTimeImmutable('2026-10-01T03:00:00Z'))));

    self::assertSame(['a', 'c'], $ids(new EventQuery(skip: 1, limit: 2)));
    self::assertSame(['d'], $ids(new EventQuery(skip: 3)));
    self::assertSame([], $ids(new EventQuery(skip: 10)));
    self::assertSame(['d'], $ids(new EventQuery(eventCodes: ['DEP'], skip: 1, limit: 5)));
    self::assertSame([], $ids(new EventQuery(eventCodes: ['DEP'], limit: 0)));
  }

  /**
   * Tests action request round trips.
   */
  public function testActionRequestRoundTrips(): void {
    $store = $this->stores->actionRequests;
    $change = $this->changeRequest('c1');
    $store->save($change);
    $read = $store->get($change->iri);
    self::assertNotNull($read);
    self::assertSame(ActionRequestType::Change, $read->type);
    self::assertSame(self::PARTNER, $read->requestedBy->value);
    self::assertEquals($change->requestedAt, $read->requestedAt);
    self::assertSame(RequestStatus::Pending, $read->status);
    self::assertSame($change->toJsonLd(ApiVersion::latest()), $read->toJsonLd(ApiVersion::latest()));

    $this->clock->advance('+5 minutes');
    $rejected = $read->withStatus(RequestStatus::Rejected, $this->clock->now(), new Iri(self::HOLDER), [Error::of('Conflict', '409', 'Revision moved on')]);
    $store->save($rejected);
    $read = $store->get($change->iri);
    self::assertNotNull($read);
    self::assertSame(RequestStatus::Rejected, $read->status, 'save() overwrites');
    self::assertEquals($this->clock->now(), $read->statusSince);
    self::assertCount(1, $read->errors);
    self::assertSame('Conflict', $read->errors[0]->title);
    self::assertCount(1, $read->history, 'The history holds the statuses left behind');
    self::assertSame(RequestStatus::Pending, $read->history[0]->status);
    self::assertSame(self::HOLDER, $read->history[0]->changedBy?->value);

    $this->clock->advance('+1 minute');
    $subscription = $this->request('s1', $this->subscription(self::BASE . '/logistics-objects/piece-1', expires: '2027-01-01T00:00:00Z'));
    $store->save($subscription->withStatus(RequestStatus::Accepted, $this->clock->now(), new Iri(self::HOLDER)));
    $read = $store->get($subscription->iri);
    self::assertNotNull($read);
    self::assertInstanceOf(Subscription::class, $read->payload);
    self::assertEquals(new \DateTimeImmutable('2027-01-01T00:00:00Z'), $read->payload->expiresAt);
    self::assertTrue($read->payload->sendLogisticsObjectBody);

    $delegation = $this->request('d1', new AccessDelegation(
      [Permission::GetLogisticsObject, Permission::GetLogisticsEvent],
      [new Iri(self::OTHER), new Iri('https://third.example/logistics-objects/third')],
      [$this->iri('piece-1'), $this->iri('piece-2')],
      'Share with the forwarder',
      expiresAt: new \DateTimeImmutable('2026-12-31T00:00:00Z'),
    ));
    $store->save($delegation);
    $read = $store->get($delegation->iri);
    self::assertNotNull($read);
    self::assertInstanceOf(AccessDelegation::class, $read->payload);
    self::assertCount(2, $read->payload->delegates);
    self::assertCount(2, $read->payload->logisticsObjects);
    self::assertEquals(new \DateTimeImmutable('2026-12-31T00:00:00Z'), $read->payload->expiresAt);

    $verification = $this->request('v1', new Verification($this->iri('piece-1'), [Error::of('Wrong weight', 'E1', 'Scale says 21 kg')], 1));
    $store->save($verification->withStatus(RequestStatus::Revoked, $this->clock->now(), new Iri(self::PARTNER)));
    $read = $store->get($verification->iri);
    self::assertNotNull($read);
    self::assertInstanceOf(Verification::class, $read->payload);
    self::assertSame(RequestStatus::Revoked, $read->status);
    self::assertSame(self::PARTNER, $read->revokedBy?->value);
    self::assertEquals($this->clock->now(), $read->revokedAt);

    self::assertNull($store->get(new Iri(self::BASE . '/action-requests/none')));
  }

  /**
   * Tests audit trail and pending changes.
   */
  public function testAuditTrailAndPendingChanges(): void {
    $store = $this->stores->actionRequests;
    $piece = $this->iri('piece-1');
    $c1 = $this->changeRequest('c1', at: '2026-10-02T10:00:00Z');
    $c2 = $this->changeRequest('c2', at: '2026-10-02T11:00:00Z')->withStatus(RequestStatus::Accepted, new \DateTimeImmutable('2026-10-02T11:30:00Z'), new Iri(self::HOLDER));
    $c0 = $this->changeRequest('c0', at: '2026-10-02T11:00:00Z');
    $other = $this->changeRequest('c3', 'piece-2', at: '2026-10-02T12:00:00Z');
    $verification = $this->request('v1', new Verification($piece, [Error::of('Check', 'E', 'm')], 1), at: '2026-10-02T12:30:00Z');
    $subscription = $this->request('s1', $this->subscription($piece->value), at: '2026-10-02T09:00:00Z');
    foreach ([$c2, $c1, $other, $c0, $verification, $subscription] as $request) {
      $store->save($request);
    }

    self::assertSame(['c1', 'c0', 'c2', 'v1'], self::names($store->auditTrail($piece, AuditTrailQuery::all())), 'Ordered by request time then IRI; subscriptions and other objects excluded');
    self::assertSame(['c1', 'c0'], self::names($store->auditTrail($piece, new AuditTrailQuery(status: RequestStatus::Pending, updatedTo: new \DateTimeImmutable('2026-10-02T11:00:00Z')))), 'updatedTo is inclusive');
    self::assertSame(['c2', 'v1'], self::names($store->auditTrail($piece, new AuditTrailQuery(updatedFrom: new \DateTimeImmutable('2026-10-02T11:30:00Z')))), 'Accepted requests are filtered by their status time');
    self::assertSame(['c2'], self::names($store->auditTrail($piece, new AuditTrailQuery(status: RequestStatus::Accepted))));
    self::assertSame(['c1', 'c0'], self::names($store->pendingChanges($piece)));
    self::assertSame(['c3'], self::names($store->pendingChanges($this->iri('piece-2'))));
    self::assertSame([], $store->pendingChanges($this->iri('piece-3')));
  }

  /**
   * Tests subscribers.
   */
  public function testSubscribers(): void {
    $requests = $this->stores->actionRequests;
    $piece = $this->iri('piece-1');
    $accepted = fn(ActionRequest $r): ActionRequest => $r->withStatus(RequestStatus::Accepted, $this->clock->now(), new Iri(self::HOLDER));
    $requests->save($accepted($this->request('by-id', $this->subscription($piece->value))));
    $requests->save($accepted($this->request('by-type', $this->subscription(Cargo::Piece, TopicType::Type, subscriber: self::OTHER))));
    $requests->save($accepted($this->request('expired', $this->subscription($piece->value, expires: '2026-10-02T12:00:00.000Z'))));
    $requests->save($accepted($this->request('later', $this->subscription($piece->value, expires: '2026-10-02T12:00:00.001Z', subscriber: 'https://late.example/logistics-objects/x'))));
    $requests->save($this->request('pending', $this->subscription($piece->value, subscriber: 'https://pending.example/logistics-objects/x')));
    $requests->save($accepted($this->request('other-object', $this->subscription($this->iri('piece-2')->value))));
    $requests->save($accepted($this->request('other-type', $this->subscription(Cargo::Company, TopicType::Type))));
    $requests->save($this->request('not-a-subscription', new Verification($piece, [Error::of('x')], 1))->withStatus(RequestStatus::Acknowledged, $this->clock->now(), new Iri(self::HOLDER)));

    $now = new \DateTimeImmutable('2026-10-02T12:00:00.000Z');
    $result = $this->stores->subscriptions->subscribersOf($piece, [Cargo::Piece], $now);
    // The order of subscribers is not part of the contract.
    $requestIris = array_map(static fn(array $r): string => $r['request']->localName(), $result);
    sort($requestIris);
    self::assertSame(['by-id', 'by-type', 'later'], $requestIris, 'Accepted, unexpired (expiry exactly now is expired), covering the object or its type');
    $byType = array_values(array_filter($result, static fn(array $r): bool => $r['request']->localName() === 'by-type'));
    self::assertSame(self::OTHER, $byType[0]['subscription']->subscriber->value);
    $withoutTypes = array_map(static fn(array $r): string => $r['request']->localName(), $this->stores->subscriptions->subscribersOf($piece, [], $now));
    sort($withoutTypes);
    self::assertSame(['by-id', 'later'], $withoutTypes);
    self::assertSame([], $this->stores->subscriptions->subscribersOf($this->iri('piece-9'), [Cargo::Shipment], $now));
  }

  /**
   * Tests offers.
   */
  public function testOffers(): void {
    $store = $this->stores->subscriptions;
    self::assertSame([], $store->offered(TopicType::Type, Cargo::Piece));
    $store->offer($this->subscription(Cargo::Piece, TopicType::Type, subscriber: self::HOLDER));
    $store->offer($this->subscription(Cargo::Piece, TopicType::Type, subscriber: self::HOLDER, expires: '2027-01-01T00:00:00Z'));
    $store->offer($this->subscription(Cargo::Shipment, TopicType::Type, subscriber: self::HOLDER));
    $store->offer($this->subscription(Cargo::Piece, TopicType::Identifier, subscriber: self::HOLDER));
    $offers = $store->offered(TopicType::Type, Cargo::Piece);
    self::assertCount(2, $offers);
    self::assertSame(self::HOLDER, $offers[0]->subscriber->value);
    self::assertNull($offers[0]->expiresAt);
    self::assertEquals(new \DateTimeImmutable('2027-01-01T00:00:00Z'), $offers[1]->expiresAt);
    self::assertCount(1, $store->offered(TopicType::Identifier, Cargo::Piece));
  }

  /**
   * Tests grants.
   */
  public function testGrants(): void {
    $store = $this->stores->delegations;
    $piece = $this->iri('piece-1');
    $partner = new Iri(self::PARTNER);
    $source = new Iri(self::BASE . '/action-requests/d1');
    $store->grant(new Grant($partner, $piece, [Permission::GetLogisticsObject]));
    $store->grant(new Grant($partner, $piece, [Permission::PatchLogisticsObject, Permission::GetLogisticsEvent], new \DateTimeImmutable('2026-12-31T00:00:00Z'), $source));
    $store->grant(new Grant($partner, $this->iri('piece-2'), [Permission::GetLogisticsObject], source: $source));
    $store->grant(new Grant(new Iri(self::OTHER), $piece, [Permission::GetLogisticsObject]));
    $store->grant(new Grant(new Iri(strtoupper(self::PARTNER)), $piece, [Permission::GetLogisticsObject]));

    $grants = $store->grantsFor($partner, $piece);
    self::assertCount(2, $grants, 'Agent and object match byte for byte, in insertion order');
    self::assertSame([Permission::GetLogisticsObject], $grants[0]->permissions);
    self::assertNull($grants[0]->source);
    self::assertNull($grants[0]->expiresAt);
    self::assertSame([Permission::PatchLogisticsObject, Permission::GetLogisticsEvent], $grants[1]->permissions);
    self::assertEquals(new \DateTimeImmutable('2026-12-31T00:00:00Z'), $grants[1]->expiresAt);
    self::assertSame($source->value, $grants[1]->source?->value);
    self::assertTrue($grants[1]->isActiveAt(new \DateTimeImmutable('2026-12-30T00:00:00Z')));
    self::assertFalse($grants[1]->isActiveAt(new \DateTimeImmutable('2026-12-31T00:00:00Z')));

    $store->revokeFrom($source);
    self::assertCount(1, $store->grantsFor($partner, $piece), 'Only grants from that request go');
    self::assertCount(0, $store->grantsFor($partner, $this->iri('piece-2')));
    self::assertCount(1, $store->grantsFor(new Iri(self::OTHER), $piece));
  }

  /**
   * Tests outbox round trip.
   */
  public function testOutboxRoundTrip(): void {
    $piece = $this->piece();
    $notification = new Notification(NotificationEventType::LogisticsObjectUpdated, $piece->iri, Cargo::Piece, new Iri(self::HOLDER), [Cargo::grossWeight], body: $piece);
    $outbound = new OutboundNotification(new Iri(self::PARTNER), $notification, $this->clock->now(), 'n-1');
    self::assertSame('https://1r.partner.example/notifications', $outbound->suggestedEndpoint());
    $this->stores->outbox->enqueue($outbound);
    $this->stores->outbox->enqueue(new OutboundNotification(new Iri('urn:agent:no-endpoint'), new Notification(NotificationEventType::LogisticsObjectCreated, $piece->iri), $this->clock->now(), 'n-2'));

    $queued = $this->outboxContents();
    self::assertCount(2, $queued);
    self::assertSame(self::PARTNER, $queued[0]->recipient->value);
    self::assertEquals($this->clock->now(), $queued[0]->createdAt);
    self::assertSame($notification->toJsonLd(), $queued[0]->notification->toJsonLd(), 'The body object survives');
    self::assertNotNull($queued[0]->notification->body);
    self::assertTrue($queued[0]->notification->body->isSameAs($piece));
    self::assertNull($queued[1]->suggestedEndpoint());
  }

  /**
   * Everything enqueued so far, oldest first.
   *
   * @return list<\LambdaTwelve\OneRecord\Server\Spi\OutboundNotification>
   *   The queued notifications.
   */
  abstract protected function outboxContents(): array;

}
