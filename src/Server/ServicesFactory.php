<?php

declare(strict_types=1);

namespace Drupal\one_record\Server;

use Drupal\one_record\Config\OneRecordConfig;
use LambdaTwelve\OneRecord\Server\Services;
use LambdaTwelve\OneRecord\Server\Spi\AccessDelegationStore;
use LambdaTwelve\OneRecord\Server\Spi\AccessPolicy;
use LambdaTwelve\OneRecord\Server\Spi\ActionRequestStore;
use LambdaTwelve\OneRecord\Server\Spi\Authenticator;
use LambdaTwelve\OneRecord\Server\Spi\LogisticsEventStore;
use LambdaTwelve\OneRecord\Server\Spi\LogisticsObjectStore;
use LambdaTwelve\OneRecord\Server\Spi\NotificationOutbox;
use LambdaTwelve\OneRecord\Server\Spi\SubscriptionStore;
use LambdaTwelve\OneRecord\Server\Spi\UnitOfWork;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Assembles the SDK's Services object from Drupal's container.
 *
 * Every constructor argument is a container service, so a site swaps any
 * piece (a store, the policy, the authenticator, the unit of work) by
 * overriding that service.
 */
final class ServicesFactory {

  public function __construct(
    private readonly OneRecordConfig $config,
    private readonly LogisticsObjectStore $objects,
    private readonly LogisticsEventStore $events,
    private readonly ActionRequestStore $actionRequests,
    private readonly SubscriptionStore $subscriptions,
    private readonly AccessDelegationStore $delegations,
    private readonly NotificationOutbox $outbox,
    private readonly Authenticator $authenticator,
    private readonly AccessPolicy $policy,
    private readonly ClockInterface $clock,
    private readonly EventDispatcherInterface $dispatcher,
    private readonly ResponseFactoryInterface $responses,
    private readonly StreamFactoryInterface $streams,
    private readonly LoggerInterface $logger,
    private readonly UnitOfWork $unitOfWork,
  ) {}

  /**
   * The services, built from the current configuration.
   *
   * @throws \Drupal\one_record\Config\NotConfiguredException
   */
  public function create(): Services {
    return new Services(
      $this->config->serverConfig(),
      $this->objects,
      $this->events,
      $this->actionRequests,
      $this->subscriptions,
      $this->delegations,
      $this->outbox,
      $this->authenticator,
      $this->policy,
      $this->clock,
      $this->dispatcher,
      $this->responses,
      $this->streams,
      $this->logger,
      unitOfWork: $this->unitOfWork,
    );
  }

}
