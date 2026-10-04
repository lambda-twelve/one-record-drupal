# Adversarial review — round 2, SDK beta5

Reviewed 2026-10-04. **The six original findings are addressed for their tested cases. Four new P2 findings remain.** I recommend fixing the outbox claim race and the broken CI installation check before publishing beta1, and resolving or explicitly narrowing the authentication and store-replacement behavior described below.

This review covers the complete current working tree, including staged, unstaged and untracked implementation changes, above module commit `dee96e9272dae64988fd49d717a825b2c917c04c`. The sibling SDK is clean at `52473b5da93349caa19e6880c407e2807b720c24`, matching local tag `1.0.0-beta5`. No implementation fixes were made.

| ID | Priority | Finding |
| --- | --- | --- |
| AR2-001 | P2 | A paused outbox claimant can acquire a later worker's attempt number |
| AR2-002 | P2 | A session cookie makes valid OAuth form credentials return 403 |
| AR2-003 | P2 | The new consumer-install CI job cannot discover the SDK version |
| AR2-004 | P2 | Replacing the action-request store silently disconnects subscription delivery |

All four findings have reproductions. P2 reflects a confirmed defect with a specific trigger; no new P1 defect was established in this round.

## AR2-001 — Claiming a notification and reading its attempt number are not atomic

**Location:** [DatabaseNotificationOutbox.php](../src/Store/DatabaseNotificationOutbox.php), lines 109–118; outcome checks at lines 167–175.

`claim()` increments `attempts` in an atomic UPDATE, then obtains the claim's attempt number using a separate, unprotected `find()`. That SELECT can read a later worker's claim. The outcome compare-and-set is correct only if each worker was given its own attempt number, which this sequence does not guarantee.

**Reproduced using two independent MariaDB connections and a controlled interleaving:**

1. A claims a due row at T. Its UPDATE commits `attempts=1` and a lease ending at T+300 seconds.
2. Suspend A after the UPDATE, before its SELECT. This was injected through Drupal's statement-execution-end event; no production method was mocked.
3. B, using a separate connection, reclaims at T+301 and receives attempt 2.
4. Resume A. Its SELECT also returns attempt 2.
5. A calls `markFailed()` using the attempt number it received. The stale failure is accepted and sets `failed_at` on B's claim.

The probe's expected-false assertion failed with:

```text
A returned attempt 2 while B owns attempt 2;
stale failure must not be accepted.
Failed asserting that true is false.
```

This requires a pause exceeding the configured lease between those two database statements, such as a suspended worker. It is narrower than the already-tested case where A pauses during HTTP delivery. When it occurs, A can permanently fail B's in-progress notification or overwrite its retry schedule. Duplicate external delivery may be acceptable under the documented at-least-once contract; sharing a claim's ownership number is not.

**Fix direction:** make the claim update and capture of its resulting state atomic, for example with a short database transaction retaining the row lock through the SELECT, or a unique per-claim token recorded by the UPDATE. Release that transaction before HTTP delivery. Extend the lease test to cover an interleaving *inside* `claim()`, not just after it has returned.

## AR2-002 — The token route now rejects form credentials when a session cookie is present

**Location:** [TokenEndpointProvider.php](../src/Authentication/Provider/TokenEndpointProvider.php), lines 39–43; [Routes.php](../src/Routing/Routes.php), lines 91–95.

The new route permits only `one_record_token_endpoint` authentication, but that provider applies only when `PHP_AUTH_USER` exists. For `client_secret_post`, it does not apply. A request carrying Drupal's session cookie is therefore claimed by core's `cookie` provider, which the route's explicit `_auth` list excludes. Drupal answers 403 before the SDK can verify the OAuth form credentials.

An authenticated Drupal account is not required: an anonymous or stale session cookie is enough for `Cookie::applies()` to claim the request. This can affect clients with a cookie jar, including API tools that have previously visited the Drupal site. It is a regression adjacent to the first round's Basic-auth fix.

**Reproduction:** in a kernel fixture with `system`, `user`, `basic_auth` and `one_record`, install the user schema/configuration, enable the token endpoint and configure its signing key. Create a valid OAuth client. POST `grant_type`, `client_id` and `client_secret` as form parameters:

| Request | Result |
| --- | --- |
| No session cookie | 200, token issued |
| Same credentials plus the site's session cookie and an empty anonymous session | 403 |

The second request failed its expected-200 assertion. The control request succeeded in the same fixture. Existing `TokenBasicAuthTest` covers Basic credentials and form credentials without a cookie, so it does not detect this case.

**Fix direction:** make the token endpoint's authentication selection consistent for both credential mechanisms. Claiming the enabled token path regardless of Basic-header presence can keep Drupal session authentication out of this endpoint; alternatively, deliberately support compatible providers in the route's policy. Keep the SDK as the authority for issuing tokens. Add valid/invalid form-credential tests with both anonymous and authenticated sessions, and verify that ordinary Drupal routes remain unaffected.

## AR2-003 — The new Composer CI check fails before running Composer

**Location:** [.github/workflows/ci.yml](../.github/workflows/ci.yml), lines 28–34.

