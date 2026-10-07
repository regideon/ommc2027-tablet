# OMMC-20261007-tablet-customer-push-pending-indicator

- **Title:** Show when Customer push work remains in the Tablet Customer module
- **Objective:** Use a red state on the existing Push Customers action whenever the Customer-only push has eligible work.
- **Repositories:** Tablet `ommc2027-tablet` only.
- **State:** TERMINAL_DELIVERED
- **Implementation authorization:** Granted by explicit Human `GO` for this bounded Work.
- **Terminal authorization:** Granted by explicit Human `TERMINAL: GO`.
- **Human-authorized scope:** Reuse Customer push eligibility for an indicator on the existing Push Customers action. No new button or push behavior.
- **Constraints:** No Portal inspection or modification; no migrations, schema changes, deployment, polling, or unrelated behavior changes.
- **Task-owned files:** SyncService, CustomerPage, Customer page view, focused Customer push indicator tests, and this Work note.
- **Unrelated/pre-existing worktree changes to preserve:** `public/expense_attachments/578f31cf-e040-481c-b95b-22bb710f3b82.png` and `public/expense_attachments/fa046040-646c-4603-967a-b47837500c41.png`.
- **Accepted decisions:** The single eligibility definition is `pendingCustomerPushQuery()`: `sync_status = pending` OR `sync_status = failed` with `sync_attempts < 3`. The indicator uses a boolean service check backed by that query. Livewire re-rendering refreshes the state after manual push; page entry reads current local state.
- **Implementation:** `SyncService::hasPendingCustomerPushWork()` checks existence through the same `pendingCustomerPushQuery()` used by manual Customer push. CustomerPage includes that boolean in view data, and the existing Push Customers action conditionally uses `text-red-500 hover:text-red-600`; its neutral class, icon, label, layout, loading, and disabled behavior remain unchanged. Livewire re-renders the page after push, so the query refreshes without polling.
- **Validation:** Focused Customer push, page access/search, paginated pull, and Customer Code sync suites: 28 passed (169 assertions). Pint passed on `SyncService.php` and the focused push tests. PHP syntax checks and `git diff --check` passed. An additional isolated, out-of-scope `CustomerCodePageTest` case fails its existing expectation that a create push failure changes the record to `failed`; the observed status is `pending`. No creation behavior or that test was changed in this Work.
- **Human acceptance:** PASS. Human functional acceptance was performed against the implemented Tablet behavior and confirmed the accepted Customer push pending indicator behavior.
- **Blockers:** None known.
- **Remaining work:** None.
- **Delivery evidence:** Terminal commit created and pushed normally to `origin/main`; commit SHA is recorded in terminal delivery report.
