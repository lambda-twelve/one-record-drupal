<?php

declare(strict_types=1);

namespace Drupal\one_record\Store;

use Drupal\Core\Database\Connection;
use LambdaTwelve\OneRecord\Api\Permission;
use LambdaTwelve\OneRecord\Rdf\Iri;
use LambdaTwelve\OneRecord\Server\Spi\AccessDelegationStore;
use LambdaTwelve\OneRecord\Server\Spi\Grant;
use LambdaTwelve\OneRecord\Vocabulary\Generated\Api;
use Psr\Clock\ClockInterface;

/**
 * Access grants in Drupal's database.
 *
 * A grant with a source is the result of an accepted access delegation and
 * disappears when that request is revoked; a grant without a source is the
 * data holder's own decision and stays until the host removes it.
 */
final class DatabaseAccessDelegationStore implements AccessDelegationStore {

  private const TABLE = 'one_record_grants';

  public function __construct(
    private readonly Connection $connection,
    private readonly ClockInterface $clock,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function grant(Grant $grant): void {
    $this->connection->insert(self::TABLE)
      ->fields([
        'agent' => $grant->agent->value,
        'agent_hash' => Db::hash($grant->agent),
        'logistics_object_iri' => $grant->logisticsObject->value,
        'logistics_object_hash' => Db::hash($grant->logisticsObject),
        'permissions' => implode(',', array_map(self::shortName(...), $grant->permissions)),
        'expires_at' => Db::microsOrNull($grant->expiresAt),
        'source' => $grant->source?->value,
        'source_hash' => $grant->source === NULL ? NULL : Db::hash($grant->source),
        'created_at' => Db::micros($this->clock->now()),
      ])
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function grantsFor(Iri $agent, Iri $logisticsObject): array {
    $rows = $this->connection->select(self::TABLE, 'g')
      ->fields('g')
      ->condition('agent_hash', Db::hash($agent))
      ->condition('logistics_object_hash', Db::hash($logisticsObject))
      ->orderBy('id')
      ->execute() ?? [];
    $grants = [];
    foreach ($rows as $row) {
      $grants[] = $this->hydrate((array) $row);
    }
    return $grants;
  }

  /**
   * {@inheritdoc}
   */
  public function revokeFrom(Iri $accessDelegationRequest): void {
    $this->connection->delete(self::TABLE)
      ->condition('source_hash', Db::hash($accessDelegationRequest))
      ->execute();
  }

  /**
   * Removes every grant on an object, for a host's data-erasure flow.
   *
   * Not part of the SPI: DataHolder::forget() leaves grants in place.
   */
  public function eraseFor(Iri $logisticsObject): void {
    $this->connection->delete(self::TABLE)
      ->condition('logistics_object_hash', Db::hash($logisticsObject))
      ->execute();
  }

  /**
   * Removes the holder's own grants to an agent on an object.
   *
   * The counterpart of GrantAccessPolicy::allow(), which writes a grant
   * without a source; delegated grants are revoked through their request.
   *
   * @return int
   *   How many grants were removed.
   */
  public function withdraw(Iri $agent, Iri $logisticsObject): int {
    return $this->connection->delete(self::TABLE)
      ->condition('agent_hash', Db::hash($agent))
      ->condition('logistics_object_hash', Db::hash($logisticsObject))
      ->isNull('source_hash')
      ->execute();
  }

  /**
   * Rebuilds a grant from its row.
   *
   * @param array<string, mixed> $row
   *   The row.
   */
  private function hydrate(array $row): Grant {
    $permissions = [];
    foreach (explode(',', (string) $row['permissions']) as $name) {
      $permission = Permission::tryFromString($name);
      if ($permission !== NULL) {
        $permissions[] = $permission;
      }
    }
    if ($permissions === []) {
      throw new \UnexpectedValueException(sprintf('Grant %s has no readable permissions.', $row['id']));
    }
    return new Grant(
      new Iri((string) $row['agent']),
      new Iri((string) $row['logistics_object_iri']),
      $permissions,
      Db::timeOrNull($row['expires_at']),
      $row['source'] === NULL ? NULL : new Iri((string) $row['source']),
    );
  }

  /**
   * The spelling of a permission in the permissions column.
   */
  private static function shortName(Permission $permission): string {
    return substr($permission->value, strlen(Api::NAMESPACE));
  }

}
