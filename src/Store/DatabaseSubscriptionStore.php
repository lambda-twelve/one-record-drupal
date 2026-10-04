<?php

declare(strict_types=1);

namespace Drupal\one_record\Store;

use Drupal\Core\Database\Connection;
use LambdaTwelve\OneRecord\Api\ActionRequestType;
use LambdaTwelve\OneRecord\Api\Subscription;
use LambdaTwelve\OneRecord\Api\TopicType;
use LambdaTwelve\OneRecord\JsonLd\Json;
use LambdaTwelve\OneRecord\Rdf\Iri;
use LambdaTwelve\OneRecord\Server\Spi\ActionRequestStore;
use LambdaTwelve\OneRecord\Server\Spi\SubscriptionStore;
use Psr\Clock\ClockInterface;

/**
 * Subscriptions in Drupal's database.
 *
 * Who to notify is derived from the accepted subscription requests of the
 * configured ActionRequestStore, so replacing that store alone keeps
 * notifications flowing; when it is the module's own, the topic columns its
 * table projects narrow the candidates in SQL. The subscriptions this host
 * offers to publishers live in their own table.
 */
final class DatabaseSubscriptionStore implements SubscriptionStore {

  private const OFFERS = 'one_record_subscription_offers';

  public function __construct(
    private readonly Connection $connection,
    private readonly ActionRequestStore $requests,
    private readonly ClockInterface $clock,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function subscribersOf(Iri $logisticsObject, array $types, \DateTimeImmutable $now): array {
    // The configured request store is the source, whatever it is; when it is
    // the module's own, its topic projection narrows the candidates in SQL.
    $candidates = $this->requests instanceof DatabaseActionRequestStore
      ? $this->requests->acceptedSubscriptionsCovering($logisticsObject, $types, $now)
      : $this->requests->accepted(ActionRequestType::Subscription);
    $out = [];
    foreach ($candidates as $request) {
      $subscription = $request->payload;
      // The SDK's own rules decide.
      if (!$subscription instanceof Subscription || $subscription->isExpiredAt($now) || !$subscription->covers($logisticsObject, $types)) {
        continue;
      }
      $out[] = ['subscription' => $subscription, 'request' => $request->iri];
    }
    return $out;
  }

  /**
   * {@inheritdoc}
   */
  public function offered(TopicType $topicType, string $topic): array {
    $documents = $this->connection->select(self::OFFERS, 'o')
      ->fields('o', ['document'])
      ->condition('topic_type', $topicType->name)
      ->condition('topic_hash', Db::hash($topic))
      ->orderBy('id')
      ->execute()
      ?->fetchCol() ?? [];
    $offers = [];
    foreach ($documents as $document) {
      $offer = Subscription::fromJsonLd((string) $document);
      if ($offer->topic === $topic) {
        $offers[] = $offer;
      }
    }
    return $offers;
  }

  /**
   * Registers a subscription this host wants when a publisher asks.
   *
   * {@inheritdoc}
   */
  public function offer(Subscription $subscription): void {
    $this->connection->insert(self::OFFERS)
      ->fields([
        'topic_type' => $subscription->topicType->name,
        'topic' => $subscription->topic,
        'topic_hash' => Db::hash($subscription->topic),
        'document' => Json::encode($subscription->toJsonLd(), FALSE),
        'created_at' => Db::micros($this->clock->now()),
      ])
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function withdraw(Subscription $subscription): void {
    $rows = $this->connection->select(self::OFFERS, 'o')
      ->fields('o', ['id', 'document'])
      ->condition('topic_type', $subscription->topicType->name)
      ->condition('topic_hash', Db::hash($subscription->topic))
      ->execute();
    foreach ($rows ?? [] as $row) {
      // Offers carry no subscriber column; match on the stored document's
      // subscriber.
      $offered = Subscription::fromJsonLd((string) $row->document);
      if ($offered->subscriber->equals($subscription->subscriber)) {
        $this->connection->delete(self::OFFERS)->condition('id', (int) $row->id)->execute();
      }
    }
  }

  /**
   * Withdraws every offer for a topic.
   *
   * @return int
   *   How many offers were removed.
   */
  public function withdrawTopic(TopicType $topicType, string $topic): int {
    return $this->connection->delete(self::OFFERS)
      ->condition('topic_type', $topicType->name)
      ->condition('topic_hash', Db::hash($topic))
      ->execute();
  }

}
