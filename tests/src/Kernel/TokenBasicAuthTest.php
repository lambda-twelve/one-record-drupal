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
 * The token endpoint's Basic credentials reach the SDK, not Drupal's login.
 *
 * With core's basic_auth enabled, any request carrying HTTP Basic
 * credentials is a Drupal user login unless a provider sorted above it
 * claims the request. The module's TokenEndpointProvider does that for the
 * token path only.
 */
#[Group('one_record')]
#[RunTestsInSeparateProcesses]
final class TokenBasicAuthTest extends OneRecordKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'basic_auth', 'one_record'];

  /**
   * {@inheritdoc}
   */
  protected bool $headerAuthentication = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['user']);
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
   * Tests client_secret_basic with basic_auth enabled.
   */
  public function testBasicCredentialsAreTheSdksWithBasicAuthEnabled(): void {
    $clients = $this->container->get('one_record.client_credentials');
    self::assertInstanceOf(DatabaseClientCredentials::class, $clients);
    $credentials = $clients->create(new Iri(self::HOLDER), 'ERP');

    $response = $this->token(['grant_type' => 'client_credentials'], $credentials['client_id'], $credentials['client_secret']);
    self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    self::assertSame('Bearer', self::json($response)['token_type']);
    self::assertSame(0, (int) $this->container->get('current_user')->id(), 'Nobody was logged in to Drupal');

    $response = $this->token(['grant_type' => 'client_credentials'], $credentials['client_id'], 'wrong');
    self::assertSame(401, $response->getStatusCode(), 'A wrong secret is the SDK\'s 401, not Drupal\'s 403 page');
    self::assertSame('invalid_client', self::json($response)['error']);
    self::assertFalse($this->container->get('flood')->isAllowed('basic_auth.failed_login_ip', 0), 'No flood event was registered for the client id');

    $response = $this->token(['grant_type' => 'client_credentials'] + $credentials);
    self::assertSame(200, $response->getStatusCode(), 'Form credentials still work');

    // A client with a cookie jar that has visited the site before: the
    // session cookie must not hand the request to Drupal's cookie provider,
    // which the token route does not allow (AR2-002).
    $response = $this->token(['grant_type' => 'client_credentials'] + $credentials, cookie: TRUE);
    self::assertSame(200, $response->getStatusCode(), 'Form credentials with a session cookie: ' . $response->getContent());
    self::assertSame('Bearer', self::json($response)['token_type']);
    $response = $this->token(['grant_type' => 'client_credentials', 'client_id' => $credentials['client_id'], 'client_secret' => 'wrong'], cookie: TRUE);
    self::assertSame(401, $response->getStatusCode(), 'And a wrong secret is still the SDK\'s answer');
    $response = $this->token(['grant_type' => 'client_credentials'], $credentials['client_id'], $credentials['client_secret'], cookie: TRUE);
    self::assertSame(200, $response->getStatusCode(), 'Basic credentials with a session cookie');

    // Elsewhere, Basic credentials are still Drupal's business.
    $request = Request::create(self::BASE . '/one-record', 'GET', [], [], [], ['PHP_AUTH_USER' => 'someone', 'PHP_AUTH_PW' => 'secret', 'HTTP_ACCEPT' => 'application/ld+json']);
    $kernel = $this->container->get('http_kernel');
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    self::assertSame(403, $response->getStatusCode(), 'basic_auth is not allowed on the API routes, so core refuses');
  }

  /**
   * Posts to the token endpoint, with Basic credentials when given.
   *
   * @param array<string, string> $fields
   *   The form fields.
   * @param string|null $user
   *   The Basic user name (the client id), if any.
   * @param string|null $password
   *   The Basic password (the client secret), if any.
   * @param bool $cookie
   *   Whether to send the site's session cookie, as a browser or a tool
   *   with a cookie jar would.
   */
  private function token(array $fields, ?string $user = NULL, ?string $password = NULL, bool $cookie = FALSE): Response {
    $server = ['HTTP_ACCEPT' => 'application/json'];
    if ($user !== NULL) {
      $server['PHP_AUTH_USER'] = $user;
      $server['PHP_AUTH_PW'] = $password;
    }
    $request = Request::create(self::BASE . '/one-record/oauth/token', 'POST', $fields, [], [], $server);
    if ($cookie) {
      $name = $this->container->get('session_configuration')->getOptions($request)['name'];
      $request = Request::create(self::BASE . '/one-record/oauth/token', 'POST', $fields, [$name => 'stale-or-anonymous'], [], $server);
    }
    $kernel = $this->container->get('http_kernel');
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    return $response;
  }

}
