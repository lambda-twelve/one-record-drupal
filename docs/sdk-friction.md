# SDK friction found while building the Drupal integration

Each item names something in `lambda-twelve/one-record` that made the Drupal
adapter harder than it should be, why, what this module did meanwhile, and the
smallest SDK change that would remove the workaround for every host (Drupal,
Laravel, Symfony). Items 1 to 10 were raised against the SDK's working tree
before its first release; each carries a status checked against SDK
1.0.0-beta6 (2026-10-05). Most are resolved, and the module has dropped the
matching workarounds; the entries stay as the record of why the SDK's API looks
the way it does. Items from 11 on were found while following the betas.

## 1. No unit of work around a request

**Friction.** `OneRecordServer::handle()` called several stores per request and
had no transaction hook. Worse, it caught every `Throwable` and turned it into a
500 response, so a host could not roll back on an exception: by the time the
host saw anything, the exception was gone and only the status code remained.

**Then.** `Server\TransactionalHandler` wrapped the SDK handler, opened a
database transaction for non-safe methods, and rolled back when the response
status was 500 or above. A 4xx committed on purpose, which was wrong for one
4xx: an acceptance beaten by a competing decision answered 409 after it had
written its grants, and the handler committed them.

**Proposal.** A `Server\Spi\UnitOfWork` interface with
`run(callable $work): mixed` (identity implementation by default), used by
`OneRecordServer::handle()` around the endpoint call and by `DataHolder` and
`ActionRequests` around their store sequences.

**Status: resolved in 1.0.0-beta1.** The SPI exists as proposed and nests. The
module binds `Server\DatabaseUnitOfWork`, a Drupal transaction with savepoints
for the nested calls, and the status-code heuristic is gone. Beta3 went
further: a decision is stored before its side effects, so a lost race writes
nothing even without a unit, and `Services` warns when a persistent store is
wired without one. Drupal's own data layer wraps writes in transactions with
savepoints, so binding one here is the natural shape, not a workaround.

## 2. `SubscriptionStore` could not be implemented from the interfaces alone

**Friction.** The interface had no write method; the offers that `offered()`
returned entered through `InMemorySubscriptionStore::offer()`, which was not on
the interface. `InMemorySubscriptionStore::subscribersOf()` also contained an
`instanceof InMemoryActionRequestStore` check, so it silently returned nothing
when paired with any other request store.

**Proposal.** Add `offer(Subscription): void` (and ideally `withdraw`) to the
SPI, and give `InMemorySubscriptionStore` a store-neutral way to list accepted
subscription requests.

**Status: resolved in 1.0.0-beta1.** The SPI has `offer()` and
`withdraw(Subscription)`; `ActionRequestStore` has `accepted()`; the in-memory
store takes its request store explicitly. The module keeps `withdrawTopic()`
as an extension for its Drush command.

## 3. `InMemoryAccessPolicy` was the grant policy, under a misleading name

**Friction.** Despite its namespace and name, this class read every grant from
whatever `AccessDelegationStore` it was given and kept only the internal agents
and `allowEveryone()` grants in memory. It was the policy a host wanted, but the
name said "test double", and `allowEveryone()` state could not be persisted.

**Proposal.** Rename to `GrantAccessPolicy`, move the public grant to the
`Grant` model so `allowEveryone()` persists, and read internal agents from a
constructor argument or a method.

**Status: resolved in 1.0.0-beta1.** `Server\GrantAccessPolicy` is the policy,
"everyone" grants are grants to a wildcard agent in the store, and internal
agents are added with `addInternal()`. `Access\AccessPolicyFactory` builds it.

## 4. No composite `KeyResolver`

**Friction.** `StaticKeyResolver` and `JwksKeyResolver` existed, but a
deployment trusts some issuers by pinned key and others by discovery, and its
own token endpoint by its own key. Nothing combined resolvers, and cache
exceptions escaped `JwksKeyResolver` into `authenticate()`, which is documented
as never throwing.

**Proposal.** Ship `Auth\Jwt\ChainKeyResolver` in the SDK and let
`JwksKeyResolver` catch PSR-16 exceptions.

**Status: resolved in 1.0.0-beta1.** Both done. `Auth\AuthenticatorFactory`
chains with the SDK's class; the module's `CompositeKeyResolver` is deleted.

## 5. The route table was not exposed

**Friction.** `ServerBuilder::build()` hard-coded the patterns and the SDK
router handled them, which is right. But a framework wants one named route per
endpoint for access control and alteration, and the only way to get the list
was to copy it.

**Proposal.** A public static method on `ServerBuilder` (name, methods,
pattern, since-version) that hosts register from.

