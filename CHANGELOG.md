# Changelog

All notable changes to this module are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

Nothing yet.

## [1.0.0-beta1] - 2026-10-05

First release: the module as a Drupal citizen of the SDK's 1.0.0-beta6, with
the SDK's store contracts green against the database stores, three
independent adversarial review rounds and a readiness review folded in. The
*Fixed* entries below record what those reviews found in the pre-release code,
so a reader of the reviews can follow each finding to its resolution.

### Fixed

- Findings of the module's first adversarial review (`review/adversarial1.md`):
  - AR-001: transport failures and token-endpoint outages are retried;
    before, both ended delivery for good. The defect was the SDK's
    (`DeliveryVerdict` saw only the wrapper) and is fixed in SDK beta5,
    which the module requires; the deliverer carries no workaround.
  - AR-002: the installation instructions name the SDK pre-release in the
    same `composer require`, which a project with default stability needs;
    CI resolves the documented command against a clean consumer project.
  - AR-003: the SDK's `Content-Language` and `Last-Modified` survive Drupal's
    response pass. Responses carry an explicit `Cache-Control: no-store,
    private` and a subscriber restores the representation headers core
    overwrites.
  - AR-004: HTTP Basic credentials on the token endpoint reach the SDK when
    core's `basic_auth` is enabled, through an authentication provider that
    claims the token path and authenticates nobody.
  - AR-005: the PSR-16 cache adapter expires items by the current time, and
    a TTL of zero or less stores nothing.
  - AR-006: replacing an action request whole rewrites the objects it
    concerns, so a former object's audit trail no longer lists it. SDK beta6
    then fixed the `save()` envelope the other way, objects set once by the
    first save, so the module's test of moving a request was withdrawn; the
    rewrite stays as a harmless guard.
- Readiness review of the beta6 repin (`review/beta6-readiness.md`):
  - B6-001: stored settings the SDK rejects (a configuration import can
    store what the form refuses) answer 503 and show as an error on the
    status report and in `drush one-record:status`, instead of throwing
    when the handler or the command is built; a stored holder that is not
    an IRI no longer breaks building the access policy either.
  - B6-002: the README states which notifications are queued before a
    listener runs (an action request's own status) and which after (object
    and event fan-out), and what the transaction still guarantees.
- Findings of the second adversarial review (`review/adversarial2.md`):
  - AR2-001: an outbox claim is a compare-and-set on the attempt number
    the worker read, so a worker paused inside its claim cannot come away
    with a later worker's number and overwrite that worker's outcome.
  - AR2-002: the token endpoint's authentication provider claims the token
    path for every credential mechanism, so a session cookie on a
    client_secret_post request no longer hands it to Drupal's cookie
    provider and a 403.
  - AR2-003: the consumer-install CI check pins the SDK to the minimum
    release the module's constraint declares instead of describing a
    shallow checkout.
  - AR2-004: the subscription store derives subscribers from the configured
    action-request store, so replacing that store alone keeps notifications
    flowing; the SQL topic projection is used only over the module's own.
- Operations are atomic: the SDK's `UnitOfWork` is bound to a database
  transaction, nested as savepoints, in place of a handler that committed
  every response below 500. Before, an acceptance beaten by a competing
  decision answered 409 but kept the grants it had written, and a failing
  listener left a half-finished `DataHolder` operation stored (the Laravel
  wrapper's review finding AR1-001, which applied here too). SDK beta3 also
  stores each decision before its side effects; the transaction remains what
  undoes a decision when something after it fails.
- An outbox worker whose lease ran out can no longer overwrite the state a
  later attempt recorded: every outcome names its attempt and is ignored once
  the row has been claimed again (AR1-002).
- Verifying an unknown client id costs the same hashing work as a known one:
  the dummy hash is prepared at install, kept in state and read before the
  lookup, instead of being made on first use in each process (AR1-004).
- The event store refuses an event IRI that already exists, with the SDK's
  `ALREADY_EXISTS` error, instead of overwriting the stored event; found by
  the SDK's own `LogisticsEventStore` contract, which the stores now run.
- The outbox keeps the id the SDK gave each notification in its own unique
  column and the deliverer sends it as the `Idempotency-Key`; before, the id
  was dropped on storage and notifications went out without one.

### Changed

- Requires SDK 1.0.0-beta6. The settings form, the status report and
  `drush one-record:status` report what is wrong with the settings in the
  SDK's own sentences (`ServerConfig::problems()`), each on its field. The SDK's store contracts run against the
  database stores as kernel tests, through the contract traits beta2 ships
  for hosts whose tests extend a framework base class; the tests use the
  SDK's doubles (`FixedClock`, `HeaderAuthenticator`,
  `RacingActionRequestStore`) instead of copies.
- The status report and `drush one-record:status` show the SDK's wiring
  findings (`ServerBuilder::check()`), and the deliverer classifies failures
  with the SDK's `DeliveryVerdict`.
- An event query loads and expands only the page it returns; sorting and
  paging work on the extracted columns, so a small page over a long history
  no longer hydrates every candidate.
- The Drupal routes are built from the SDK's route table
  (`ServerBuilder::routes()`); the key resolvers are chained by the SDK's
  `ChainKeyResolver`; events are hydrated with `LogisticsEvent::fromStored()`.
  The module's copies of each are gone.

### Added

- Database implementations of every SDK storage SPI (logistics objects with
  revisions, events, action requests, subscriptions, grants, outbox) on
  Drupal's database API, with contract tests shared with the SDK's in-memory
  stores.
- Container wiring of the SDK server: configuration translated into
  `ServerConfig`, SDK interfaces as service aliases, one Drupal route per
  endpoint, a thin PSR-7 controller and a per-request database transaction.
- RS256 bearer-token authentication over configured issuers (pinned keys or
  JWKS), an OAuth 2.0 client-credentials token endpoint with hashed client
  secrets, and a JWKS document.
- The SDK's grant-based access policy over the database with internal agents
  from configuration.
- Notification delivery through Drupal's queue with the SDK client, database
  driven retries and cron sweeps.
- Drush commands for diagnostics, clients, grants, the outbox and forgetting.
- A settings form, configuration schema and DDEV-based development setup.
