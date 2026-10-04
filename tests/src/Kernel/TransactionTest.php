<?php

declare(strict_types=1);

namespace Drupal\Tests\one_record\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\one_record\Notification\QueuedOutbox;
use Drupal\one_record\Store\DatabaseNotificationOutbox;
use Drupal\one_record\Store\Db;
use LambdaTwelve\OneRecord\Api\AccessDelegation;
use LambdaTwelve\OneRecord\Api\ActionRequest;
use LambdaTwelve\OneRecord\Api\Permission;
use LambdaTwelve\OneRecord\Api\RequestStatus;
use LambdaTwelve\OneRecord\Api\Subscription;
use LambdaTwelve\OneRecord\Api\SubscriptionEventType;
use LambdaTwelve\OneRecord\Api\TopicType;
use LambdaTwelve\OneRecord\Change\ChangeBuilder;
use LambdaTwelve\OneRecord\Rdf\Iri;
use LambdaTwelve\OneRecord\Server\DataHolder;
use LambdaTwelve\OneRecord\Server\Event\LogisticsObjectCreated;
use LambdaTwelve\OneRecord\Server\Event\LogisticsObjectRevised;
use LambdaTwelve\OneRecord\Server\GrantAccessPolicy;
use LambdaTwelve\OneRecord\Server\Spi\AccessDelegationStore;
use LambdaTwelve\OneRecord\Server\Spi\ActionRequestStore;
use LambdaTwelve\OneRecord\Server\Spi\LogisticsObjectStore;
use LambdaTwelve\OneRecord\Testing\RacingActionRequestStore;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Everything an operation writes stands or falls together.
 *
 * The SDK runs every mutating request and every DataHolder operation
 * through the unit of work; the module binds a database transaction there,
 * so a failure anywhere in an operation leaves nothing of it behind.
 */