**Status: resolved in 1.0.0-beta1.** `ServerBuilder::routes()` returns
`Server\Route` values. `Routing\Routes` iterates it and keeps only a map from
the SDK's route names to the Drupal route names sites may already use.

## 6. Persistence had to round-trip through JSON-LD at a fixed API version

**Friction.** `ActionRequest::toJsonLd()` emitted the status history only at
2.3.0 and there was no version-independent storage form; `LogisticsEvent` had
no non-validating hydration factory; `Literal::dateTime` wrote milliseconds
while `SystemClock` yielded microseconds, so timestamps changed on the way
through a document.

**Proposal.** A storage serialisation, `LogisticsEvent::fromStored()`, and a
millisecond `SystemClock`.

**Status: resolved in 1.0.0-beta1.** `LogisticsEvent::fromStored()`,
`ActionRequest::toStorageJsonLd()` and a millisecond `SystemClock` exist. The
module hydrates events with `fromStored()`; `Clock\TimeClock` truncates the way
`SystemClock` does; the request store still writes the latest edition with the
version recorded, which the SDK's contract accepts.

## 7. `forget()` was narrower than its name

**Friction.** `DataHolder::forget()` erased the object's revisions only. Events
stayed, grants stayed, and the SPI had no `eraseFor()` on the event store.

**Proposal.** `LogisticsEventStore::eraseFor(Iri)` and
`AccessDelegationStore::eraseFor(Iri)` on the SPI, with `DataHolder::forget()`
taking flags.

**Status: resolved in 1.0.0-beta1.** Both `eraseFor()` methods are on the SPI
and `forget(Iri $object, bool $events = true, bool $grants = true)` takes the
flags the `one-record:forget` command exposes.

## 8. Delivery has no contract beyond `enqueue()`

**Friction.** The outbox SPI is one method. Everything a host needs to deliver
(resolve the partner's endpoint and credentials, lease a row, retry, give up,
mark delivered) is reinvented per framework, and the SDK offered no idempotency
id on `OutboundNotification`, so a partner could not deduplicate a retried
send.

**Here.** `Notification\Delivery`, `NotificationDelivererInterface`,
`PartnerRegistryInterface` and the outbox row columns, with attempt-numbered
outcomes so a worker that outlived its lease cannot overwrite a later attempt.

