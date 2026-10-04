<?php

declare(strict_types=1);

namespace Drupal\one_record\Auth;

use Drupal\Core\Database\Connection;
use Drupal\Core\Password\PasswordInterface;
use Drupal\Core\State\StateInterface;
use Drupal\one_record\Store\Db;
use LambdaTwelve\OneRecord\Auth\ClientCredentialsVerifier;
use LambdaTwelve\OneRecord\Rdf\Iri;
use Psr\Clock\ClockInterface;

/**
 * OAuth 2.0 clients of the token endpoint, with hashed secrets in the database.
 *
 * Verification always runs exactly one hash check, against a dummy hash
 * when the client id is unknown, so timing does not reveal which ids exist.
 * The dummy is made once with the same password service and kept in state,
 * and it is read on every verification before the client is looked up, so
 * an unknown id costs the same work as a known one: a per-process lazy
 * dummy would have added a hash computation to the unknown path, which is
 * what the check exists to hide. Secrets are hashed with Drupal's password
 * service and shown once, when the client is created.
 */
final class DatabaseClientCredentials implements ClientCredentialsVerifier {

  private const TABLE = 'one_record_clients';

  /**
   * The state key of the hash unknown client ids are checked against.
   */
  public const DUMMY_HASH_STATE = 'one_record.client_dummy_hash';

  public function __construct(
    private readonly Connection $connection,
    private readonly PasswordInterface $password,
    private readonly ClockInterface $clock,
    private readonly StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function verify(string $clientId, string $clientSecret): ?Iri {
    $dummy = $this->dummyHash();
    $row = $this->connection->select(self::TABLE, 'c')
      ->fields('c', ['secret_hash', 'agent_iri', 'enabled'])
      ->condition('client_id', $clientId)
      ->execute()
      ?->fetchAssoc();
    $hash = is_array($row) ? (string) $row['secret_hash'] : $dummy;
    $valid = $this->password->check($clientSecret, $hash);
    if (!$valid || !is_array($row) || !(bool) $row['enabled']) {
      return NULL;
    }
    $this->connection->update(self::TABLE)
      ->fields(['last_used_at' => Db::micros($this->clock->now())])
      ->condition('client_id', $clientId)
      ->execute();
    return new Iri((string) $row['agent_iri']);
  }

  /**
   * Registers a client and returns its secret, which is never stored.
   *
   * @return array{client_id: string, client_secret: string}
   *   The credentials to hand to the client.
   */
  public function create(Iri $agent, string $label = '', ?string $clientId = NULL): array {
    $clientId ??= bin2hex(random_bytes(16));
    $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $this->connection->insert(self::TABLE)
      ->fields([
        'client_id' => $clientId,
        'secret_hash' => $this->password->hash($secret) ?: throw new \RuntimeException('Hashing the client secret failed.'),
        'agent_iri' => $agent->value,
        'label' => $label,
        'enabled' => 1,
        'created_at' => Db::micros($this->clock->now()),
      ])
      ->execute();
    return ['client_id' => $clientId, 'client_secret' => $secret];
  }

  /**
   * Enables or disables a client.
   *
   * @return bool
   *   Whether the client exists.
   */
  public function setEnabled(string $clientId, bool $enabled): bool {
    return $this->connection->update(self::TABLE)
      ->fields(['enabled' => $enabled ? 1 : 0])
      ->condition('client_id', $clientId)
      ->execute() === 1;
  }

  /**
   * Removes a client.
   *
   * @return bool
   *   Whether the client existed.
   */
  public function delete(string $clientId): bool {
    return $this->connection->delete(self::TABLE)->condition('client_id', $clientId)->execute() === 1;
  }

  /**
   * Every client, for administration.
   *
   * @return list<array{client_id: string, agent_iri: string, label: string, enabled: bool, created_at: \DateTimeImmutable, last_used_at: ?\DateTimeImmutable}>
   *   The clients, oldest first.
   */
  public function all(): array {
    $out = [];
    $rows = $this->connection->select(self::TABLE, 'c')->fields('c')->orderBy('created_at')->execute() ?? [];
    foreach ($rows as $row) {
      $row = (array) $row;
      $out[] = [
        'client_id' => (string) $row['client_id'],
        'agent_iri' => (string) $row['agent_iri'],
        'label' => (string) $row['label'],
        'enabled' => (bool) $row['enabled'],
        'created_at' => Db::time($row['created_at']),
        'last_used_at' => Db::timeOrNull($row['last_used_at']),
      ];
    }
    return $out;
  }

  /**
   * The dummy hash: made once for the site, read from state after that.
   */
  private function dummyHash(): string {
    $hash = $this->state->get(self::DUMMY_HASH_STATE);
    if (!is_string($hash) || $hash === '') {
      $hash = $this->password->hash(bin2hex(random_bytes(16))) ?: throw new \RuntimeException('Hashing failed.');
      $this->state->set(self::DUMMY_HASH_STATE, $hash);
    }
    return $hash;
  }

}
