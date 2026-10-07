# OMMC-20261007-tablet-customer-tile-company-code

- **Title:** Show Customer Code in Customer list tile titles
- **Objective:** Display the locally stored Customer Code before each Customer name on the Customer module list.
- **Repositories:** Tablet `ommc2027-tablet` only.
- **State:** TERMINAL_DELIVERED
- **Implementation authorization:** Granted by explicit Human request.
- **Human-authorized scope:** Display-only Customer list title update using the existing `customers.unique_id` field.
- **Constraints:** No Portal changes, migrations, schema changes, deployment, or changes to Customer Code generation/allocation, search, synchronization, scoping, ordering, details, forms, or location behavior.
- **Unrelated worktree files to preserve:** `public/expense_attachments/578f31cf-e040-481c-b95b-22bb710f3b82.png` and `public/expense_attachments/fa046040-646c-4603-967a-b47837500c41.png`.
- **Correction history:** The initial implementation interpreted “company_code” as `companies.code` through `customers.company_id`. Human Tablet validation clarified that the requested identifier is the Customer Code. That initial visual result, which displayed company/category values such as `OMMC` or `LAST_MILE`, was rejected.
- **Authoritative display field:** `customers.unique_id` is the existing Tablet Customer Code field. Portal remains authoritative for Customer Code allocation. This Work does not generate, derive, or reconstruct codes.
- **Implementation:** The incorrect Company relationship eager-loading and title dependency have been removed. The title now renders trimmed `customers.unique_id` followed by ` - ` and the Customer name when nonblank. Null, empty, or whitespace-only Customer Code renders the Customer name alone. Tile layout, subtitle, chevron, click behavior, list ordering, and existing access scoping are unchanged. Customer search is unchanged.
- **Validation:** Corrected Customer page search and access suites passed: 8 tests, 39 assertions, including actual `unique_id` display, company-code exclusion, null/blank/whitespace fallback, and ordering/access scoping. PHP syntax checks passed for changed PHP files. Pint passed on the focused test file. `git diff --check` passed.
- **Human visual acceptance:** PASS. Initial interpretation using `companies.code` was rejected during Human Tablet validation. The corrected implementation using `customers.unique_id` was visually validated and accepted on the Tablet.
- **Remaining work:** None.
- **Delivery evidence:** Terminal commit created and pushed normally to `origin/main`; commit SHA is recorded in terminal delivery report.
