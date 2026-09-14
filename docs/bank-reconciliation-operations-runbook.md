# Bank Reconciliation Operations Runbook

## Pre-enable checklist

1. Confirm migrations are applied and PostgreSQL constraints/triggers exist.
2. Configure the company bank account, journal currency, and dedicated liquidity account.
3. Assign least-privilege reconciliation permissions.
4. Set both global and company rollout requirements for a release environment.
5. Run the legacy/configuration audit and resolve every finding.
6. Take and verify a recoverable backup.
7. Complete a staging reconciliation and independent accountant review.
8. Record controller sign-off.

## Readiness commands

Use the project's selected PHP binary and memory limit. Replace `<company-id>` and named operators with real values.

```bash
php artisan reconciliation:audit-legacy <company-id>

php artisan reconciliation:rollout <company-id> \
  --operator="<operator>" \
  --phase=p0 \
  --backup-confirmed \
  --staging-verified \
  --controller-signoff="<controller>"

php artisan reconciliation:validate-readiness <company-id> --json
```

Do not regard rollout enablement as evidence that functional tests or accountant sign-off passed; the supplied flags are attestations and must correspond to retained operational evidence.

## Safe disable

If a material defect is found, stop future mutations without deleting evidence:

```bash
php artisan reconciliation:rollout <company-id> \
  --operator="<operator>" \
  --disable \
  --reason="<incident or control reason>"
```

Then preserve logs/snapshots, identify affected periods and identities, and decide whether controlled reopen/reperformance is required. Do not edit completed evidence directly.

## Period-close procedure

1. Confirm the statement is complete and authentic enough for the entity's control policy.
2. Verify source identity and import controls.
3. Confirm every bank row has one supported terminal treatment.
4. Review outstanding payments and deposits in transit for age, ownership, and subsequent clearing.
5. Review adjustments against supporting documents and posted moves.
6. Confirm bank errors/duplicates have evidence and approval.
7. Confirm zero difference and statement arithmetic.
8. Ensure reviewer independence when required.
9. Complete and retain the immutable report/snapshot.

## Historical correction

1. Submit a reopen request with the accounting reason and effective date.
2. Review the displayed impact on later periods.
3. Obtain approval from an authorized different user when configured.
4. Post reversals/corrections through normal accounting workflows.
5. Reconcile the reopened period.
6. Rework every stale later period chronologically.

## Verification policy

Run focused deterministic tests first. Broad reconciliation, Accounts regression, browser, concurrency, and rollback rehearsals are separate gates. Expensive checks require explicit user approval. Never run tests against the normal application or production database.
