<?php

declare(strict_types=1);

namespace Drupal\one_record\Store;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Database\Query\SelectInterface;
use LambdaTwelve\OneRecord\JsonLd\Json;
use LambdaTwelve\OneRecord\Model\LogisticsEvent;
use LambdaTwelve\OneRecord\Rdf\Iri;
use LambdaTwelve\OneRecord\Server\Spi\EventQuery;
use LambdaTwelve\OneRecord\Server\Spi\LogisticsEventStore;
use LambdaTwelve\OneRecord\Server\Spi\StoreException;

/**
 * Logistics events in Drupal's database.
 *
 * The event date, creation date and event code are extracted into columns on
 * append so the spec's filters run in SQL. Sorting and paging happen in PHP
 * on those columns: the SDK's ordering is byte-exact on the IRI, which a
 * collated ORDER BY cannot promise on every database. Only the page that is
 * returned has its JSON-LD loaded and expanded, so a small page over a long
 * history costs a scan of short rows, not a hydration of each.
 *
 * Every event the server hands over carries cargo:eventDate (the SDK refuses
 * one without); the fallbacks to the receipt time below only keep the store
 * total over rows written by other means.
 */
final class DatabaseLogisticsEventStore implements LogisticsEventStore {

  private const TABLE = 'one_record_logistics_events';

