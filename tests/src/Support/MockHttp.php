<?php

declare(strict_types=1);

namespace Drupal\Tests\one_record\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Replaces Drupal's http_client with a scripted Guzzle client.
 *
 * Kernel tests run in their own process, so the static queue and history
 * belong to one test only.
 */
final class MockHttp {

  /**
   * The scripted responses.
   */
  public static ?MockHandler $handler = NULL;

  /**
   * Every request sent, with its response, as Guzzle's history middleware records it.
   *
   * @var array<int, array<string, mixed>>
   */
  public static array $history = [];

  /**
   * The factory the http_client service definition points at.
   */
  public static function client(): Client {
    self::$handler ??= new MockHandler();
    $stack = HandlerStack::create(self::$handler);
    // Guzzle fills the array by reference; PHPStan cannot follow the generics.
    // @phpstan-ignore-next-line
    $stack->push(Middleware::history(self::$history));
    return new Client(['handler' => $stack]);
  }

  /**
   * Queues a response.
   */
  public static function respond(ResponseInterface $response): void {
    self::$handler ??= new MockHandler();
    self::$handler->append($response);
  }

  /**
   * The requests sent so far.
   *
   * @return list<\Psr\Http\Message\RequestInterface>
   *   In order.
   */
  public static function requests(): array {
    $requests = [];
    foreach (self::$history as $entry) {
      $request = $entry['request'];
      assert($request instanceof RequestInterface);
      $requests[] = $request;
    }
    return $requests;
  }

}
