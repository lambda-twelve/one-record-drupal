# Contributing

Thanks for helping make ONE Record a natural part of Drupal.

## Where things belong

This module is an adapter. Before adding code here, ask which layer it is for:

| Belongs in | Examples |
| --- | --- |
| The SDK (`lambda-twelve/one-record`) | JSON-LD, the model, endpoint semantics, the action-request lifecycle, authorisation rules of the protocol, anything a Laravel or Symfony host would also need |
| This module | Container wiring, configuration, routes, database stores, queue delivery, Drush, Drupal events |
| A site's own module | Business rules ("a forwarder sees the shipments routed to it"), partner onboarding, ERP integration |

If integrating with the SDK is awkward, do not hide it behind a Drupal-side
workaround. Describe it in `docs/sdk-friction.md` with the smallest SDK change
that would fix it, and keep the workaround as small and as clearly labelled as
possible.

## Working on the module

Development runs through DDEV with the SDK working tree beside this repository
as `../one-record`; see the README's *Local development* section.

- `ddev test` must pass. Tests are kernel tests that exercise the real SDK and
  the real database; please do not mock the SDK.
- `ddev phpstan` (level 8) and `ddev phpcs` (Drupal, DrupalPractice) must be
  clean. No baselines.
- Stores must keep passing the contract tests shared with the SDK's in-memory
  implementations. If the in-memory behaviour is wrong, fix it in the SDK, not
  by diverging here.
- Commit messages say what changed and why. Small commits.
- Record user-facing changes under *Unreleased* in `CHANGELOG.md`.

## Supported versions

Drupal `^10.3 || ^11` on PHP 8.3 and later. Do not raise either floor without a
technical reason that the commit message explains.