  public function __construct(
    private readonly Connection $connection,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function append(LogisticsEvent $event): void {
    // A savepoint, so a refused insert leaves the surrounding transaction
    // usable on every database.
    $transaction = $this->connection->startTransaction();
    try {
      try {
        $this->connection->insert(self::TABLE)
          ->fields([
            'iri_hash' => Db::hash($event->iri),
            'iri' => $event->iri->value,
            'logistics_object_hash' => Db::hash($event->logisticsObject),
            'logistics_object_iri' => $event->logisticsObject->value,
            'event_code' => $event->eventCode(),
            'event_date' => Db::microsOrNull($event->eventDate()),
            'creation_date' => Db::microsOrNull($event->creationDate()),
            'created_at' => Db::micros($event->created),
            'document' => Json::encode($event->toJsonLd(), FALSE),
          ])
          ->execute();
      }
      catch (IntegrityConstraintViolationException) {
        // The log is append-only and an event IRI is unique on the server,
        // whichever object it is filed under.
        throw StoreException::alreadyExists($event->iri);
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
  public function get(Iri $eventIri): ?LogisticsEvent {
    $row = $this->connection->select(self::TABLE, 'e')
      ->fields('e', ['iri', 'logistics_object_iri', 'document', 'created_at'])
      ->condition('iri_hash', Db::hash($eventIri))
      ->execute()
      ?->fetchAssoc();
    return is_array($row) ? $this->hydrate($row) : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function query(Iri $logisticsObject, EventQuery $query): array {
    if ($query->limit !== NULL && $query->limit <= 0) {
      return [];
    }
    $select = $this->connection->select(self::TABLE, 'e')
      ->fields('e', [
        'iri_hash',
        'iri',
        'event_code',
        'event_date',
        'creation_date',
        'created_at',
      ])
      ->condition('logistics_object_hash', Db::hash($logisticsObject));
    $this->applyRanges($select, $query);
    if ($query->eventCodes !== []) {
      // A LIKE superset in SQL; the exact, case-sensitive match follows in PHP.
      $codes = $select->orConditionGroup();
      foreach ($query->eventCodes as $code) {
        $codes->condition('event_code', $code);
        $codes->condition('event_code', '%' . $this->connection->escapeLike('#' . $code), 'LIKE');
        $codes->condition('event_code', '%' . $this->connection->escapeLike('/' . $code), 'LIKE');
      }
      $select->condition($codes);
    }

    $byEvent = str_ends_with($query->sort, 'eventDate');
    $descending = str_starts_with($query->sort, 'DESC');
    $keys = [];
    foreach ($select->execute() ?? [] as $row) {
      $row = (array) $row;
      $code = $row['event_code'] === NULL ? NULL : (string) $row['event_code'];
      if ($query->eventCodes !== [] && !self::matchesAny($code, $query->eventCodes)) {
        continue;
      }
      $instant = $byEvent ? ($row['event_date'] ?? $row['created_at']) : ($row['creation_date'] ?? $row['created_at']);
      $keys[] = ['hash' => (string) $row['iri_hash'], 'iri' => (string) $row['iri'], 'at' => (int) $instant];
    }
    usort($keys, static function (array $a, array $b) use ($descending): int {
      $cmp = $a['at'] <=> $b['at'];
      if ($cmp === 0) {
        $cmp = strcmp($a['iri'], $b['iri']);
      }
      return $descending ? -$cmp : $cmp;
    });
    $page = array_slice($keys, $query->skip, $query->limit);
    if ($page === []) {
      return [];
    }

    $rows = $this->connection->select(self::TABLE, 'e')
      ->fields('e', [
        'iri_hash',
        'iri',
        'logistics_object_iri',
        'document',
        'created_at',
      ])
      ->condition('iri_hash', array_column($page, 'hash'), 'IN')
      ->execute();
    $byHash = [];
    foreach ($rows ?? [] as $row) {
      $row = (array) $row;
      $byHash[(string) $row['iri_hash']] = $row;
    }
    $events = [];
    foreach ($page as $key) {
      if (isset($byHash[$key['hash']])) {
        $events[] = $this->hydrate($byHash[$key['hash']]);
      }
    }
    return $events;
  }

  /**
   * {@inheritdoc}
   */
  public function lastModified(Iri $logisticsObject): ?\DateTimeImmutable {
    $select = $this->connection->select(self::TABLE, 'e')
      ->condition('logistics_object_hash', Db::hash($logisticsObject));
    $select->addExpression('MAX(e.created_at)', 'last_modified');
    $value = $select->execute()?->fetchField();
    return $value === FALSE || $value === NULL ? NULL : Db::time($value);
  }

  /**
   * {@inheritdoc}
   */
  public function eraseFor(Iri $logisticsObject): void {
    $this->connection->delete(self::TABLE)
      ->condition('logistics_object_hash', Db::hash($logisticsObject))
      ->execute();
  }

  /**
   * Adds the four strict time bounds of an event query.
   */
  private function applyRanges(SelectInterface $select, EventQuery $query): void {
    if ($query->createdAfter !== NULL) {
      $select->where('COALESCE(e.creation_date, e.created_at) > :created_after', [':created_after' => Db::micros($query->createdAfter)]);
    }
    if ($query->createdBefore !== NULL) {
      $select->where('COALESCE(e.creation_date, e.created_at) < :created_before', [':created_before' => Db::micros($query->createdBefore)]);
    }
    if ($query->occurredAfter !== NULL) {
      $select->isNotNull('e.event_date')->condition('e.event_date', Db::micros($query->occurredAfter), '>');
    }
    if ($query->occurredBefore !== NULL) {
      $select->isNotNull('e.event_date')->condition('e.event_date', Db::micros($query->occurredBefore), '<');
    }
  }

  /**
   * Whether a stored event code matches any requested code.
   *
   * The same rule as LogisticsEvent::matchesCode(), on the column the code
   * was extracted into, so a code filter needs no document.
   *
   * @param string|null $code
   *   The stored event code.
   * @param list<string> $filters
   *   The requested codes.
   */
  private static function matchesAny(?string $code, array $filters): bool {
    if ($code === NULL) {
      return FALSE;
    }
    foreach ($filters as $filter) {
      if ($code === $filter || str_ends_with($code, '#' . $filter) || str_ends_with($code, '/' . $filter)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Rebuilds an event from its row without re-validating the document.
   *
   * @param array<string, mixed> $row
   *   The row.
   */
  private function hydrate(array $row): LogisticsEvent {
    return LogisticsEvent::fromStored(
      new Iri((string) $row['iri']),
      new Iri((string) $row['logistics_object_iri']),
      (string) $row['document'],
      Db::time($row['created_at']),
    );
  }

}
