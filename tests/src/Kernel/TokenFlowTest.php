<?php

declare(strict_types=1);

namespace Drupal\Tests\one_record\Kernel;

use Drupal\one_record\Auth\DatabaseClientCredentials;
use Drupal\one_record\Config\OneRecordConfig;
use LambdaTwelve\OneRecord\Rdf\Iri;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tokens issued here are accepted here: client credentials to bearer token.
 */
#[Group('one_record')]
#[RunTestsInSeparateProcesses]
final class TokenFlowTest extends OneRecordKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected bool $headerAuthentication = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    self::assertNotFalse($key);
    self::assertTrue(openssl_pkey_export($key, $pem));
    self::assertIsString($pem);
    $this->setSetting('one_record.signing_key', $pem);
    $this->config(OneRecordConfig::NAME)
      ->set('token_endpoint.enabled', TRUE)
      ->set('token_endpoint.key_id', 'drupal-1')
      ->save();
  }

  /**
   * Tests issuing a token and using it against the server.
   */
  public function testClientCredentialsToBearerToken(): void {
    $clients = $this->container->get('one_record.client_credentials');
    self::assertInstanceOf(DatabaseClientCredentials::class, $clients);
    $credentials = $clients->create(new Iri(self::HOLDER), 'ERP');

    self::assertSame(401, $this->request('GET', '/one-record', agent: NULL)->getStatusCode(), 'No token, no access');
    self::assertSame(401, $this->request('GET', '/one-record', agent: NULL, headers: ['Authorization' => 'Bearer not.a.token'])->getStatusCode());

    $response = $this->token(['grant_type' => 'client_credentials', 'client_id' => $credentials['client_id'], 'client_secret' => 'wrong']);
    self::assertSame(401, $response->getStatusCode());
    self::assertSame('invalid_client', self::json($response)['error']);

    $response = $this->token(['grant_type' => 'client_credentials'] + $credentials);
    self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    $body = self::json($response);
    self::assertSame('Bearer', $body['token_type']);
    self::assertSame(3600, $body['expires_in']);
    $token = $body['access_token'];
    self::assertIsString($token);
    $header = json_decode(base64_decode(strtr(explode('.', $token)[0], '-_', '+/')), TRUE, 512, JSON_THROW_ON_ERROR);
    self::assertSame(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'drupal-1'], $header);

    $response = $this->request('GET', '/one-record', agent: NULL, headers: ['Authorization' => 'Bearer ' . $token]);
    self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    self::assertSame('api:ServerInformation', self::json($response)['@type']);

    $piece = $this->piece();
    $response = $this->request('POST', '/one-record/logistics-objects', agent: NULL, body: $piece->toJson(), headers: ['Authorization' => 'Bearer ' . $token]);
    self::assertSame(201, $response->getStatusCode(), 'The holder, as an internal agent, may create objects');

    $clients->setEnabled($credentials['client_id'], FALSE);
    self::assertSame(401, $this->token(['grant_type' => 'client_credentials'] + $credentials)->getStatusCode(), 'Disabled clients get no new tokens');
  }

  /**
   * Tests the JWKS document partners verify our tokens with.
   */
  public function testJwks(): void {
    $response = $this->request('GET', '/one-record/.well-known/jwks.json', agent: NULL);
    self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $keys = self::json($response)['keys'];
    self::assertIsArray($keys);
    self::assertCount(1, $keys);
    self::assertSame('RSA', $keys[0]['kty']);
    self::assertSame('RS256', $keys[0]['alg']);
    self::assertSame('drupal-1', $keys[0]['kid']);
    self::assertArrayHasKey('n', $keys[0]);
  }

  /**
   * Posts form fields to the token endpoint.
   *
   * @param array<string, string> $fields
   *   The form fields.
   */
  private function token(array $fields): Response {
    $request = Request::create(self::BASE . '/one-record/oauth/token', 'POST', $fields, [], [], ['HTTP_ACCEPT' => 'application/json']);
    $kernel = $this->container->get('http_kernel');
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    return $response;
  }

}
