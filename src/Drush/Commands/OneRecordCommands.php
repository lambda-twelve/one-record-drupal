<?php

declare(strict_types=1);

namespace Drupal\one_record\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\PropertyList;
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\Core\Database\Connection;
use Drupal\one_record\Auth\DatabaseClientCredentials;
use Drupal\one_record\Config\OneRecordConfig;
use Drupal\one_record\Notification\Delivery;
use Drupal\one_record\Server\ServicesFactory;
use Drupal\one_record\Store\DatabaseAccessDelegationStore;
use Drupal\one_record\Store\DatabaseLogisticsEventStore;
use Drupal\one_record\Store\DatabaseLogisticsObjectStore;
use Drupal\one_record\Store\DatabaseNotificationOutbox;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use LambdaTwelve\OneRecord\Api\Permission;
use LambdaTwelve\OneRecord\Rdf\Iri;
use LambdaTwelve\OneRecord\Server\DataHolder;
use LambdaTwelve\OneRecord\Server\ServerBuilder;
use LambdaTwelve\OneRecord\Server\Spi\Grant;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Operational commands: diagnostics, clients, grants, the outbox, forgetting.
 */
final class OneRecordCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    #[Autowire(service: 'one_record.config')]
    private readonly OneRecordConfig $settings,
    #[Autowire(service: 'one_record.store.logistics_objects')]
    private readonly DatabaseLogisticsObjectStore $objects,
    #[Autowire(service: 'one_record.store.logistics_events')]
    private readonly DatabaseLogisticsEventStore $events,
    #[Autowire(service: 'one_record.store.delegations')]
    private readonly DatabaseAccessDelegationStore $delegations,
    #[Autowire(service: 'one_record.store.outbox')]
    private readonly DatabaseNotificationOutbox $outbox,
    #[Autowire(service: 'one_record.client_credentials')]
    private readonly DatabaseClientCredentials $clients,
    #[Autowire(service: 'one_record.delivery')]
    private readonly Delivery $delivery,
    #[Autowire(service: 'one_record.clock')]
    private readonly ClockInterface $clock,
    #[Autowire(service: 'database')]
    private readonly Connection $connection,
    #[Autowire(service: 'one_record.services_factory')]
    private readonly ServicesFactory $services,
  ) {
    parent::__construct();
  }

  /**
   * Shows the server configuration and what the stores hold.
   */
  #[CLI\Command(name: 'one-record:status', aliases: ['1r-status'])]
  #[CLI\FieldLabels(labels: [
    'configured' => 'Configured',
    'endpoint' => 'Server endpoint',
    'data_holder' => 'Data holder',
    'holder_stored' => 'Holder object stored',
    'denial' => 'Denied requests',
    'internal_agents' => 'Internal agents',
    'issuers' => 'Trusted issuers',
    'token_endpoint' => 'Token endpoint',
    'partners' => 'Partners',
    'objects' => 'Logistics objects',
    'pending_requests' => 'Pending action requests',
    'outbox_pending' => 'Outbox: pending',
    'outbox_delivered' => 'Outbox: delivered',
    'outbox_failed' => 'Outbox: failed',
    'clients' => 'Token clients',
    'wiring' => 'Wiring',
  ])]
  #[CLI\DefaultFields(fields: [
    'configured',
    'endpoint',
    'data_holder',
    'holder_stored',
    'denial',
    'internal_agents',
    'issuers',
    'token_endpoint',
    'partners',
    'objects',
    'pending_requests',
    'outbox_pending',
    'outbox_delivered',
    'outbox_failed',
    'clients',
    'wiring',
  ])]
  public function status(array $options = ['format' => 'table']): PropertyList {
    $problems = $this->settings->problems();
    $configured = $problems === [];
    $outbox = $this->outbox->counts();
    $pending = (int) $this->connection->select('one_record_action_requests')->condition('status', 'REQUEST_PENDING')->countQuery()->execute()?->fetchField();
    return new PropertyList([
      'configured' => $configured ? 'yes' : 'no (' . implode(' ', $problems) . ')',
      'endpoint' => $configured ? $this->settings->endpoint() : '',
      'data_holder' => $configured ? $this->settings->serverConfig()->dataHolder->value : '',
      'holder_stored' => $configured ? ($this->objects->exists($this->settings->serverConfig()->dataHolder) ? 'yes' : 'no') : '',
      'denial' => $this->settings->denial()->name,
      'internal_agents' => implode(', ', array_map(static fn(Iri $i): string => $i->value, $this->settings->internalAgents())),
      'issuers' => implode(', ', array_column($this->settings->issuers(), 'issuer')),
      'token_endpoint' => $this->settings->tokenEndpointEnabled() ? $this->settings->tokenPath() : 'disabled',
      'partners' => implode(', ', array_column($this->settings->partners(), 'agent')),
      'objects' => $this->objects->count(),
      'pending_requests' => $pending,
      'outbox_pending' => $outbox['pending'],
      'outbox_delivered' => $outbox['delivered'],
      'outbox_failed' => $outbox['failed'],
      'clients' => count($this->clients->all()),
      'wiring' => $configured ? (implode(' ', ServerBuilder::check($this->services->create())) ?: 'ok') : '',
    ]);
  }

  /**
   * Creates OAuth client credentials for the token endpoint.
   */
  #[CLI\Command(name: 'one-record:client:create', aliases: ['1r-client-create'])]
  #[CLI\Argument(name: 'agent', description: 'The logistics agent IRI the client acts as.')]
  #[CLI\Option(name: 'label', description: 'A human-readable name.')]
  #[CLI\Option(name: 'client-id', description: 'A client id to use instead of a generated one.')]
  #[CLI\Usage(name: 'drush one-record:client:create https://1r.example.com/one-record/logistics-objects/holder --label="ERP"', description: 'Credentials for an internal system acting as the data holder.')]
  public function clientCreate(string $agent, array $options = ['label' => '', 'client-id' => NULL]): void {
    $credentials = $this->clients->create(new Iri($agent), (string) $options['label'], is_string($options['client-id']) ? $options['client-id'] : NULL);
    $this->io()->success('Client created. The secret is shown once; store it now.');
    $this->io()->definitionList(
      ['Client id' => $credentials['client_id']],
      ['Client secret' => $credentials['client_secret']],
      ['Agent' => $agent],
      ['Token endpoint' => $this->settings->tokenEndpointEnabled() ? $this->settings->tokenPath() : 'disabled (enable it in the ONE Record settings)'],
    );
  }

  /**
   * Lists OAuth clients.
   */
  #[CLI\Command(name: 'one-record:client:list', aliases: ['1r-client-list'])]
  #[CLI\FieldLabels(labels: [
    'client_id' => 'Client id',
    'label' => 'Label',
    'agent_iri' => 'Agent',
    'enabled' => 'Enabled',
    'created_at' => 'Created',
    'last_used_at' => 'Last used',
  ])]
  #[CLI\DefaultFields(fields: ['client_id', 'label', 'agent_iri', 'enabled', 'last_used_at'])]
  public function clientList(array $options = ['format' => 'table']): RowsOfFields {
    $rows = [];
    foreach ($this->clients->all() as $client) {
      $rows[] = [
        'client_id' => $client['client_id'],
        'label' => $client['label'],
        'agent_iri' => $client['agent_iri'],
        'enabled' => $client['enabled'] ? 'yes' : 'no',
        'created_at' => $client['created_at']->format(DATE_ATOM),
        'last_used_at' => $client['last_used_at']?->format(DATE_ATOM) ?? '',
      ];
    }
    return new RowsOfFields($rows);
  }

  /**
   * Disables an OAuth client without deleting it.
   */
  #[CLI\Command(name: 'one-record:client:disable', aliases: ['1r-client-disable'])]
  #[CLI\Argument(name: 'clientId', description: 'The client id.')]
  public function clientDisable(string $clientId): void {
    $this->clients->setEnabled($clientId, FALSE) ? $this->io()->success('Client disabled.') : $this->io()->error('No such client.');
  }

  /**
   * Re-enables an OAuth client.
   */
  #[CLI\Command(name: 'one-record:client:enable', aliases: ['1r-client-enable'])]
  #[CLI\Argument(name: 'clientId', description: 'The client id.')]
  public function clientEnable(string $clientId): void {
    $this->clients->setEnabled($clientId, TRUE) ? $this->io()->success('Client enabled.') : $this->io()->error('No such client.');
  }

  /**
   * Deletes an OAuth client.
   */
  #[CLI\Command(name: 'one-record:client:delete', aliases: ['1r-client-delete'])]
  #[CLI\Argument(name: 'clientId', description: 'The client id.')]
  public function clientDelete(string $clientId): void {
    $this->clients->delete($clientId) ? $this->io()->success('Client deleted.') : $this->io()->error('No such client.');
  }

  /**
   * Grants an agent permissions on a logistics object.
   */
  #[CLI\Command(name: 'one-record:grant', aliases: ['1r-grant'])]
  #[CLI\Argument(name: 'agent', description: 'The agent IRI.')]
  #[CLI\Argument(name: 'object', description: 'The logistics object IRI.')]
  #[CLI\Argument(name: 'permissions', description: 'Permissions: GET_LOGISTICS_OBJECT, PATCH_LOGISTICS_OBJECT, POST_LOGISTICS_EVENT, GET_LOGISTICS_EVENT.')]
  #[CLI\Option(name: 'expires', description: 'When the grant expires, as a date-time.')]
  #[CLI\Usage(name: 'drush one-record:grant https://partner.example/logistics-objects/partner https://1r.example.com/one-record/logistics-objects/piece-1 GET_LOGISTICS_OBJECT GET_LOGISTICS_EVENT', description: 'Let a partner read a piece and its events.')]
  public function grant(string $agent, string $object, array $permissions, array $options = ['expires' => NULL]): void {
    $parsed = [];
    foreach ($permissions as $name) {
      $parsed[] = Permission::tryFromString((string) $name) ?? throw new \InvalidArgumentException(sprintf('"%s" is not a permission.', $name));
    }
    if ($parsed === []) {
      throw new \InvalidArgumentException('Name at least one permission.');
    }
    $expires = is_string($options['expires']) ? new \DateTimeImmutable($options['expires']) : NULL;
    $this->delegations->grant(new Grant(new Iri($agent), new Iri($object), $parsed, $expires));
    $this->io()->success(sprintf('Granted %s on %s to %s.', implode(', ', $permissions), $object, $agent));
  }

  /**
   * Revokes the holder's own grants to an agent on an object.
   */
  #[CLI\Command(name: 'one-record:revoke', aliases: ['1r-revoke'])]
  #[CLI\Argument(name: 'agent', description: 'The agent IRI.')]
  #[CLI\Argument(name: 'object', description: 'The logistics object IRI.')]
  public function revoke(string $agent, string $object): void {
    $count = $this->delegations->withdraw(new Iri($agent), new Iri($object));
    $this->io()->success(sprintf('%d grant(s) removed. Grants from accepted access delegations are revoked through their request.', $count));
  }

  /**
   * Delivers due outbox notifications now, without the queue.
   */
  #[CLI\Command(name: 'one-record:outbox:deliver', aliases: ['1r-deliver'])]
  #[CLI\Option(name: 'limit', description: 'How many notifications to attempt.')]
  public function outboxDeliver(array $options = ['limit' => 50]): void {
    $counts = $this->delivery->deliverDue((int) $options['limit']);
    $this->io()->definitionList(...array_map(static fn(string $k, int $v): array => [$k => $v], array_keys($counts), $counts));
  }

  /**
   * Removes delivered and failed notifications older than a given age.
   */
  #[CLI\Command(name: 'one-record:outbox:prune', aliases: ['1r-prune'])]
  #[CLI\Option(name: 'older-than', description: 'A relative age such as "30 days".')]
  public function outboxPrune(array $options = ['older-than' => '30 days']): void {
    $before = $this->clock->now()->modify('-' . ltrim((string) $options['older-than'], '-'));
    $count = $this->outbox->prune($before);
    $this->io()->success(sprintf('%d notification(s) removed.', $count));
  }

  /**
   * Erases a logistics object: every revision, and optionally more.
   */
  #[CLI\Command(name: 'one-record:forget', aliases: ['1r-forget'])]
  #[CLI\Argument(name: 'object', description: 'The logistics object IRI.')]
  #[CLI\Option(name: 'events', description: 'Also erase its logistics events.')]
  #[CLI\Option(name: 'grants', description: 'Also remove every grant on it.')]
  #[CLI\Usage(name: 'drush one-record:forget https://1r.example.com/one-record/logistics-objects/piece-1 --events --grants', description: 'Erase a piece completely.')]
  public function forget(string $object, array $options = ['events' => FALSE, 'grants' => FALSE]): void {
    $iri = new Iri($object);
    if (!$this->io()->confirm(sprintf('Erase %s? Partners are not told; the SDK leaves that to you.', $object))) {
      return;
    }
    // Built here rather than injected: it needs a configured server.
    $holder = new DataHolder($this->services->create());
    $transaction = $this->connection->startTransaction();
    try {
      $holder->forget($iri);
      if ((bool) $options['events']) {
        $this->events->eraseFor($iri);
      }
      if ((bool) $options['grants']) {
        $this->delegations->eraseFor($iri);
      }
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
    unset($transaction);
    $this->io()->success('Erased.');
  }

}
