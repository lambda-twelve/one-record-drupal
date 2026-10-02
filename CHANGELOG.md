# Changelog

All notable changes to this module are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added

- Database implementations of every SDK storage SPI (logistics objects with
  revisions, events, action requests, subscriptions, grants, outbox) on
  Drupal's database API, with contract tests shared with the SDK's in-memory
  stores.
- Container wiring of the SDK server: configuration translated into
  `ServerConfig`, SDK interfaces as service aliases, one Drupal route per
  endpoint, a thin PSR-7 controller and a per-request database transaction.
- RS256 bearer-token authentication over configured issuers (pinned keys or
  JWKS), an OAuth 2.0 client-credentials token endpoint with hashed client
  secrets, and a JWKS document.
- The SDK's grant-based access policy over the database with internal agents
  from configuration.
- Notification delivery through Drupal's queue with the SDK client, database
  driven retries and cron sweeps.
- Drush commands for diagnostics, clients, grants, the outbox and forgetting.
- A settings form, configuration schema and DDEV-based development setup.
