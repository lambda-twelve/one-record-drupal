# Adversarial review — beta1

Reviewed 2026-10-04. **Recommendation: hold beta1.** Two high-priority issues prevent the documented installation or permanently stop notification delivery after transient failures. Four further defects affect the Drupal boundary and store/cache contracts.

The review covers the working tree, including existing staged, unstaged and untracked implementation changes, on top of `dee96e9272dae64988fd49d717a825b2c917c04c`. The sibling SDK was clean at `95bb3e193cea53633bf66bfa4f09667e88a433ae`, matching its local `1.0.0-beta4` tag. No implementation fixes were made.

| ID | Priority | Finding |
| --- | --- | --- |
| AR-001 | P1 | Transport failures and token-endpoint outages permanently fail notifications |
| AR-002 | P1 | The documented Composer installation fails with default stability settings |
| AR-003 | P2 | Drupal removes `Last-Modified` and overwrites the negotiated language |
| AR-004 | P2 | Core Basic Authentication blocks valid OAuth client credentials |
| AR-005 | P2 | PSR-16 cache entries remain readable after their TTL expires |
| AR-006 | P2 | Replacing an action request leaves it associated with its former objects |

P1 means fix before publication; P2 means a confirmed functional defect with a narrower trigger or impact. All six findings have runtime or Composer-solver reproductions, described below.

## AR-001 — P1: transient delivery errors become permanent failures

**Location:** [ClientNotificationDeliverer.php](../src/Notification/ClientNotificationDeliverer.php), lines 40–46; [Delivery.php](../src/Notification/Delivery.php), lines 48–51. The underlying classification defect is in SDK beta4's [DeliveryVerdict](../../one-record/src/Client/DeliveryVerdict.php), lines 22–31.

`DeliveryVerdict::of()` retries a PSR HTTP client exception, but `OneRecordClient::send()` wraps that exception in the SDK's `ClientException`. That wrapper extends `RuntimeException`, not the PSR exception interface. Passing the wrapper to the verdict therefore returns `Reject`. The Drupal deliverer converts it to `DeliveryRejected`, and `Delivery::attempt()` sets `failed_at` immediately. The row is excluded from future due sweeps, regardless of `delivery.max_attempts`.

The token provider has the same problem: transport failures are wrapped, and an OAuth HTTP 503 is flattened into a generic `ClientException` without a structured status. A temporary identity-provider outage can permanently fail every notification attempted during it.

**Reproduced with the real delivery services and scripted Guzzle HTTP:**

1. Configure a partner and secret, enqueue a notification, and script successful token and server-information responses.
2. Throw `GuzzleHttp\Exception\ConnectException` on `POST /notifications`.
3. Observe `GivenUp`, `attempts=1`, and a non-null `failed_at`, instead of `Retrying`.
4. In a fresh fixture, return 503 from the token endpoint. The same premature terminal state occurs before any notification is sent.

Both probes failed their expected-`Retrying` assertion. Existing `DeliveryTest::testRetriesAndGivesUp()` exercises a 503 from the notification endpoint, which follows the different, correctly classified `OneRecordHttpException` path.

**Required change:** fix the exception contract/classification in the SDK and require the corrected release, or provide a narrowly scoped adapter fix. Preserve transport causes and structured OAuth error statuses so retryable errors can be distinguished from invalid credentials and malformed configuration. Do not retry every SDK `ClientException` indiscriminately. Add delivery-level tests for connection failure, token transport failure and token 503/429, alongside permanent failures.

## AR-002 — P1: the installation instructions omit the root SDK stability allowance

**Location:** [README.md](../README.md), lines 48–51; [composer.json](../composer.json), SDK requirement.

The documented `composer require lambda-twelve/one-record-drupal:@dev` allows development stability for the wrapper only. The wrapper's transitive `^1.0.0-beta4@beta` requirement does not grant the consuming root project permission to install a beta SDK. With Composer's default `minimum-stability: stable` and beta4 as the available SDK release, dependency resolution fails.

**Reproduction:** an isolated offline Composer repository containing the actual wrapper metadata, SDK beta4 metadata, and dependency metadata from the installed development lock file failed with:

