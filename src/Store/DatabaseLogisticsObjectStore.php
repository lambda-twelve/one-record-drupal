<?php

declare(strict_types=1);

namespace Drupal\one_record\Store;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use LambdaTwelve\OneRecord\JsonLd\Json;
use LambdaTwelve\OneRecord\Model\LogisticsObject;
use LambdaTwelve\OneRecord\Rdf\Iri;
use LambdaTwelve\OneRecord\Server\Spi\LogisticsObjectStore;
use LambdaTwelve\OneRecord\Server\Spi\StoredObject;
use LambdaTwelve\OneRecord\Server\Spi\StoreException;

/**
 * Logistics objects in Drupal's database: a head row plus one row per revision.
 *
 * The head row carries the latest revision number, which makes saveRevision()
 * a single compare-and-set UPDATE: the optimistic concurrency the SPI demands
 * is enforced by the database, not by the PHP process.
 */
final class DatabaseLogisticsObjectStore implements LogisticsObjectStore {

  private const HEAD = 'one_record_logistics_objects';
  private const REVISIONS = 'one_record_logistics_object_revisions';

  public function __construct(
    private readonly Connection $connection,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function latest(Iri $iri): ?StoredObject {
    $head = $this->head($iri);
    if ($head === NULL) {
      return NULL;
    }
    return $this->read($iri, (int) $head['latest_revision'], $head);
  }

  /**
   * {@inheritdoc}
   */
  public function revision(Iri $iri, int $revision): ?StoredObject {
    if ($revision < 1) {
      return NULL;
    }
    $head = $this->head($iri);
    if ($head === NULL || $revision > (int) $head['latest_revision']) {
      return NULL;
    }
    return $this->read($iri, $revision, $head);
  }

  /**
   * {@inheritdoc}
   */
  public function at(Iri $iri, \DateTimeImmutable $at): ?StoredObject {
    $head = $this->head($iri);
    if ($head === NULL) {
      return NULL;
    }
    $revision = $this->connection->select(self::REVISIONS, 'r')
      ->fields('r', ['revision'])
      ->condition('iri_hash', Db::hash($iri))
      ->condition('created_at', Db::micros($at), '<=')
      ->orderBy('revision', 'DESC')
      ->range(0, 1)
      ->execute()
      ?->fetchField();
    if ($revision === FALSE || $revision === NULL) {
      return NULL;
    }
    return $this->read($iri, (int) $revision, $head);
  }

  /**
   * {@inheritdoc}
   */
  public function exists(Iri $iri): bool {
    return $this->head($iri) !== NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function create(LogisticsObject $object, \DateTimeImmutable $at): StoredObject {
    $micros = Db::micros($at);
    $transaction = $this->connection->startTransaction();
    try {
      try {
        $this->connection->insert(self::HEAD)
          ->fields([
            'iri_hash' => Db::hash($object->iri),
            'iri' => $object->iri->value,
            'latest_revision' => 1,
            'created_at' => $micros,
            'updated_at' => $micros,
          ])
          ->execute();
      }
      catch (IntegrityConstraintViolationException) {
        throw StoreException::alreadyExists($object->iri);
      }
      $this->insertRevision($object, 1, $micros);
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
    unset($transaction);
    return new StoredObject($object, 1, 1, $at, $at);
  }

  /**
   * {@inheritdoc}
   */
  public function saveRevision(LogisticsObject $object, int $expectedCurrent, \DateTimeImmutable $at): StoredObject {
    $next = $expectedCurrent + 1;
    $micros = Db::micros($at);
    $transaction = $this->connection->startTransaction();
    try {
      $updated = $this->connection->update(self::HEAD)
        ->fields(['latest_revision' => $next, 'updated_at' => $micros])
        ->condition('iri_hash', Db::hash($object->iri))
        ->condition('latest_revision', $expectedCurrent)
        ->execute();
      if ($updated === 0) {
        $head = $this->head($object->iri);
        if ($head === NULL) {
          throw StoreException::notFound($object->iri);
        }
        throw StoreException::revisionConflict($object->iri, $expectedCurrent, (int) $head['latest_revision']);
      }
      $head = $this->head($object->iri) ?? throw StoreException::notFound($object->iri);
      $createdAt = Db::time($head['created_at']);
      $this->insertRevision($object, $next, $micros);
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
    unset($transaction);
    return new StoredObject($object, $next, $next, $createdAt, $at);
  }

  /**
   * {@inheritdoc}
   */
  public function erase(Iri $iri): void {
    $transaction = $this->connection->startTransaction();
    try {
      $this->connection->delete(self::REVISIONS)->condition('iri_hash', Db::hash($iri))->execute();
      $this->connection->delete(self::HEAD)->condition('iri_hash', Db::hash($iri))->execute();
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
  }

  /**
   * The IRIs of every stored object, for diagnostics.
   *
   * @return int
   *   How many logistics objects the store holds.
   */
  public function count(): int {
    return (int) $this->connection->select(self::HEAD)->countQuery()->execute()?->fetchField();
  }

  /**
   * The head row of an object.
   *
   * @return array<string, mixed>|null
   *   The row, or NULL when the object does not exist.
   */
  private function head(Iri $iri): ?array {
    $row = $this->connection->select(self::HEAD, 'h')
      ->fields('h')
      ->condition('iri_hash', Db::hash($iri))
      ->execute()
      ?->fetchAssoc();
    return is_array($row) ? $row : NULL;
  }

  /**
   * Reads one revision, given the head row.
   *
   * @param \LambdaTwelve\OneRecord\Rdf\Iri $iri
   *   The object.
   * @param int $revision
   *   The revision to read.
   * @param array<string, mixed> $head
   *   The head row.
   */
  private function read(Iri $iri, int $revision, array $head): ?StoredObject {
    $row = $this->connection->select(self::REVISIONS, 'r')
      ->fields('r', ['document', 'created_at'])
      ->condition('iri_hash', Db::hash($iri))
      ->condition('revision', $revision)
      ->execute()
      ?->fetchAssoc();
    if (!is_array($row)) {
      return NULL;
    }
    return new StoredObject(
      LogisticsObject::fromJsonLd((string) $row['document'], $iri),
      $revision,
      (int) $head['latest_revision'],
      Db::time($head['created_at']),
      Db::time($row['created_at']),
    );
  }

  /**
   * Inserts a revision row.
   */
  private function insertRevision(LogisticsObject $object, int $revision, int $micros): void {
    $this->connection->insert(self::REVISIONS)
      ->fields([
        'iri_hash' => Db::hash($object->iri),
        'revision' => $revision,
        'type' => $object->mostSpecificType(),
        'document' => Json::encode($object->toJsonLd(), FALSE),
        'created_at' => $micros,
      ])
      ->execute();
  }

}
