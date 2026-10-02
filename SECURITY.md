# Security

Please report vulnerabilities in this module privately through GitHub's
security advisories for `lambda-twelve/one-record-drupal`. You will hear back
within five working days, and fixes are targeted within 90 days.

In scope: the module's PHP code, service wiring, routes, database stores and
Drush commands. Issues in the protocol implementation itself (JSON-LD, the
model, endpoint behaviour, token verification) belong to the SDK and should be
reported to `lambda-twelve/one-record`.

Secrets handled by this module are the RS256 signing key and partners' client
secrets, both read from `settings.php`, and the hashed client secrets in
`one_record_clients`. Exported configuration never contains secrets.

Before 1.0.0, only the latest pre-release is supported.