```text
require lambda-twelve/one-record ^1.0.0-beta4@beta
-> found lambda-twelve/one-record[1.0.0-beta4]
but it does not match your minimum-stability.
```

Adding `"lambda-twelve/one-record": "^1.0.0-beta4@beta"` to the same root project's requirements made the dry-run resolve successfully, with 69 installs. No dependencies were actually installed by this experiment.

**Required change:** document a command that grants both allowances explicitly, for example:

```sh
composer require lambda-twelve/one-record-drupal:@dev \
  'lambda-twelve/one-record:^1.0.0-beta4@beta'
```

Update the wrapper constraint when beta1 is tagged, and use the fixed SDK version needed for AR-001. Add a clean consuming-project installation check. The current `.dev` project uses `minimum-stability: dev`, directly requires the SDK, and links the wrapper manually, so it cannot catch this release-installation failure.

## AR-003 — P2: the Drupal response pipeline changes SDK protocol headers

**Location:** [ServerController.php](../src/Controller/ServerController.php), lines 36–37; [Routes.php](../src/Routing/Routes.php), server route options. The core interaction is `Drupal\Core\EventSubscriber\FinishResponseSubscriber::onRespond()` and `setResponseNotCacheable()`.

Returning the PSR-7 response through Drupal's bridge does not preserve its headers through the rest of the kernel. The SDK does not set an explicit `Cache-Control`; core therefore applies its default uncacheable-response handling and removes `Last-Modified`. Core also unconditionally sets `Content-Language` to Drupal's current language, overwriting the SDK's negotiated value. The route's `no_cache` option does not protect either header.

**Reproduction:** create a logistics object with `one_record.data_holder`, then read it as the holder directly through `one_record.handler` and through the Drupal HTTP kernel:

| Header | Direct SDK handler | Drupal HTTP response |
| --- | --- | --- |
| `Last-Modified` | `Fri, 02 Oct 2026 12:00:00 GMT` | absent |
| `Content-Language`, requesting `en-US` | SDK negotiates `en-US` | `en` |

The timestamp comes from the test fixture's fixed clock. Both kernel assertions failed. The missing date affects object responses and the same response path used by events, action requests and audit trails. Clients cannot use the advertised modification metadata for polling; the SDK client's parsed `lastModified` becomes null. The language header no longer describes the SDK-negotiated representation.

**Required change:** preserve SDK response metadata through Drupal's response subscribers, while retaining the intended cache policy. An explicit appropriate cache-control policy can prevent core's default date stripping; language preservation also needs handling at the Drupal boundary. Test final GET and HEAD responses for dates, language, revision and type headers, plus cache behavior. Assertions on the handler alone will miss this defect.

## AR-004 — P2: Drupal Basic Authentication intercepts OAuth Basic credentials

**Location:** [Routes.php](../src/Routing/Routes.php), lines 88–95, and the absence of integration with Drupal's authentication-provider pipeline.

When core's `basic_auth` module is enabled, an OAuth `client_secret_basic` request is picked up as Drupal user authentication before the token controller runs. The token route has no `_auth` option, and `basic_auth` is not a global provider. `AuthenticationSubscriber::onKernelRequestFilterProvider()` consequently denies the request, even though the OAuth credentials are valid in `one_record_clients`.

**Reproduction:** enable `system`, `user`, `basic_auth` and `one_record` in a kernel fixture; install the user entity schema/configuration; configure a signing key and enable the token endpoint. Create an OAuth client for the holder. Posting its credentials as form fields returns 200. Posting the same credentials using HTTP Basic, with only `grant_type=client_credentials` in the form, returns **403 and a Drupal HTML access-denied page**, not an OAuth token response.

This breaks a supported SDK credential mechanism on a normal Drupal configuration. It also sends OAuth attempts through Drupal user-authentication/flood logic.

**Required change:** define how ONE Record authentication coexists with Drupal providers, so OAuth client authentication reaches the SDK without being treated as a Drupal user login. A route-level allowance alone should also be checked for unwanted user-authentication and flood side effects. Add the enabled-`basic_auth` case to `TokenFlowTest`; its current form-credential test does not cover this path.

