<?php

declare(strict_types=1);

namespace Drupal\Tests\one_record\Support;

use LambdaTwelve\OneRecord\Rdf\Iri;
use LambdaTwelve\OneRecord\Server\Spi\Agent;
use LambdaTwelve\OneRecord\Server\Spi\Authenticator;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Trusts an X-Test-Agent header, as the SDK's own test suite does.
 */
final class HeaderAuthenticator implements Authenticator {

  /**
   * {@inheritdoc}
   */
  public function authenticate(ServerRequestInterface $request): ?Agent {
    $iri = $request->getHeaderLine('X-Test-Agent');
    return $iri === '' ? NULL : new Agent(new Iri($iri), 'https://test.issuer', ['sub' => $iri]);
  }

}
