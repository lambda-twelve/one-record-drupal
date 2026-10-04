# Response to the beta6 readiness review

Checked on 2026-10-05. Both follow-ups confirmed and addressed; the CI drift
the review noted in passing is closed as well.

| ID | Confirmed | Fix | Proof |
| --- | --- | --- | --- |
| B6-001 | Yes | `HandlerFactory` and `drush one-record:status` decide on `problems()`, the same check as the status report; rejected stored settings get the 503 handler (with a title that says rejected, the problems themselves staying off the wire) and the Drush table lists them. | `ServerRequestTest::testRejectedStoredSettingsAnswer503` |
| B6-001, recheck: malformed holder IRI | Yes | `internalAgents()` leaves out a stored value that is not an IRI instead of throwing while the access policy is built; `problems()` is where it is reported, and the handler, the status report, the legacy hook and Drush all reach their 503 or error paths. | `ServerRequestTest::testMalformedStoredHolderAnswers503` |
| B6-002 | Yes | The README's events section now distinguishes an action request's own status notification, queued before the listener, from object and event fan-out, queued after, and restates the transaction guarantee. | Documentation |
| CI checks out the SDK default branch for tests and static analysis | Yes | Every job reads the minimum SDK release from the module's constraint and checks the SDK out at that tag, as the consumer job already did. | `.github/workflows/ci.yml` |
