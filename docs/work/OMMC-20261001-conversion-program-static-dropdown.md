# OMMC-20261001 — Conversion Program static dropdown

## Status

READY_FOR_TERMINAL_REVIEW

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

## Verification

- Added focused contract tests for the exact option set and null/invalid validation behavior.
- git diff --check passed during implementation.
- Human acceptance of the Portal and Tablet forms remains required.

## Terminal handoff

Implementation is complete and ready for separate terminal review. No commit or push was performed under this Work.
