# Response to adversarial review 1

Checked on 2026-10-04 against the review's six findings. Every finding was
confirmed by reading the code and, for AR-002, by reproducing the Composer
failure in a scratch consumer project inside the DDEV container. Each is
addressed in the working tree; the changelog's *Unreleased* section lists them
and the tests named below prove the fix.

| ID | Confirmed | Owner | Fix | Proof |
| --- | --- | --- | --- | --- |
| AR-001 | Yes | **SDK** (`DeliveryVerdict` ignored the wrapped cause; the token provider dropped the status). Fixed in SDK 1.0.0-beta5, which the module requires. | None left here: the deliverer hands the verdict what it caught. | `DeliveryTest::testTransientFailuresBeforeAndAroundTheNotificationAreRetried` |
| AR-002 | Yes | Module | The README names the SDK pre-release in the same `composer require`; CI resolves that command in a clean consumer project. | CI `composer` job |
| AR-003 | Yes | Module | Explicit `Cache-Control: no-store, private`; `ResponseHeadersSubscriber` restores `Content-Language` and the validators after core. | `ServerRequestTest::testCreateGrantAndRead` (GET and HEAD) |
| AR-004 | Yes | Module | `TokenEndpointProvider`, sorted above `basic_auth`, claims the token path and authenticates nobody; the token route lists it in `_auth`. | `TokenBasicAuthTest` |
| AR-005 | Yes | Module | The PSR-16 adapter enforces expiry by the current time and stores nothing for a TTL of zero or less. | `SimpleCacheTest` |
| AR-006 | Yes | Module; the SDK's contract had the gap. Beta5 tested the move, beta6 instead fixed the `save()` envelope so the SDK never moves a request; the rewrite stays as a guard. | `save()` rewrites the request's object rows inside its transaction. | `StoreContractTestBase::testActionRequestRoundTrips`, run against both the database and the in-memory stores |

The two SDK-side points were recorded as items 14 and 15 of
[docs/sdk-friction.md](../docs/sdk-friction.md) and in the SDK repository as
`review/drupal3.md`; SDK 1.0.0-beta5 resolved both.

Not done, as the review itself noted outside its scope: no run on Drupal 10.3,
PostgreSQL or SQLite in this pass (CI covers 10.3 and SQLite on push), and no
concurrent-session or process-death test of the outbox.
