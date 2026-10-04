# ONE Record for Drupal

A Drupal module that makes [`lambda-twelve/one-record`](https://github.com/lambda-twelve/one-record),
the framework-agnostic PHP SDK for the IATA ONE Record API, a Drupal citizen:
enable the module, configure a base URL and a data holder, and your site serves
the ONE Record API, stores logistics objects in its database, verifies partners'
bearer tokens, issues its own, and delivers notifications through Drupal's queue.

```text
Drupal site (your module, your business rules)
        ↓
one_record (this module: container, config, routes, database, queue, Drush)
        ↓
lambda-twelve/one-record (protocol, model, JSON-LD, endpoints, lifecycle)
        ↓
PHP + PSR
```

The module is an adapter. Every protocol decision is made by the SDK; this
module translates Drupal's request, configuration, database, cache, logger,
events and queue into the interfaces the SDK consumes. Where that translation
exposed rough edges in the SDK, they are written down in
[docs/sdk-friction.md](docs/sdk-friction.md) rather than papered over.

## Compatibility

| | Supported |
| --- | --- |
| Drupal | `^10.3 \|\| ^11` |
| PHP | 8.3 and later |
| SDK | `lambda-twelve/one-record ^1.0.0-beta6` |
| Drush (optional) | 12.5 and later, for the `one-record:*` commands |

PHP 8.3 is the SDK's floor. Drupal 10.3 is the oldest maintained line that
runs on it, and nothing in the module needs anything newer, so the range is as
wide as the SDK allows. The module declares every HTTP method on its routes and
uses attribute-based plugins, both available since 10.3.

The module expects Drupal at the web root: the configured base path is both the
URL prefix partners see and the Drupal route. Subdirectory installs are not
supported yet.

## Installation

The SDK comes from Packagist as a pre-release. This module is not published
there yet, so add its repository. Name the SDK in the same `require`: a
pre-release that only a dependency asks for does not pass a project's default
`minimum-stability`, so the module's own constraint is not enough on its own.

```sh
composer config repositories.one-record-drupal vcs https://github.com/lambda-twelve/one-record-drupal
composer require 'lambda-twelve/one-record-drupal:^1.0.0-beta1@beta' 'lambda-twelve/one-record:^1.0.0-beta6@beta'
drush pm:install one_record
```

Installing the module creates nine `one_record_*` tables (see
[Persistence](#persistence)) and the default configuration. Nothing is served
until the two required settings are present; requests under the base path
answer 503 until then.

## Configuration

Settings live in `one_record.settings` and are edited at
*Administration → Configuration → Web services → ONE Record*
(`/admin/config/services/one-record`, permission *Administer ONE Record*) or
through configuration management. The form covers the scalar settings; the two
structured lists (trusted issuers and partners) are managed as exported
configuration.

| Key | Purpose | Default |
| --- | --- | --- |
| `base_url` | Scheme and host, e.g. `https://1r.example.com`. Object IRIs are built from it and stored, so decide before publishing. | required |
| `base_path` | URL prefix the API is served under; server endpoint = base URL + base path | `/one-record` |
| `data_holder` | IRI of the organisation operating the server, normally an object served here | required |
| `data_holder_type` | Class of the holder for server information; empty reads it from the stored holder object | `cargo:Company` |
| `api_versions`, `data_model_versions` | Versions offered; empty means all the SDK implements | `[]` |
| `languages`, `max_body_bytes`, `embedded_depth`, `bulk_logistics_events` | Passed straight to the SDK's `ServerConfig` | SDK defaults |
| `denial` | `forbid` answers 403, `hide` answers 404 | `forbid` |
| `internal_agents` | Agent IRIs with full access; the data holder is always one | `[]` |
| `authentication.issuers` | Trusted token issuers: `issuer`, optional `jwks_url`, optional `public_keys` (PEMs; used instead of JWKS when given) | `[]` |
| `authentication.audience`, `authentication.leeway_seconds` | Token verification | none, 30 |
| `token_endpoint.*` | `enabled`, `path`, `issuer`, `ttl_seconds`, `audience`, `key_id` for tokens this server issues | disabled |
| `partners` | Who this host sends notifications to: `agent`, optional `endpoint`, `token_url`, `client_id`, optional `scope`, `basic_auth` | `[]` |
| `delivery.lease_seconds`, `delivery.max_attempts` | Outbox delivery | 300, 10 |

The module validates with the SDK's own `ServerConfig::problems()`; each
problem is reported on its field in the SDK's words, and the same sentences
appear on the status report and in `drush one-record:status` while the server
is not configured. Changing `base_path` or the token endpoint rebuilds the
router.

### Secrets

Secrets never go into exportable configuration. In `settings.php`:

```php
// The RS256 private key for tokens this server issues (one of the two).
$settings['one_record.signing_key_file'] = '/etc/one-record/signing-key.pem';
$settings['one_record.signing_key'] = "-----BEGIN PRIVATE KEY-----\n...";

// Client secrets for partners' token endpoints, keyed by our client id there.
$settings['one_record.partner_secrets']['our-client-id-at-partner'] = '...';
```

## Routes and the server

One Drupal route is registered per ONE Record endpoint pattern under the base
path (`one_record.server_information`, `one_record.logistics_object`,
`one_record.action_request`, and so on). Every route points at the same thin
controller: Drupal's PSR-7 bridge builds a `ServerRequestInterface`, the SDK's
PSR-15 `OneRecordServer` handles it, and the PSR-7 response is converted back.
Content negotiation, authentication, routing inside the API, 404/405 answers,
the action-request lifecycle and notification fan-out are all the SDK's.

The SDK's unit of work is bound to a database transaction
(`one_record.unit_of_work`). The SDK runs every mutating request, and every
`DataHolder` operation, through it, so whatever an operation writes stands or
falls together: an exception anywhere inside, a listener's included, rolls the
operation back before the SDK turns it into a response, and an acceptance
beaten by a competing decision answers 409 with no grant or revision left
behind. Nested operations join the outer unit as savepoints. The SDK also
stores every decision on an action request before any of its side effects, so
a lost race writes nothing even where the unit of work is not in play, such as
a store called directly. The SDK's own check of the wiring
(`ServerBuilder::check()`) is shown on the status report and by
`drush one-record:status`; with this module's services it has nothing to
report.

Responses carry `Cache-Control: no-store, private` and are never page-cached.
The headers that describe the SDK's representation survive Drupal's response
pass: core would otherwise overwrite `Content-Language` with the site language
and, on a response it deems uncacheable, drop `Last-Modified`; the module sets
the cache policy explicitly and restores the negotiated language and the
validators after core has run.

Because they are ordinary named routes, a `RouteSubscriber` can alter access
requirements, authentication options or anything else per endpoint.

When the token endpoint is enabled, two more routes appear: the token endpoint
at `token_endpoint.path` and the JWKS document at
`{base_path}/.well-known/jwks.json`, where the SDK's resolver looks by default.

## Persistence

The SDK's storage SPI is implemented on Drupal's database API, one class per
interface, without content entities: the domain model stays in the SDK and the
tables hold the JSON-LD the SDK writes plus the columns its queries need.

| Table | Holds |
| --- | --- |
| `one_record_logistics_objects` | Head row per object: latest revision, creation time |
| `one_record_logistics_object_revisions` | Every revision as JSON-LD |
| `one_record_logistics_events` | Events with event code, event date and creation date extracted |
| `one_record_action_requests`, `one_record_action_request_objects` | Requests at API 2.3.0 with status, subscription and expiry projections; objects concerned |
| `one_record_subscription_offers` | Subscriptions offered to publishers |
| `one_record_grants` | Permissions granted to agents, with their source request |
| `one_record_outbox` | Queued notifications and their delivery state |
| `one_record_clients` | OAuth client credentials (hashed) for the token endpoint |

IRIs are compared byte for byte, as the SDK does, through SHA-256 hash columns;
timestamps are UTC microseconds. `saveRevision()` is a compare-and-set
`UPDATE`, so optimistic concurrency is enforced by the database. The stores
pass the SDK's own store contracts (its `Testing\Contract` traits, run as
kernel tests) as well as this module's contract tests, which the SDK's
in-memory reference implementations pass too.

Each store is a service (`one_record.store.*`) aliased under its SDK interface
name. Replace one to keep that part elsewhere; the rest of the module keeps
working. The subscription store derives subscribers from whichever
action-request store is configured, through the SDK's `accepted()` query, and
uses its SQL topic projection only when that store is the module's own.

## Authentication and access

Incoming requests are authenticated by the SDK's `JwtAuthenticator`: RS256
bearer tokens from the configured issuers, each trusted by pinned public keys or
a JWKS document (fetched through Drupal's HTTP client and cached in the
`one_record` cache bin through a PSR-16 adapter that expires items by the
current time, so a long queue run or Drush command does not keep reading an
entry past its TTL). When the token endpoint is enabled, this server's own
issuer and key are trusted too, so internal systems can use the same tokens.

Authorisation is the SDK's grant policy over the `one_record_grants` table:
internal agents may do anything, everyone else needs a grant for the object and
permission in question, and grants from accepted access delegations are honoured
automatically. Grants are given through accepted access-delegation requests,
`drush one-record:grant`, or `GrantAccessPolicy::allow()` on the
`one_record.access_policy` service from your own code.

To use another token scheme or your own rules, override
`one_record.authenticator` or `one_record.access_policy` with your
implementation of the SDK's `Authenticator` or `AccessPolicy` interface.

### Issuing tokens

Enable the token endpoint, provide a signing key in `settings.php`, and create
clients:

```sh
drush one-record:client:create https://1r.example.com/one-record/logistics-objects/holder --label="Warehouse ERP"
```

The secret is printed once. The client then obtains tokens with the OAuth 2.0
client-credentials grant at the configured path, sending its credentials as
form fields or as HTTP Basic; the SDK's `TokenEndpoint` answers. With core's
`basic_auth` module enabled, Basic credentials would otherwise be a Drupal
user login; an authentication provider of this module, sorted above
`basic_auth` and applying to the token path only, keeps them for the SDK
without logging anyone in or touching flood control. Rate limiting is left to
your web server or a middleware.

## Notifications

The SDK fans out notifications into the outbox during the request; the module
writes an `one_record_outbox` row and, once the transaction commits, a queue
item on `one_record_outbox`. The queue worker claims the row, sends the
notification with the SDK client using the partner's client credentials from
the `partners` configuration, and records the result. Transport errors and 5xx,
408 and 429 answers are retried with backoff (1 min to 1 day, up to
`delivery.max_attempts`), as the SDK's `DeliveryVerdict` classifies them; any
other failure or an unknown partner ends delivery.
Cron queues whatever is due, so retries do not depend on the queue item
surviving; a row that is queued twice is attempted once, because the second
worker finds it leased or finished.

Delivery is at least once. Each claim is numbered, and a worker records its
outcome under that number, so a worker that outlived its lease cannot overwrite
what a later attempt recorded; a partner may still see a notification twice and
is given the SDK's notification id as the `Idempotency-Key` to recognise it.

Received notifications raise the SDK's `NotificationReceived` event and are not
stored; subscribe to the event to act on them.

## Events

The SDK dispatches its PSR-14 events (`LogisticsObjectCreated`,
`LogisticsObjectRevised`, `LogisticsEventReceived`, `ActionRequestCreated`,
`ActionRequestStatusChanged`, `NotificationReceived`) through Drupal's
`event_dispatcher`, which is already PSR-14. Subscribe to them by class name:

```php
public static function getSubscribedEvents(): array {
  return [LogisticsObjectRevised::class => 'onRevised'];
}
```

Listeners run synchronously inside the unit of work, after the writes they
report are in place (an accepted delegation's grants, an accepted change's
revision). Object and event fan-out (`LogisticsObjectUpdated` and the like)
is queued after the listener; an action request's own status notification
(Pending on creation, the decision afterwards) is queued *before* the
listener, so a listener that decides the request on the spot cannot put the
decision's notification ahead of the state it decided. Either way the queued
rows belong to the same unit of work: a listener that throws fails the
operation and rolls it back, request, revision and queued notifications
included, and the Drupal queue items are only created once the unit commits.
Treat a listener like a hook that runs inside an entity save: keep it short,
and hand anything that talks to another system to a queue worker or to a
post-transaction callback on the database connection.

## Publishing your own data

Your module publishes through the SDK's `DataHolder`, available as the
`one_record.data_holder` service:

```php
$holder = \Drupal::service('one_record.data_holder');
$stored = $holder->create($logisticsObject);            // revision 1
$holder->update($changedObject);                        // records and applies a change
$holder->announce($stored->object->iri, $partnerAgent); // LOGISTICS_OBJECT_AVAILABLE
$holder->accept($actionRequestIri);
```

Calls outside a request are not wrapped in a transaction; wrap them with
`$connection->startTransaction()` when several must succeed together.

## Drush

| Command | Purpose |
| --- | --- |
| `one-record:status` | Configuration summary and store counts |
| `one-record:client:create\|list\|enable\|disable\|delete` | OAuth clients for the token endpoint |
| `one-record:grant`, `one-record:revoke` | The holder's own grants on an object |
| `one-record:outbox:deliver [--limit]` | Deliver due notifications now |
| `one-record:outbox:prune [--older-than]` | Remove delivered and failed rows |
| `one-record:forget <iri> [--events] [--grants]` | Erase an object |

## Extension points

Everything is a service; override the one you need in your module's
`services.yml` or a `ServiceProvider`.

| Service | Interface | Default |
| --- | --- | --- |
| `one_record.store.*` | SDK `Server\Spi\*Store`, `NotificationOutbox` | Database stores |
| `one_record.authenticator` | SDK `Authenticator` | RS256 JWT over configured issuers |
| `one_record.access_policy` | SDK `AccessPolicy` | Grant policy over the database |
| `one_record.partner_registry` | `PartnerRegistryInterface` | Configuration plus settings.php |
| `one_record.deliverer` | `NotificationDelivererInterface` | SDK client over Drupal's HTTP client |
| `one_record.clock` | PSR-20 | Drupal's time service, millisecond precision |
| `one_record.simple_cache` | PSR-16 | The `one_record` cache bin |
| `one_record.client_factory` | | Per-partner SDK clients |

Routes can be altered per endpoint with a `RouteSubscriber`.

## Local development

Development runs through [DDEV](https://ddev.com); no local PHP is needed.
The SDK working tree is expected beside this repository as `../one-record` and
is mounted into the container, where the hidden `.dev/` project installs Drupal
around the module and reads the SDK through a Composer path repository.

```sh
ddev start
ddev install          # composer install, link the module, install Drupal, enable one_record
ddev test             # PHPUnit through Drupal core's test configuration
ddev phpstan          # PHPStan (phpstan-drupal) at level 8
ddev phpcs [fix]      # Drupal and DrupalPractice coding standards
ddev link-module      # re-link after adding a top-level file to the module
ddev drush one-record:status
```

Edits to the SDK in `../one-record` take effect immediately. The module is
linked into the site entry by entry rather than as one symlink, because
Drupal's extension scans follow symlinks and would otherwise find the site
inside the module. The same scripts run in GitHub Actions against Drupal 10.3
and 11 on PHP 8.3 and 8.4.

## Documentation

- [docs/sdk-friction.md](docs/sdk-friction.md): what the SDK could change so
  every framework integration gets simpler.
- [CHANGELOG.md](CHANGELOG.md)

## Licence

Apache-2.0, like the SDK. See [LICENSE](LICENSE) and [NOTICE](NOTICE).
