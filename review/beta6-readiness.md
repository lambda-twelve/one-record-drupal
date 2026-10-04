# Beta6 SDK alignment — beta release readiness

Reviewed 2026-10-05. **No beta-blocking regression found. The module remains suitable for a beta release on the verified stack, with two low-priority follow-ups below.** The previously closed adversarial findings remain covered by passing tests. This is a focused upgrade review, not a guarantee across every supported environment.

The module is commit `dee96e9272dae64988fd49d717a825b2c917c04c` plus the existing staged and unstaged implementation changes. The sibling SDK is clean at `14c88fb79f14d44dcc491ea1bd4e20576469129d`, exactly the commit behind `1.0.0-beta6`. I reviewed the SDK's beta5-to-beta6 runtime changes against the Drupal adapters, configuration, persistence, authentication, notifications and transaction boundaries.

## Findings

### B6-001 — P3: rejected stored settings bypass the new diagnostics in Drush and handler construction

**Locations:** [OneRecordCommands.php](../src/Drush/Commands/OneRecordCommands.php), lines 101–121; [HandlerFactory.php](../src/Server/HandlerFactory.php), lines 33–37; [RequirementsHooks.php](../src/Hook/RequirementsHooks.php), lines 42–51.

`isConfigured()` checks only that `base_url` and `data_holder` are nonempty. The status hook correctly consults `problems()`, but Drush and the handler factory still treat that two-field check as sufficient to construct the SDK services.

**Reproduction:** on an otherwise configured kernel, save `max_body_bytes: 0` directly through Drupal configuration, as an import or override could do. `problems()` reports “The request body limit must be a positive number of bytes.” The status hook returns `REQUIREMENT_ERROR`, while both `OneRecordCommands::status()` and construction of `one_record.handler` throw that `InvalidArgumentException`. Thus Drush does not produce its diagnostic table, and the hook's promise that these requests “answer 503” is not implemented by the handler factory. The probe verified the construction exception; it did not assert the final rendered HTTP error page.

**Suggested fix:** use `problems()` before constructing SDK services in both paths, report all problems in Drush, and return the unconfigured handler for rejected settings if 503 is the intended behavior. Keep the distinction between missing settings and invalid settings in the status report.

This is nonblocking for beta: it requires invalid stored configuration, which the settings form rejects. Valid configurations and the normal unconfigured installation path pass. The broader invalid-configuration construction weakness predates this repin; beta6's new positive-body-limit validation and the new diagnostic promises expose it here.

### B6-002 — P3: listener-order documentation overstates the beta6 guarantee

**Location:** [README.md](../README.md), lines 247–253.

The README says listeners run before notification fan-out. Beta6 now queues the Pending status notification **before** `ActionRequestCreated`, and queues a decision's status notification **before** `ActionRequestStatusChanged`. This is intentional: a listener may advance the request synchronously without reversing the notification order. The general statement is now misleading to integrators writing those listeners.

**Suggested fix:** distinguish action-request status notifications from logistics-object/event fan-out. Retain the transaction guarantee: queued status rows are still rolled back when a listener throws, and Drupal queue items are scheduled after commit. The integration probes below confirm both ordering and rollback.

## Verification

| Check | Result |
| --- | --- |
| Full module kernel suite | **65 tests, 783 assertions passed** |
| Independent beta6 integration probes | **4 tests, 34 assertions passed** |
| Invalid-settings diagnostics probe | **1 test, 6 assertions passed**, reproducing B6-001; one dependency deprecation when loading Drush |
| PHPStan, configured level 8 | Passed |
| Drupal coding standards, 68 files | Passed |
| `composer validate --strict --no-check-publish` | Passed |
| CI's SDK-version extraction expression | Returned `1.0.0-beta6` |
| Documented Composer require command in an isolated consumer | Offline dry run passed: **69 planned installs**, including SDK beta6 |
| `git diff --check` | Passed |

The temporary integration probes exercised the real Drupal kernel, database stores and services:

- A creation listener accepted a subscription synchronously. The holder returned the accepted request, the database agreed, and the outbox contained Pending then Accepted. Two queue items appeared after commit. A later listener failure after Pending was enqueued rolled back that request and notification without adding a queue item.
- An integral `xsd:double` survived database hydration, an HTTP GET and a subsequent revision. The stricter beta6 change validation accepted the legitimate numeric update.
- A server configured for data model 3.2 refused a Shipment carrying the 3.3-only `securityDeclarations` property with HTTP 400 and persisted no object.
- Raw OAuth form requests reached the SDK through Drupal with core `basic_auth` enabled. Valid credentials issued a usable bearer token; duplicate parameters, including an encoded duplicate key, and mixed Basic/body credentials returned `400 invalid_request`. A correctly signed token with a present-but-null `nbf` returned 401. Drupal's current user remained anonymous.

The existing suite also exercises delivery retries for transport/token-endpoint failures, stale outbox attempts, token requests carrying session cookies, response headers, cache expiry, replacement request stores, rollback and the SDK's storage contracts. None failed with beta6. The module has no direct calls to the JWT claim accessors whose return types changed from `?int` to `?float`.

The reduction from the previous **66 tests / 794 assertions** is explained by the SDK withdrawing its contract test that moved an existing action request between objects. Beta6 explicitly fixes the identity, payload and object associations on first save. The module's defensive pivot rewrite remains; the reduced count is not evidence of a failing test being skipped locally.

## Scope and release assessment

Tests ran on PHP **8.3.30**, Drupal **11.4.8**, PHPUnit **11.5.56**, and DDEV's MariaDB **10.11** environment. The consumer dry run used the actual module and SDK path repositories, with other dependency metadata supplied from the existing development lock file and networking disabled. It proves dependency resolution for that package set, not a public download/install.

The remote GitHub Actions matrix, Drupal 10, PHP 8.4, other database engines, a fresh dependency vulnerability audit and live partner interoperability were not run in this review. CI's consumer job pins the declared SDK minimum; its test/static jobs still check out the SDK default branch, so future CI results need not represent beta6 exactly. This local run did use the beta6 tag's exact code.

There is no demonstrated blocker to publishing the module beta from this review. Addressing the two small follow-ups would improve diagnostics and integration guidance; normal configured operation passed the checks above. Only this report was added: implementation files were left untouched, and temporary probes and the consumer fixture were removed.
