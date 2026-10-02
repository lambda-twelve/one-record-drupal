# SDK friction found while building the Drupal integration

Each item names something in `lambda-twelve/one-record` that made the Drupal
adapter harder than it should be, why, what this module does meanwhile, and the
smallest SDK change that would remove the workaround for every host (Drupal,
Laravel, Symfony). Checked against SDK commit `864ab23` plus the uncommitted
working tree on 2026-10-02.

## 1. No unit of work around a request

**Friction.** `OneRecordServer::handle()` calls several stores per request and
has no transaction hook. Worse, it catches every `Throwable` and turns it into a
500 response, so a host cannot roll back on an exception: by the time the host
sees anything, the exception is gone and only the status code remains.

**Here.** `Server\TransactionalHandler` wraps the SDK handler, opens a database
transaction for non-safe methods, and rolls back when the response status is
500 or above. A 4xx commits on purpose: a `StoreException` inside an accepted
change becomes a stored, failed action request. Synchronous listeners run inside
the transaction.

**Proposal.** A `Server\Spi\UnitOfWork` interface with
`run(callable $work): mixed` (identity implementation by default), used by
`OneRecordServer::handle()` around the endpoint call and by `DataHolder` and
`ActionRequests` around their store sequences. Hosts bind their transaction
manager once and the status-code heuristic disappears.

## 2. `SubscriptionStore` cannot be implemented from the interfaces alone

**Friction.** The interface has no write method; the offers that `offered()`
returns enter through `InMemorySubscriptionStore::offer()`, which is not on the
interface. `InMemorySubscriptionStore::subscribersOf()` also contains an
`instanceof InMemoryActionRequestStore` check, so it silently returns nothing
when paired with any other request store. The reference implementation is not a
reference for a host.

**Here.** `Store\DatabaseSubscriptionStore` derives subscribers from the
projected columns of accepted subscription requests and adds its own `offer()`
and `withdraw()` methods.

**Proposal.** Add `offer(Subscription): void` (and ideally `withdraw`) to the
SPI, and give `InMemorySubscriptionStore` a store-neutral way to list accepted
subscription requests (an `ActionRequestStore::accepted(ActionRequestType)` query
or an explicit dependency on the in-memory class in its constructor type).

## 3. `InMemoryAccessPolicy` is the grant policy, under a misleading name

**Friction.** Despite its namespace and name, this class reads every grant from
whatever `AccessDelegationStore` it is given and keeps only the internal agents
and `allowEveryone()` grants in memory. It is the policy a host wants, but the
name says "test double", and `allowEveryone()` state cannot be persisted.

**Here.** `Access\AccessPolicyFactory` instantiates `InMemoryAccessPolicy` over
the database grant store and adds internal agents from configuration. Public
grants are not supported: they would vanish on the next request.

**Proposal.** Rename to `GrantAccessPolicy` (keep the old name as an alias),
move the public grant to the `Grant` model (a grant to a wildcard agent, stored
like any other) so `allowEveryone()` persists, and read internal agents from a
constructor argument.

## 4. No composite `KeyResolver`

**Friction.** `StaticKeyResolver` and `JwksKeyResolver` exist, but a deployment
trusts some issuers by pinned key and others by discovery, and its own token
endpoint by its own key. Nothing combines resolvers.

**Here.** `Auth\CompositeKeyResolver`, fifteen lines that ask each resolver in
turn.

**Proposal.** Ship `Auth\Jwt\ChainKeyResolver` in the SDK and let
`JwksKeyResolver` catch PSR-16 exceptions like the client classes do (today
they propagate out of `authenticate()`, which is documented as never throwing).

## 5. The route table is not exposed

**Friction.** `ServerBuilder::build()` hard-codes twelve patterns and the SDK
router handles them, which is right. But a framework wants one named route per
endpoint for access control and alteration, and the only way to get the list is
to copy it.

**Here.** `Routing\Routes` mirrors the table.

**Proposal.** A public constant or static method on `ServerBuilder` (pattern,
methods, since-version) that hosts register from, so a new endpoint in the SDK
appears in every framework without a copy.

## 6. Persistence has to round-trip through JSON-LD at a fixed API version

**Friction.** `ActionRequest::toJsonLd()` emits the status history only at
2.3.0 and there is no version-independent storage form; `LogisticsEvent` has no
non-validating hydration factory (stores rebuild it from `JsonLd::expand()`
directly); `Literal::dateTime` writes milliseconds while `SystemClock` yields
microseconds, so timestamps change on the way through a document.

**Here.** Everything is stored at `ApiVersion::latest()` with the version
recorded; `Clock\TimeClock` truncates to whole milliseconds so round trips are
exact; events are hydrated with `new LogisticsEvent(..., JsonLd::expand($json)->graph, ...)`.

**Proposal.** A storage serialisation (`toStorage()`/`fromStorage()` or a
documented "always latest" rule in the SPI docs), `LogisticsEvent::fromStored()`,
and a millisecond `SystemClock`.

## 7. `forget()` is narrower than its name

**Friction.** `DataHolder::forget()` erases the object's revisions only. Events
stay, grants stay, and the SPI has no `eraseFor()` on the event store.

**Here.** The database event and grant stores add `eraseFor(Iri)`, and the
`one-record:forget` command offers `--events` and `--grants`.

**Proposal.** `LogisticsEventStore::eraseFor(Iri)` and
`AccessDelegationStore::eraseFor(Iri)` on the SPI, with `DataHolder::forget()`
taking flags (or a `ForgetScope`) so hosts do not reach around it.

## 8. Delivery has no contract beyond `enqueue()`

**Friction.** The outbox SPI is one method. Everything a host needs to deliver
(resolve the partner's endpoint and credentials, lease a row, retry, give up,
mark delivered) is reinvented per framework, and the SDK offers no idempotency
id on `OutboundNotification`, so a partner cannot deduplicate a retried send.

**Here.** `Notification\Delivery`, `NotificationDelivererInterface`,
`PartnerRegistryInterface` and the outbox row columns.

**Proposal.** Add an id to `OutboundNotification`, document the delivery
expectations (retry classes by status, idempotency) in the SDK guide, and
consider a small framework-neutral `Delivery` helper in `Client\` that takes a
`PartnerResolver` interface and reports a retry/reject/delivered outcome.

## 9. Test support is not shipped

**Friction.** `FixedClock`, `HeaderAuthenticator`, `RecordingDispatcher` and the
server test case live under `tests/` and `autoload-dev`, so every wrapper
re-creates them (this module did, in `tests/src/Support`).

**Proposal.** A `src/Testing/` namespace (or a `lambda-twelve/one-record-testing`
package) with those classes and the store contract tests the
`InMemoryLogisticsObjectStore` docblock already mentions.

## 10. Working tree ahead of the published repository

**Friction.** `src/Client/` and `TopicType::shortName()` exist only in the
local working tree. This module uses the client for delivery; CI that checks
out the GitHub repository will fail until the client is pushed.

**Proposal.** Push the client and tag `1.0.0-beta1` so wrappers can depend on a
version.
