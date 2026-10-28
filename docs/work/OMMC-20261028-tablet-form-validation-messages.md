# OMMC-20261028-tablet-form-validation-messages

- **Title:** Tablet — per-form validation rules, friendly messages, and Marketing date ordering
- **Repository role:** Tablet `ommc2027-tablet` only. No Portal changes.
- **State:** `READY_FOR_TERMINAL_REVIEW`
- **Design basis:** Human direction 2026-10-28: validation messages must be
  user friendly, must not expose `.0` array indexes, and must be handled in
  per-form rule classes rather than a new HTTP FormRequest layer. Follow-up:
  Marketing start/end dates must enforce that the end date is after the start
  date.

## Objective

1. User-facing validation messages no longer expose payload keys or repeated
   row indexes such as `items.0.category` or `participants.0.position_id`.
2. Per-form rule classes own the rules, custom messages, and friendly field
   names; services delegate to them instead of maintaining private rule copies.
3. Marketing `duration_end_at` must be on or after `duration_start_at`, and
   each realization `end_at` must be on or after its `start_at`.
4. Each form has a page-level request test that triggers validation and checks
   the resulting message, instead of relying only on generic rule tests.

## Human-authorized scope

- Tablet application validation only.
- No HTTP controller/FormRequest layer; this follows the approved tablet-forms
  foundation design, which specifies per-form validation rule classes instead.
- No database or Portal behavior change.
- Added date-order validation for Marketing start/end date pairs.
- Implementation authorization: Human requests on 2026-10-28.
- Terminal authorization: **none**.

## Task-owned files

- `app/Contracts/ProvidesFormRules.php` — adds `messages()` and `attributes()`.
- `app/Support/Forms/Concerns/ValidatesFormPayload.php` — shared
  `messages()`, `attributes()`, and `validate()` behavior.
- `app/Support/Forms/CrrRules.php`
- `app/Support/Forms/GiveawayRules.php`
- `app/Support/Forms/MarketingRules.php` — date-order rules and messages.
- `app/Support/Forms/RepairRules.php`
- `app/Support/Forms/SignageRules.php`
- `app/Support/Forms/TradeCheckRules.php`
- `app/Support/Forms/TradeCheckSubmissionRules.php` — new submission rules
  extracted from `TradeCheckService`.
- `app/Support/Forms/TradeRequestRules.php`
- `app/Support/Forms/TrainingRules.php`
- `app/Services/Forms/CrrService.php`
- `app/Services/Forms/GiveawayService.php`
- `app/Services/Forms/MarketingService.php`
- `app/Services/Forms/RepairService.php`
- `app/Services/Forms/SignageService.php`
- `app/Services/Forms/TradeCheckService.php`
- `app/Services/Forms/TradeRequestService.php`
- `app/Services/Forms/TrainingService.php`
- `lang/en/validation.php` — fallback friendly labels for Customer/Expense and
  non-rule-class validators.
- `resources/views/filament/pages/customer-create-page.blade.php` — removes
  numeric row indexes from the Customer error-summary field prefix.
- `resources/views/filament/pages/marketing-form-page.blade.php` — dynamic
  `min` on duration and realization end dates, updated when the start date
  changes.
- `tests/Feature/Forms/FormRuleClassesTest.php` — new rule-class coverage.
- `tests/Feature/Forms/CrrFormPageTest.php`
- `tests/Feature/Forms/GiveawayFormPageTest.php`
- `tests/Feature/Forms/MarketingFormPageTest.php` — date-order request test.
- `tests/Feature/Forms/MarketingFoundationTest.php` — mechanics period aligned
  to the already-enforced date rule.
- `tests/Feature/Forms/RepairFormPageTest.php`
- `tests/Feature/Forms/SignageFormPageTest.php`
- `tests/Feature/Forms/TradeCheckFormPageTest.php`
- `tests/Feature/Forms/TradeRequestFormPageTest.php`
- `tests/Feature/Forms/TrainingFormPageTest.php`
- `tests/Feature/CustomerCreatePageDiagnosticTest.php`
- `tests/Feature/SalescallExpenseReadTest.php`
- This Work note.

## Unrelated/pre-existing worktree changes to preserve

- Worktree was clean at start (`git status --short` empty).
- Untracked `.opencode/` and `graphify-out/` are pre-existing user-owned
  infrastructure; `graphify-out/` was refreshed with `graphify update .` per
  `AGENTS.md`, not staged.

## Decisions

- Per-form rule classes are the validation contract: `rules()`,
  `messages()`, `attributes()`, and `validate()`. Services call
  `CrrRules::validate($input)` and equivalents; private service rule copies
  were removed.
- The Tablet intentionally has no `app/Http/Requests` FormRequest layer. The
  approved design uses `App\Support\Forms\*Rules` classes because form
  validation runs in Livewire/service actions, not HTTP controllers.
- Friendly labels live in each rule class. `lang/en/validation.php` remains as
  a fallback for Customer/Expense and any validator that does not use a rule
  class.
- Wildcard labels collapse row indexes (for example `items.*.name` →
  "item name"), which is the user-facing wording.
- `duration_end_at` uses `after_or_equal:duration_start_at`; each
  `realizations.*.end_at` uses `after_or_equal:realizations.*.start_at`. Equal
  dates are allowed.
- The Marketing frontend mirrors the server rule: each end-date input gets a
  dynamic `min` equal to its start date, and the bound `min` updates
  immediately when the start date changes.
- The Customer summary strips numeric segments from the field prefix; the
  message itself is already friendly.
- Per-form coverage is implemented as Livewire page requests that call the real
  save action and assert the real `formErrors` / returned errors.
- The Marketing foundation mechanics period was changed from `Week 1` to a date
  because the service and date input already required a date; the rule class
  now matches that behavior.

## Validation evidence

- `php artisan test --compact tests/Feature/Forms` → **302 passed**
  (1618 assertions).
- `php artisan test --compact tests/Feature/Forms/MarketingFormPageTest.php --filter="end dates|date pickers"` → **3 passed**
  (13 assertions).
- `php artisan test --compact tests/Feature/Forms/FormRuleClassesTest.php` → **18 passed**
  (27 assertions).
- `php artisan test --compact tests/Feature/SalescallExpenseReadTest.php --filter="Expense validation messages"` → **1 passed**
  (1 assertion).
- `php artisan test --compact tests/Feature/CustomerCreatePageDiagnosticTest.php --filter="still rejects an invalid access user id|Warehouse Code|Delivery Detail"` → **8 passed**
  (56 assertions).
- `vendor/bin/pint --dirty --format agent` → passed.
- `graphify update .` → completed (11832 nodes, 33296 edges).
- The complete `CustomerCreatePageDiagnosticTest.php` file was not run in one
  invocation; targeted validation tests from that file passed.

## Manual acceptance

Pending. Requires the Human to confirm in runtime:

- Submitting an invalid repeated row in each form shows a readable message
  without `.0` (Giveaway items, Marketing volumes/realizations/mechanics,
  Trade Request items, Training participants).
- Single-field form errors use readable labels (CRR, Repair, Signage).
- Trade Check required-answer errors remain friendly.
- Customer validation summary shows no numeric index prefix.
- Expense validation returns friendly field names.
- Marketing rejects a duration or realization end date that is before its
  start date, while allowing equal dates.
- The Marketing end-date picker prevents selecting a date before the selected
  start date, including after changing the start date.

## Blockers and remaining work

- No blockers.
- Terminal delivery awaits separate explicit Human authorization.

## Delivery evidence status

Local implementation and validation only. No commit, push, migration, or
database mutation was performed.