The SDK checkout leaves `ref`, `fetch-depth` and `fetch-tags` at their defaults, then immediately runs `git describe --tags --abbrev=0`. Checkout v4 fetches one commit by default, with tag fetching disabled. Consequently the fresh checkout has no tag from which to derive `sdk_version`. These defaults are documented in the official [checkout v4 inputs](https://github.com/actions/checkout/blob/v4/README.md#usage).

**Reproduction:** clone the local SDK with `--depth 1 --no-tags` over `file://`, matching the relevant checkout behavior. Run the workflow's `git describe` command in that clone. It exits 128:

```text
fatal: No names found, cannot describe anything.
```

The source repository has the beta5 tag; its absence from the shallow clone causes the failure. Under the Actions shell's error handling, the assignment fails and the consumer project's Composer command is never reached.

This does not reopen the original Composer stability defect: an offline resolver experiment using the updated README requirements successfully resolved beta5 with default stable root settings. It breaks the new CI check intended to protect that installation path.

**Fix direction:** check out the intended SDK release explicitly and use that known version, or fetch enough history and tags for `git describe`. Prefer validating the declared minimum SDK release over dynamically relabeling default-branch code with the nearest tag's version. Run the consumer job from a fresh checkout to verify the entire step.

## AR2-004 — The subscription store bypasses the configured action-request store

**Location:** [DatabaseSubscriptionStore.php](../src/Store/DatabaseSubscriptionStore.php), lines 27–44; [one_record.services.yml](../one_record.services.yml), lines 39–44. The supported behavior is stated in [README.md](../README.md), lines 165–167.

The module advertises that each storage service can be replaced independently. However, `DatabaseSubscriptionStore::subscribersOf()` reads `one_record_action_requests` directly instead of querying the configured `ActionRequestStore`. Replacing only the action-request service makes newly accepted subscriptions invisible to the default subscription service. If old rows remain in the SQL table, it can instead continue reading stale subscription decisions from the abandoned store.

**Reproduction:** replace `one_record.store.action_requests` with the SDK's `InMemoryActionRequestStore` before constructing `one_record.data_holder`, leaving the other services intact. Create a logistics object and call `DataHolder::subscribe()` for a partner:

```text
Returned request status: Accepted
Configured action-request store's accepted subscriptions: 1
Default subscription store's subscribersOf(object): 0
```

The expected-one-subscriber assertion failed. The in-memory store is a test stand-in for another backend; the same hard-coded table lookup bypasses a persistent replacement or a decorator with different read semantics. Fan-out uses `subscribersOf()`, so operations can succeed while producing no notification for an accepted subscription.

**Fix direction:** derive subscribers from the configured action-request SPI, preserving any SQL optimization only when its backing-store assumptions are explicitly satisfied. If independent replacement is intentionally unsupported, document and enforce replacement of the coupled pair rather than silently accepting incompatible wiring. Add a container-level integration test that replaces only the action-request store; testing complete database and complete in-memory bundles separately cannot expose this coupling.

## First-round findings: disposition

| Original finding | Result in this round |
| --- | --- |
| AR-001: transient errors permanently fail delivery | Resolved for tested cases. Beta5 walks exception causes and preserves token-endpoint statuses; the updated delivery regression passes. |
| AR-002: missing root SDK stability allowance | Resolved in the README. Updated root requirements resolve successfully in an isolated offline consumer; see AR2-003 for the new CI defect. |
| AR-003: protocol headers changed by Drupal | Resolved for tested GET/HEAD paths. Explicit cache control and the response subscriber preserve modification time and negotiated language. |
| AR-004: Basic credentials intercepted by Drupal | Resolved for the original Basic-auth case. The adjacent form-plus-cookie regression is AR2-002. |
| AR-005: cache expiration uses request-start time | Resolved for the tested TTLs. The adapter checks current time and removes non-positive/expired entries. |
| AR-006: obsolete action-request object associations | Resolved. Pivot rows are rewritten, and beta5's expanded replacement contract passes against the database store. |

## Validation and limits

The existing suite passed: **64 tests, 771 assertions** on PHP 8.3.30, PHPUnit 11.5.56, installed Drupal 11.4.8 and the local DDEV MariaDB environment.

```sh
ddev exec 'cd /var/www/html/.dev && vendor/bin/phpunit -c web/core web/modules/contrib/one_record/tests'
```

Three additional temporary kernel probes reproduced AR2-001, AR2-002 and AR2-004 as assertion failures. The outbox probe used two actual database connections, no surrounding transaction, and deterministic statement interleaving with explicit T/T+301 timestamps; it was not a stress test or a real five-minute process suspension. The CI reproduction failed with exit 128. The separate offline Composer dry-run succeeded with 69 planned installs and installed nothing.

Temporary probe files were removed after review. Only this report was added to the repository by the review. The earlier report and existing implementation changes were left intact.

This round did not execute GitHub Actions remotely, install from public Composer repositories, exercise external partner servers, or run Drupal 10, minimum-version, PostgreSQL or SQLite matrices. In particular, the green local suite does not establish those compatibility claims. Static analysis and coding standards were not rerun because the review made no implementation changes.
