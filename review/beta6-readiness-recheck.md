# Beta6 readiness — recheck of the author's response

Reviewed 2026-10-05 against [the author's response](beta6-readiness-response.md). **The original body-limit reproduction is fixed, B6-002 is closed, and CI now selects the declared SDK tag in every job. B6-001 remains partially open for a malformed data-holder IRI.** This remaining invalid-configuration edge case is still P3 and does not change the beta-release assessment.

The module remains at `dee96e9272dae64988fd49d717a825b2c917c04c` plus the author's existing working-tree changes. The SDK is clean at `14c88fb79f14d44dcc491ea1bd4e20576469129d`, exactly the beta6 tag's commit.

## Disposition

| Item | Result | Evidence |
| --- | --- | --- |
| B6-001: rejected stored settings | **Partially fixed** | `max_body_bytes: 0` now produces HTTP 503 and a useful Drush table. Invalid holder IRIs still throw during dependency construction or Drush output preparation. |
| B6-002: listener-order documentation | **Closed** | README now distinguishes status notifications queued before their listeners from object/event fan-out queued afterwards, and preserves the rollback/after-commit explanation. |
| CI SDK default-branch drift | **Closed in workflow configuration** | Composer, test and static jobs all extract the minimum release and supply it as the SDK checkout `ref`. PHP setup precedes extraction, and the nested checkouts use the correct working directory. Remote Actions were not run. |

## Remaining B6-001 case — P3

**Relevant locations:** [one_record.services.yml](../one_record.services.yml), lines 91–118; [AccessPolicyFactory.php](../src/Access/AccessPolicyFactory.php), lines 32–35; [OneRecordCommands.php](../src/Drush/Commands/OneRecordCommands.php), line 112; [OneRecordConfig.php](../src/Config/OneRecordConfig.php), lines 111–117.

On an otherwise configured installation, store:

```yaml
data_holder: 'https://holder.example/invalid value'
```

`OneRecordConfig::problems()` correctly identifies the invalid IRI. However, resolving `one_record.handler` first resolves `HandlerFactory`'s `ServicesFactory` dependency, which eagerly resolves the access policy. `AccessPolicyFactory::create()` calls `internalAgents()`, which appends the holder and constructs an `Iri` from it. That throws:

```text
Not a valid IRI: "https://holder.example/invalid value".
```

This happens **before `HandlerFactory::create()` reaches the new `problems()` guard**. An HTTP kernel probe failed with “The controller for URI \"/one-record\" is not callable.” Direct service-resolution probes isolated the underlying IRI exception and its `internalAgents()` stack frame. No final rendered HTTP status was asserted for this malformed-holder case.

The same eager dependency prevents constructing the Drush command and executing the legacy runtime-requirements hook on cold services. The OOP requirements hook has the same `ServicesFactory` constructor dependency. Even when the command has already been constructed while settings were valid, changing the holder to the malformed value and calling `status()` still throws: line 112 calls `internalAgents()` unconditionally, despite `$problems` already being nonempty.

**Suggested completion:** defer construction of configuration-dependent runtime services until after the configuration check, including in diagnostics. In Drush, avoid converting rejected values into SDK value objects merely to display the table. Add coverage for both a fresh container with a malformed holder and the direct `status()` path. The new body-limit test does not exercise a value that fails while constructing the access policy.

This requires invalid stored configuration that the settings form rejects. It is a remaining coverage gap in the broad rejected-settings behavior, not a regression in normal configured operation.

## Verification

- Full existing kernel suite: **66 tests, 789 assertions passed**, including `testRejectedStoredSettingsAnswer503`.
- Independent probes: **3 tests, 17 assertions**. One verified the successful fix with both a zero body limit and negative embedding depth: Drush listed both problems and HTTP returned 503 without disclosing them. The other two explicitly asserted the remaining exceptions described above; their passing result confirms the defect, not its resolution.
- PHPStan and Drupal coding standards: passed.
- `git diff --check`: passed.
- The probes emitted the same dependency deprecation seen in the earlier review when loading `DrushCommands` (`SavableState::currentState()` return-type compatibility).

Verification used the existing PHP 8.3.30 / Drupal 11.4.8 / PHPUnit 11.5.56 / MariaDB environment. This was a focused response recheck; the remote compatibility matrix and fresh consumer installation were not repeated. Only this report was added, temporary probes were removed, and implementation files were left unchanged.