#[Group('one_record')]
#[RunTestsInSeparateProcesses]
final class TransactionTest extends OneRecordKernelTestBase {

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    // The SDK's racing double in front of the real request store, so a test
    // can stage a decision lost to another worker.
    $container->register('one_record.racing_requests', RacingActionRequestStore::class)
      ->setDecoratedService('one_record.store.action_requests')
      ->setArguments([new Reference('one_record.racing_requests.inner')])
      ->setPublic(TRUE);
  }

  /**
   * Tests that a listener's exception rolls the operation back.
   */
  public function testFailingListenerLeavesNothingStored(): void {
    $holder = $this->container->get('one_record.data_holder');
    self::assertInstanceOf(DataHolder::class, $holder);
    $dispatcher = $this->container->get('event_dispatcher');
    $listener = static function (): void {
      throw new \RuntimeException('The ERP is down');
    };
    $dispatcher->addListener(LogisticsObjectCreated::class, $listener);

    $piece = $this->piece();
    try {
      $holder->create($piece);
      self::fail('The listener\'s exception reaches the caller');
    }
    catch (\RuntimeException $e) {
      self::assertSame('The ERP is down', $e->getMessage());
    }

    $objects = $this->container->get(LogisticsObjectStore::class);
    self::assertInstanceOf(LogisticsObjectStore::class, $objects);
    self::assertNull($objects->latest($piece->iri), 'The object was rolled back with the failed operation');
    self::assertSame(0, $this->container->get('queue')->get(QueuedOutbox::QUEUE)->numberOfItems());

    $dispatcher->removeListener(LogisticsObjectCreated::class, $listener);
    self::assertSame(1, $holder->create($piece)->revision, 'Nothing lingers: the same URI can be created');
  }

  /**
   * Tests that a failure after the decision rolls the decision back too.
   *
   * The SDK stores a decision before its side effects. Without a
   * transaction, a listener failing after that would leave the request
   * accepted and the revision written behind a 500 answer.
   */
  public function testFailureAfterTheDecisionUndoesTheDecision(): void {
    $holder = $this->container->get('one_record.data_holder');
    self::assertInstanceOf(DataHolder::class, $holder);
    $piece = $this->piece();
    $stored = $holder->create($piece);
    $holder->subscribe(new Subscription(new Iri(self::PARTNER), TopicType::Identifier, $piece->iri->value, [SubscriptionEventType::LogisticsObjectUpdated]));
    $policy = $this->container->get('one_record.access_policy');
    self::assertInstanceOf(GrantAccessPolicy::class, $policy);
    $policy->allow(new Iri(self::PARTNER), $piece->iri, [Permission::GetLogisticsObject, Permission::PatchLogisticsObject]);

    $change = (new ChangeBuilder())->diff($stored->object, $this->piece(weight: 25.0), 1, 'Reweighed');
    self::assertNotNull($change);
    $path = substr($piece->iri->value, strlen(self::BASE));
    $response = $this->request('PATCH', $path, self::PARTNER, $change->toJson());
    self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
    $requestIri = (string) $response->headers->get('Location');
    $queue = $this->container->get('queue')->get(QueuedOutbox::QUEUE);
    while ($item = $queue->claimItem()) {
      $queue->deleteItem($item);
    }

    $this->container->get('event_dispatcher')->addListener(LogisticsObjectRevised::class, static function (): void {
      throw new \RuntimeException('The warehouse system refused the new weight');
    });
    $response = $this->request('PATCH', substr($requestIri, strlen(self::BASE)) . '?status=REQUEST_ACCEPTED', self::HOLDER);
    self::assertSame(500, $response->getStatusCode(), (string) $response->getContent());
    self::assertSame('api:Error', self::json($response)['@type']);

    $requests = $this->container->get(ActionRequestStore::class);
    self::assertInstanceOf(ActionRequestStore::class, $requests);
    self::assertSame(RequestStatus::Pending, $requests->get(new Iri($requestIri))?->status, 'The acceptance was rolled back');
    self::assertSame('1', $this->request('GET', $path)->headers->get('Revision'), 'So was the revision');
    $outbox = $this->container->get('one_record.store.outbox');
    self::assertInstanceOf(DatabaseNotificationOutbox::class, $outbox);
    self::assertSame([], $outbox->due($this->clock->now()->modify('+1 day')), 'Nothing was fanned out');
    self::assertSame(0, $queue->numberOfItems());
  }

  /**
   * Tests that an acceptance beaten by a competing decision writes nothing.
   */
  public function testCompetingDecisionLeavesNothingBehind(): void {
    $holder = $this->container->get('one_record.data_holder');
    self::assertInstanceOf(DataHolder::class, $holder);
    $piece = $this->piece();
    $holder->create($piece);

    $delegation = new AccessDelegation([Permission::GetLogisticsObject], [new Iri(self::PARTNER)], [$piece->iri]);
    $response = $this->request('POST', '/one-record/access-delegations', self::HOLDER, json_encode($delegation->toJsonLd(), JSON_THROW_ON_ERROR));
    self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
    $requestIri = (string) $response->headers->get('Location');

    // Another worker rejects the request a moment before our compare-and-set,
    // which then loses for real against the module's own store.
    $racing = $this->container->get('one_record.store.action_requests');
    self::assertInstanceOf(RacingActionRequestStore::class, $racing);
    $connection = $this->container->get('database');
    $racing->arm(new Iri($requestIri), static function (ActionRequest $request) use ($connection): void {
      $connection->update('one_record_action_requests')
        ->fields(['status' => RequestStatus::Rejected->shortName()])
        ->condition('iri_hash', Db::hash($request->iri))
        ->execute();
    });
    $response = $this->request('PATCH', substr($requestIri, strlen(self::BASE)) . '?status=REQUEST_ACCEPTED', self::HOLDER);
    self::assertSame(409, $response->getStatusCode(), (string) $response->getContent());
    self::assertSame('api:Error', self::json($response)['@type']);
    self::assertSame(1, $racing->racesLost, 'The competing decision was staged');

    $grants = $this->container->get(AccessDelegationStore::class);
    self::assertInstanceOf(AccessDelegationStore::class, $grants);
    self::assertSame([], $grants->grantsFor(new Iri(self::PARTNER), $piece->iri), 'The losing acceptance granted nothing');
    self::assertSame(403, $this->request('GET', substr($piece->iri->value, strlen(self::BASE)))->getStatusCode());
    self::assertSame(0, $this->container->get('queue')->get(QueuedOutbox::QUEUE)->numberOfItems(), 'And queued nothing');

    $requests = $this->container->get(ActionRequestStore::class);
    self::assertInstanceOf(ActionRequestStore::class, $requests);
    self::assertSame(RequestStatus::Pending, $requests->get(new Iri($requestIri))?->status, 'The staged rejection unwound with the unit; the request awaits a decision');
  }

}