**Proposal.** Document the delivery expectations (retry classes by status,
at-least-once, idempotency) in the SDK guide, and consider a small
framework-neutral `Delivery` helper in `Client\` that takes a
`PartnerResolver` interface and reports a retry/reject/delivered outcome.

**Status: resolved across 1.0.0-beta1, beta3 and beta4.** `OutboundNotification`
carries an id, which the module sends as the `Idempotency-Key`; beta3's SPI
docs state the timing (`enqueue()` inside the unit, the queue hand-off after
commit, at least once); beta4 adds `Client\DeliveryVerdict`, the retry
classification, and a guide section specifying the outbox row, claim and
outcome model. The module's deliverer uses the verdict; its outbox and
`Delivery` worker are the Drupal shape of the specified model, which is the
part that belongs in a host.

## 9. Test support was not shipped

**Friction.** `FixedClock`, `HeaderAuthenticator`, `RecordingDispatcher` and
the server test case lived under `tests/` and `autoload-dev`, so every wrapper
re-created them. The store contract tests the in-memory stores' docblocks
mentioned were not shipped either, and when they were, they were abstract
`TestCase` subclasses, which a Drupal `KernelTestBase` cannot extend.

**Proposal.** A `src/Testing/` namespace with the doubles and the store
contract tests, usable from a test case that already has a base class.

**Status: resolved in 1.0.0-beta1 and 1.0.0-beta2.** `Testing\` ships the
doubles and the contracts; beta2 adds the contracts as traits
(`Testing\Contract\*ContractTests`) for exactly this case. The module runs all
six against its database stores in `tests/src/Kernel/Store/SdkContract/`,
which is how the event store's silent overwrite of a repeated IRI was found.
Since beta4 `Testing\FixedClock` has `set()` and the module ships no double
of its own: the kernel tests use the SDK's clock, header authenticator and
racing request store.

## 10. Working tree ahead of the published repository

**Friction.** `src/Client/` and `TopicType::shortName()` existed only in the
local working tree, so CI against the GitHub repository failed.

**Status: resolved.** 1.0.0-beta1 and 1.0.0-beta2 are tagged and on Packagist;
the module requires `^1.0.0-beta2`.

## 11. `ActionRequestStatusChanged` fires before the decision's side effects

**Friction.** Since beta3, `ActionRequests::accept()` stores the new status,
dispatches `ActionRequestStatusChanged` and fans out the status notification,
and only then writes the grants of an access delegation or applies the change
and saves the revision. A listener for an accepted delegation that reads the
policy, or a listener for an accepted change that reads the object, sees the
state from before the decision. In Drupal that is the natural moment to react
("a partner was granted access: tell the ERP"), and the listener gets
yesterday's answer.

**Here.** Nothing in the module reacts to the event yet; the README tells
listeners to queue their work, which also moves the read after the commit.

**Proposal.** Dispatch the status event after the side effects (the
compare-and-set can stay first; only the dispatch and the status fan-out need
to move), or document that `LogisticsObjectRevised` and
`LogisticsObjectAccessGranted` are the events to react to and
`ActionRequestStatusChanged` only reports the decision.

**Status: resolved in 1.0.0-beta4.** The event and the status notification
fire after the grants, revision or revocation are in place, once per decision.
The README's events section says what a Drupal listener may now rely on.

## 12. The competing-decision scenario is proved per host

**Friction.** `tests/Integration/HostFeedbackTest` stages a lost compare-and-set
with an anonymous `ActionRequestStore` decorator; the module needed the same
decorator, writing to its own table, to prove the scenario through its wiring
(`tests/src/Support/CompetingActionRequestStore`). Every host will write one.

**Proposal.** Ship a `Testing\RacingActionRequestStore` (decorate any store;
arm with a request IRI; the next `transition()` on it throws the status
conflict, or runs a host callback first) next to the other doubles, so the
scenario is one line in a host's test.

**Status: resolved in 1.0.0-beta4.** Shipped as proposed. The module's
`TransactionTest` decorates its request store with it and arms it with a
callback that flips the Drupal row, so the module's own compare-and-set is
the one that loses.

## 13. The in-memory check in `Services` is by class name

**Friction.** The beta3 warning decides whether a store is "in memory" by its
class namespace. A host that decorates an in-memory store (a test double, a
recording wrapper) is warned about a transaction it does not need, and a host
that subclasses nothing but persists is correctly warned, by luck of naming.

**Proposal.** A marker interface (`Spi\Volatile`, or a `needsUnitOfWork()`
hint on the store) that the in-memory stores implement and a decorator can
forward; or simply document that the warning is a heuristic.

**Status: resolved in 1.0.0-beta4.** `Spi\Volatile` decides the warning, and
`ServerBuilder::check()` returns the same findings for a status page. The
module shows them in `hook_requirements()` and `drush one-record:status`;
none of the module's stores is volatile, so none is marked.

## 14. `DeliveryVerdict` judges the wrapper, not the cause

**Friction.** `OneRecordClient::send()` wraps a PSR-18 failure in
`ClientException` (keeping it as the previous exception) and
`ClientCredentialsTokenProvider` turns a non-2xx token answer into a
`ClientException` whose status survives only in the message. `DeliveryVerdict::of()`
checks the exception it is handed for `OneRecordHttpException` or the PSR
interface, so both arrive as plain `ClientException` and are `Reject`: a
connection refused on the notification, or a 503 from the partner's token
endpoint, ends delivery for good (module review AR-001).

**Here.** `Notification\ClientNotificationDeliverer` walks the previous chain
before asking the verdict and reads the token endpoint's status out of the
message, with tests for each case. Both are workarounds for the SDK's
contract.

**Proposal.** Have `DeliveryVerdict::of()` walk `getPrevious()`; give the
token provider's failures a structured status (`TokenEndpointException` with
`status`, or reuse `OneRecordHttpException`) so refused credentials and an
outage are distinguishable without parsing a sentence.

**Status: resolved in 1.0.0-beta5.** Both done as proposed. The deliverer
hands the verdict whatever it caught and reads the status from the chain;
the message parsing is gone.

## 15. The request-store contract does not replace across objects

**Friction.** `ActionRequestStore::save()` may replace a request whole, and
the SDK's contract proves replacement by status only. The module's store
merged its object projection on replacement and kept the former object's
association, which the contract did not catch; an independent review did
(module review AR-006).

**Proposal.** One more case in `ActionRequestStoreContractTests`: save a
verification about A under IRI R, save another about B under R, and assert
`auditTrail(A)` no longer lists R while `auditTrail(B)` does.

**Status: settled the other way in 1.0.0-beta6.** Beta5 added the test; beta6
withdrew it and stated the `save()` envelope instead: the server saves a
request once, its objects are fixed then, and a later `save()` may replace
only what `transition()` changes. That is the clearer contract for a store
with query columns, and the module's test of moving a request is withdrawn
with it.