## AR-005 — P2: PSR-16 expiry follows request-start time rather than current time

**Location:** [SimpleCache.php](../src/Cache/SimpleCache.php), lines 29–38, 97–98 and 114–120.

The adapter computes expiration from current time, but delegates all expiry enforcement to the Drupal backend. Drupal's database backend compares expiry with `getRequestTime()`, which remains fixed during the request or worker. Thus a cache item that expires during a long-running queue/Drush process can remain readable for the rest of that process. `has()` has the same problem. A zero TTL is also stored and can immediately be returned because the backend accepts expiry equal to request time.

**Reproduced against `one_record.simple_cache`:**

```php
$cache->set('review-zero', 'stale', 0);
$cache->get('review-zero', 'missing'); // Actual: 'stale'.

$cache->set('review-positive', 'stale', 1);
sleep(2);
$cache->get('review-positive', 'missing'); // Actual: 'stale'.
```

Both probes expected `missing` and failed. This cache carries JWKS documents, refresh-cooldown markers and server information, so expiry matters beyond a generic cache conformance detail. The SDK token provider independently checks its token's `expiresAt`; this finding does **not** establish that expired bearer tokens are reused.

**Required change:** enforce expiration against current time in the PSR-16 adapter on reads and `has()`, and delete entries immediately for non-positive TTLs. Cover integer TTLs, inverted/zero `DateInterval` values, and reads after time advances within the same process.

## AR-006 — P2: whole-request replacement leaves stale object associations

**Location:** [DatabaseActionRequestStore.php](../src/Store/DatabaseActionRequestStore.php), lines 65–79 and 168–185.

The SDK's `ActionRequestStore::save()` explicitly permits replacing a request whole. The implementation replaces the main row/document, but only merges new rows into `one_record_action_request_objects`; it never removes associations that are absent from the replacement. `auditTrail()` and `pendingChanges()` trust this pivot table.

**Reproduction:** save a valid verification request with IRI R concerning object A. Save another valid verification request with the same IRI R concerning object B. `get(R)` now describes B, but `auditTrail(A, new AuditTrailQuery())` still returns R, including its B payload. The expected-empty assertion failed.

This corrupts object-specific history and can expose the replacement object's verification data through the former object's audit trail, where the SDK checks access to the former object. The trigger is a host/importer using the documented store replacement operation; the review did not establish a public endpoint that lets an arbitrary caller retarget an existing request.

**Required change:** replace the request's pivot rows within the existing transaction, or otherwise remove obsolete associations before adding the current set. Extend the shared store contract with replacement that changes the concerned object; current replacement tests exercise status changes without changing the object.

## Validation and limits

The existing suite passed on the local DDEV environment: **60 tests, 701 assertions**, using PHP 8.3.30, PHPUnit 11.5.56, installed Drupal 11.4.8 and the MariaDB configuration in `.ddev/config.yaml`:

```sh
ddev exec 'cd /var/www/html/.dev && vendor/bin/phpunit -c web/core web/modules/contrib/one_record/tests'
```

Eight additional temporary kernel probes produced **eight assertion failures, no setup errors**: two delivery failures, two response-header failures, two cache-expiry failures, one Basic-auth failure and one action-request replacement failure. The Composer experiment separately demonstrated failure without the root SDK stability allowance and success with it. Temporary probe files were removed after review; their fixtures and expected/actual outcomes are described above.

Inspection also covered service wiring, unit-of-work and nested transaction handling, outbox claims/outcomes, SQL projections, credentials/signing, configuration, hooks, Drush and CI. Passing transaction/store tests are useful evidence, but this review did not run concurrent database sessions or reproduce process death between outbox operations.

Drupal 10, minimum supported Drupal versions, PostgreSQL, SQLite, external partner servers and a public Composer download/install were not exercised. CI currently selects version ranges and the sibling SDK's default branch, rather than proving installation against the declared minimum releases. Re-run the relevant matrix and a clean consumer installation with the corrected SDK before publication.
