<?php

declare(strict_types=1);

namespace Drupal\one_record\Authentication\Provider;

use Drupal\Core\Authentication\AuthenticationProviderInterface;
use Drupal\one_record\Config\OneRecordConfig;
use Symfony\Component\HttpFoundation\Request;

/**
 * Claims the token endpoint's HTTP Basic credentials for the SDK.
 *
 * Drupal picks one authentication provider per request, the first that
 * applies by priority, and a route answers 403 to a provider its _auth
 * option does not list. On the token endpoint every other provider is
 * wrong: basic_auth would take client_secret_basic credentials for a user
 * login, with user lookups and flood control against client ids; cookie
 * would claim a request that merely carries a session cookie, which API
 * tools that have visited the site do, and the route would then refuse
 * the form credentials in it. So this provider sorts above both and applies
 * to every request on the token path, whatever credentials it carries, and
 * authenticates nobody: the request stays anonymous and the SDK's
 * TokenEndpoint verifies the client against one_record_clients. The token
 * route lists only this provider in its _auth option.
 */
final class TokenEndpointProvider implements AuthenticationProviderInterface {

  /**
   * The provider id, as the token route's _auth option names it.
   */
  public const ID = 'one_record_token_endpoint';

  public function __construct(
    private readonly OneRecordConfig $config,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(Request $request): bool {
    // Authentication runs before routing, so the path decides.
    return $this->config->tokenEndpointEnabled()
      && rtrim($request->getPathInfo(), '/') === rtrim($this->config->tokenPath(), '/');
  }

  /**
   * {@inheritdoc}
   */
  public function authenticate(Request $request): NULL {
    return NULL;
  }

}
