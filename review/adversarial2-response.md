# Response to adversarial review 2

Checked on 2026-10-04. All four findings confirmed by reading the code and
addressed in the working tree. None belongs to the SDK.

| ID | Confirmed | Fix | Proof |
| --- | --- | --- | --- |
| AR2-001 | Yes | `claim()` reads the row, then `lease()` updates it with a compare-and-set on the attempt number it read and returns that number plus one; no second read. | `DeliveryTest::testClaimPausedBeforeLeasingDoesNotTakeAnotherWorkersNumber` |
| AR2-002 | Yes | `TokenEndpointProvider` applies to every request on the token path, so it is the chosen provider for form and Basic credentials alike; cookie and basic_auth are never consulted there. | `TokenBasicAuthTest`, now with a session cookie on form credentials, valid and invalid, and on Basic credentials |
| AR2-003 | Yes | CI reads the minimum SDK release from the module's `composer.json`, checks the SDK out at that tag and pins the path repository to it. | CI `composer` job; the version step was run locally |
| AR2-004 | Yes | `DatabaseSubscriptionStore` takes the configured `ActionRequestStore`; it uses the SQL topic projection only when that store is the module's own and the SDK's `accepted()` otherwise. | `StoreReplacementTest`, replacing only the request store |
