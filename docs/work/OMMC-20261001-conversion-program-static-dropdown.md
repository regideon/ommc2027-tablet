# OMMC-20261001 — Conversion Program static dropdown

## Status

TERMINAL_DELIVERED

## Authorization

- Implementation authorization: Granted by Human GO.
- Terminal authorization: Granted by explicit Human TERMINAL: GO in the reconciliation instruction.

## Objective

Constrain the Conversion Program field to the canonical values 'For Conversion', 'Increase Share of Wallet', and 'Head On', while allowing blank/null values in Portal and Tablet.

## Scope

- Portal shared Customer Create/Edit form: replace the free-text field with a bounded select and validation.
- Tablet Add Customer form: replace the free-text field with a bounded select and validation; Edit inherits the same field and has equivalent validation.
- Add one-way, idempotent JSON backfill migration in each repository.
- Preserve unrelated profile_data keys and archived profiles; do not redesign sync or add reference tables.

## Approved legacy mapping

The explicitly approved mapping is 'Inc Share of Wallet' → 'Increase Share of Wallet'.

Read-only pre-implementation counts:

- Portal: 1,750 active profiles with the legacy value.
- Tablet: 2 active profiles with the legacy value.
- No archived-profile conflicts were found in either repository.

The migration is intentionally one-way; down() does not restore the legacy value.

## Implementation and validation

- Added focused contract tests for the exact option set and null/invalid validation behavior.
- Portal and Tablet PHP syntax checks passed for changed PHP files.
- Focused Conversion Program tests passed in both repositories.
- git diff --check passed.
- Full-suite baseline failures remained unrelated to this bounded Work.

## Human acceptance

Human acceptance: PASS. The Human confirmed that the Conversion Program functionality works. No additional acceptance evidence is claimed.

## Delivery evidence

- Portal implementation commit: b7ca23a1f78d2f258f2488127667d31c60c9c808, pushed to origin/main.
- Tablet implementation commit: 6339fe47606f488b43e1bf7ac044e3dd676081ed, pushed to origin/ommc-ipad-v2.
- This terminal reconciliation updates only this Work note; its independent terminal reconciliation commit and push are recorded in Git history and the delivery report.
- No application behavior, database contents, migrations, unrelated Work records, or unrelated files were changed during reconciliation.
- RSM Work was not started.
