# Adversarial review 2 — recheck of the author's response

Reviewed 2026-10-04 against [the author's response](adversarial2-response.md) and the current working tree. **All four round-2 findings are closed. No remaining blocker from that round was reproduced, and no new defect was identified in the fixes examined.**

This is a focused verification of the response, not a new review of the entire module. The module remains on commit `dee96e9272dae64988fd49d717a825b2c917c04c` plus its existing working-tree changes. The sibling SDK is unchanged and clean at `52473b5da93349caa19e6880c407e2807b720c24`, the commit referenced by `1.0.0-beta5`.

| Finding | Disposition | Verification |
| --- | --- | --- |
| AR2-001: outbox claim ownership race | Closed | The compare-and-set captures the caller's own attempt number. The original two-connection interleaving now passes. |
| AR2-002: session cookie blocks OAuth form credentials | Closed | The enabled token path is claimed regardless of credential mechanism. Valid and invalid form credentials with session cookies reach the SDK. |
| AR2-003: CI cannot discover the SDK version | Closed | The workflow extracts `1.0.0-beta5` from the declared constraint and explicitly checks out that release. The local version step and offline consumer resolution both pass. |
| AR2-004: replacement request store disconnects subscriptions | Closed | Subscriber discovery follows the configured request store. Independent testing confirms notification fan-out and revocation with only that store replaced. |

## Verification details

**AR2-001:** [DatabaseNotificationOutbox.php](../src/Store/DatabaseNotificationOutbox.php), lines 109–150, reads the current attempt count and conditions the UPDATE on that count. The returned claim is constructed from the successful update's known values, without a subsequent SELECT. This is a valid alternative to the transaction-based fix suggested in the original review.

I repeated the original pause **after A's UPDATE**, using two independent MariaDB connections and Drupal's statement-execution-end event. B reclaimed at T+301. A correctly returned attempt 1; B returned attempt 2. A's subsequent failure and retry updates were both rejected, while B could record delivery. The author's added test separately covers a pause **before leasing**, where the stale compare-and-set correctly returns null. Both windows are covered.

**AR2-002:** [TokenEndpointProvider.php](../src/Authentication/Provider/TokenEndpointProvider.php) no longer requires a Basic header to claim the token path. The expanded `TokenBasicAuthTest` passes. An independent probe also sent form credentials with a session cookie and an explicit session, testing both an anonymous session and one containing a nonzero `uid`. Valid credentials returned 200, invalid credentials returned 401, and Drupal's current user remained anonymous. The nonzero-UID fixture was synthetic; it was not a browser login test.

**AR2-003:** The exact version-extraction command from [.github/workflows/ci.yml](../.github/workflows/ci.yml) returned `1.0.0-beta5`. Its checkout `ref` and path-repository version now use that value, eliminating the dependency on locally fetched tag history. The local SDK checkout was verified against the tag's commit. The workflow's Composer require command succeeded in a clean temporary consumer using the actual module/SDK path repositories and offline dependency metadata: 69 planned installs, exit 0. Nothing was installed. GitHub Actions itself was not run remotely.

**AR2-004:** [DatabaseSubscriptionStore.php](../src/Store/DatabaseSubscriptionStore.php), lines 30–49, receives the configured `ActionRequestStore`. The SQL optimization calls that instance when it is the module's database implementation; replacements use the SPI's `accepted()` method. The new `StoreReplacementTest` passes. An independent end-to-end probe replaced only the action-request store, accepted a subscription, updated an object and observed one pending notification. After revoking the subscription, another update produced no additional notification. The SQL action-request table remained empty throughout.

## Results and limits

- Full existing kernel suite: **66 tests, 794 assertions passed**.
- Independent recheck probes: **3 tests, 24 assertions passed**.
- CI version extraction and offline Composer resolution: passed.
- `git diff --check`: passed.

Tests ran on the existing DDEV environment with PHP 8.3.30, PHPUnit 11.5.56, Drupal 11.4.8 and MariaDB. The outbox test used controlled interleaving and explicit timestamps, not a prolonged process suspension or stress test. Drupal 10, other database engines, public dependency downloads and the remote CI matrix remain outside this recheck.

The author's response accurately describes the fixes. No additional SDK change was required. Only this report was added by the recheck; temporary probes were removed, and implementation files and earlier review documents were left intact.
