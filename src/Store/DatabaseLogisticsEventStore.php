<?php

declare(strict_types=1);

namespace Drupal\one_record\Store;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use LambdaTwelve\OneRecord\JsonLd\Json;
use LambdaTwelve\OneRecord\JsonLd\JsonLd;
use LambdaTwelve\OneRecord\Model\LogisticsEvent;
use LambdaTwelve\OneRecord\Rdf\Iri;
use LambdaTwelve\OneRecord\Server\Spi\EventQuery;
use LambdaTwelve\OneRecord\Server\Spi\LogisticsEventStore;

/**
 * Logistics events in Drupal's database.
 *
 * The event date, creation date and event code are extracted into columns on
 * append so the spec's filters run in SQL. Sorting and paging happen in PHP
 * after the filtered rows are loaded: the SDK's ordering is byte-exact on the
 * IRI, which a collated ORDER BY cannot promise on every database.
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
    $this->connection->merge(self::TABLE)
      ->keys(['iri_hash' => Db::hash($event->iri)])
      ->fields([
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

  /**
   * {@inheritdoc}
   */
  public function get(Iri $eventIri): ?LogisticsEvent {
    $row = $this->connection->select(self::TABLE, 'e')
      ->fields('e')
      ->condition('iri_hash', Db::hash($eventIri))
      ->execute()
      ?->fetchAssoc();
    return is_array($row) ? $this->hydrate($row) : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function query(Iri $logisticsObject, EventQuery $query): array {
    $select = $this->connection->select(self::TABLE, 'e')
      ->fields('e')
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
    $events = [];
    foreach ($select->execute() ?? [] as $row) {
      $event = $this->hydrate((array) $row);
      if ($query->eventCodes !== [] && !$this->matchesAny($event, $query->eventCodes)) {
        continue;
      }
      $events[] = $event;
    }
    usort($events, static function (LogisticsEvent $a, LogisticsEvent $b) use ($query): int {
      $byEvent = str_ends_with($query->sort, 'eventDate');
      $ka = $byEvent ? ($a->eventDate() ?? $a->created) : ($a->creationDate() ?? $a->created);
      $kb = $byEvent ? ($b->eventDate() ?? $b->created) : ($b->creationDate() ?? $b->created);
      $cmp = $ka <=> $kb;
      if ($cmp === 0) {
        $cmp = strcmp($a->iri->value, $b->iri->value);
      }
      return str_starts_with($query->sort, 'DESC') ? -$cmp : $cmp;
    });
    return array_slice($events, $query->skip, $query->limit);
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
   * Removes every event of an object, for a host's data-erasure flow.
   *
   * Not part of the SPI: DataHolder::forget() erases the object only.
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
   * Whether an event carries any of the requested codes.
   *
   * @param \LambdaTwelve\OneRecord\Model\LogisticsEvent $event
   *   The event.
   * @param list<string> $codes
   *   The requested codes.
   */
  private function matchesAny(LogisticsEvent $event, array $codes): bool {
    foreach ($codes as $code) {
      if ($event->matchesCode($code)) {
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
    return new LogisticsEvent(
      new Iri((string) $row['iri']),
      new Iri((string) $row['logistics_object_iri']),
      JsonLd::expand((string) $row['document'])->graph,
      Db::time($row['created_at']),
    );
  }

}
