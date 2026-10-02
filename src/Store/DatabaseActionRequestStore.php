<?php

declare(strict_types=1);

namespace Drupal\one_record\Store;

use Drupal\Core\Database\Connection;
use LambdaTwelve\OneRecord\Api\AccessDelegation;
use LambdaTwelve\OneRecord\Api\ActionRequest;
use LambdaTwelve\OneRecord\Api\ActionRequestType;
use LambdaTwelve\OneRecord\Api\RequestStatus;
use LambdaTwelve\OneRecord\Api\Subscription;
use LambdaTwelve\OneRecord\JsonLd\Json;
use LambdaTwelve\OneRecord\Rdf\Iri;
use LambdaTwelve\OneRecord\Server\Spi\ActionRequestStore;
use LambdaTwelve\OneRecord\Server\Spi\AuditTrailQuery;
use LambdaTwelve\OneRecord\Spec\ApiVersion;
use Psr\Clock\ClockInterface;

/**
 * Action requests in Drupal's database.
 *
 * Each request is stored as the JSON-LD the SDK writes at the latest API
 * version (older versions omit the status history), together with the columns
 * the audit trail, the pending-change check and the subscription lookup
 * filter on. A pivot table lists the objects a request concerns, since an
 * access delegation can cover several.
 */
final class DatabaseActionRequestStore implements ActionRequestStore {

  private const TABLE = 'one_record_action_requests';
  private const OBJECTS = 'one_record_action_request_objects';

  public function __construct(
    private readonly Connection $connection,
    private readonly ClockInterface $clock,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function save(ActionRequest $request): void {
    $now = Db::micros($this->clock->now());
    $payload = $request->payload;
    $fields = [
      'iri' => $request->iri->value,
      'type' => $request->type->name,
      'status' => $request->status->shortName(),
      'requested_by' => $request->requestedBy->value,
      'requested_by_hash' => Db::hash($request->requestedBy),
      'requested_at' => Db::micros($request->requestedAt),
      'status_since' => Db::microsOrNull($request->statusSince),
      'last_modified' => Db::micros($request->lastModified()),
      'topic_type' => $payload instanceof Subscription ? $payload->topicType->name : NULL,
      'topic' => $payload instanceof Subscription ? $payload->topic : NULL,
      'topic_hash' => $payload instanceof Subscription ? Db::hash($payload->topic) : NULL,
      'subscriber_hash' => $payload instanceof Subscription ? Db::hash($payload->subscriber) : NULL,
      'expires_at' => $payload instanceof Subscription || $payload instanceof AccessDelegation ? Db::microsOrNull($payload->expiresAt) : NULL,
      'api_version' => ApiVersion::latest()->value,
      'document' => Json::encode($request->toJsonLd(ApiVersion::latest()), FALSE),
      'updated_at' => $now,
    ];
    $transaction = $this->connection->startTransaction();
    try {
      $this->connection->merge(self::TABLE)
        ->keys(['iri_hash' => Db::hash($request->iri)])
        ->insertFields($fields + ['iri_hash' => Db::hash($request->iri), 'created_at' => $now])
        ->updateFields($fields)
        ->execute();
      foreach ($request->logisticsObjects() as $object) {
        $this->connection->merge(self::OBJECTS)
          ->keys([
            'action_request_hash' => Db::hash($request->iri),
            'logistics_object_hash' => Db::hash($object),
          ])
          ->fields(['logistics_object_iri' => $object->value])
          ->execute();
      }
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function get(Iri $iri): ?ActionRequest {
    $document = $this->connection->select(self::TABLE, 'r')
      ->fields('r', ['document'])
      ->condition('iri_hash', Db::hash($iri))
      ->execute()
      ?->fetchField();
    return is_string($document) ? ActionRequest::fromJsonLd($document) : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function auditTrail(Iri $logisticsObject, AuditTrailQuery $query): array {
    return $this->about($logisticsObject, [ActionRequestType::Change->name, ActionRequestType::Verification->name], $query);
  }

  /**
   * {@inheritdoc}
   */
  public function pendingChanges(Iri $logisticsObject): array {
    return $this->about($logisticsObject, [ActionRequestType::Change->name], new AuditTrailQuery(status: RequestStatus::Pending));
  }

  /**
   * Requests of the given types about an object, oldest first.
   *
   * @param \LambdaTwelve\OneRecord\Rdf\Iri $logisticsObject
   *   The object.
   * @param list<string> $types
   *   ActionRequestType case names.
   * @param \LambdaTwelve\OneRecord\Server\Spi\AuditTrailQuery $query
   *   Time and status filters.
   *
   * @return list<\LambdaTwelve\OneRecord\Api\ActionRequest>
   *   The matching requests.
   */
  private function about(Iri $logisticsObject, array $types, AuditTrailQuery $query): array {
    $select = $this->connection->select(self::TABLE, 'r')
      ->fields('r', ['document']);
    $select->join(self::OBJECTS, 'o', 'o.action_request_hash = r.iri_hash');
    $select->condition('o.logistics_object_hash', Db::hash($logisticsObject))
      ->condition('r.type', $types, 'IN');
    if ($query->updatedFrom !== NULL) {
      $select->condition('r.last_modified', Db::micros($query->updatedFrom), '>=');
    }
    if ($query->updatedTo !== NULL) {
      $select->condition('r.last_modified', Db::micros($query->updatedTo), '<=');
    }
    if ($query->status !== NULL) {
      $select->condition('r.status', $query->status->shortName());
    }
    $requests = [];
    foreach ($select->execute()?->fetchCol() ?? [] as $document) {
      $requests[] = ActionRequest::fromJsonLd((string) $document);
    }
    usort($requests, static function (ActionRequest $a, ActionRequest $b): int {
      $byTime = $a->requestedAt <=> $b->requestedAt;
      return $byTime !== 0 ? $byTime : strcmp($a->iri->value, $b->iri->value);
    });
    return $requests;
  }

}
