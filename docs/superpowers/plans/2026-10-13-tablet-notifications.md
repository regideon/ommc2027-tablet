# Tablet Notifications (User-Filtered Form Status Updates) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When a form decision is recorded on the Portal, the user who submitted the form receives a per-user in-app notification on their tablet, shown via a topbar bell with an unread badge and a Notifications page with local read state.

**Architecture:** Extend the Portal's existing template catalog / Notifier / `FormNotificationService` with a `tablet` receiver addressed to the submitting user, and expose the authenticated user's notifications through a new user-scoped sync pull endpoint. The tablet pulls incrementally by watermark into its existing `notifications` table (`insertOrIgnore`, read state local) and renders a custom Filament page plus a Livewire bell in the topbar.

**Tech Stack:** PHP 8.5, Laravel 13, Filament 5, Livewire 4, Pest 4, Laravel Pint, Spatie permissions, NativePHP tablet app.

**Spec:** `docs/superpowers/specs/2026-10-13-tablet-notifications-design.md` (tablet repo). Cross-repo Work ID: `OMMC-20261013-tablet-notifications`.

> **Revision (2026-10-13, Human correction):** Tasks 1 and 2 are CANCELLED.
> Tablet users are admin users with the DRM role and `AdminRole::roles()`
> already includes `drm`, so the existing `admin` templates and role-based
> recipients already notify them. No `tablet` receiver, no new templates or
> stubs, no seeder changes, and no `FormNotificationService` change. Remaining
> scope: Task 3 (sync endpoint) and Tasks 4-7 (tablet + validation). The spec
> reflects the corrected design; the ledger records the ruling.

## Global Constraints

- Repositories: Portal `C:\Users\RyanLVillanueva\Herd\ommc2027` (admin) and tablet `C:\Users\RyanLVillanueva\Herd\ommc2027-tablet`. Same Work ID and materially equivalent `docs/work/OMMC-20261013-tablet-notifications.md` notes in both.
- **Do NOT commit or push.** Implementation authorization covers local changes and validation only. Where a step would normally commit, run Pint + tests and update the Work note instead. Terminal delivery requires separate explicit Human authorization.
- No new Composer/npm dependencies. No schema changes in either repo (both `notifications` tables already exist). No changes to the admin `Notifier` API, `NotificationTemplate` model, or seeder mechanics.
- Notifications must never break an approval, a sync, or a page render: endpoint / pull / page-refresh failure → log or silently degrade, continue.
- Per-user filtering is structural: the admin sends to the submitter only, and the sync endpoint queries only `$request->user()->notifications()`. No client-supplied user id is ever accepted.
- Tablet read state is local only; pulled rows are inserted with `insertOrIgnore` so re-pulls never overwrite `read_at`.
- Follow existing file/style conventions; PHP: curly braces always, explicit return types, PHPDoc array shapes, TitleCase enums.
- Run `vendor/bin/pint --dirty --format agent` in a repo after modifying PHP files.
- Run tests with `php artisan test --compact` (filter with `--filter=`).
- Spec deferrals that remain out of scope: read-state push back, deep links/action buttons, native push, announcement broadcasts, pruning.

## Review Focus

1. **Notification rows with unusual payloads** (missing title/body/tag): the endpoint returns nulls and the page still renders safe fallbacks — pinned in Task 3 test ("returns nulls for payloads without title body or tag") and Task 5 rendering.
2. **Large notification volumes**: pagination must deliver every row without duplicates or stalls — pinned in Task 3 test ("paginates at two hundred rows per page") and Task 4 test ("follows has_more pages").
3. **The same notification pulled twice** (watermark overlap, page boundary, re-pull after reading): no duplicate rows, `read_at` preserved — pinned in Task 4 tests ("idempotent", "watermark").
4. **Offline / expired token while opening Notifications or during `pull()`**: local rows still render; the schedule pull still succeeds — pinned in Task 4 test ("schedule pull succeeds … notifications pull fails") and Task 5 test ("failed background refresh keeps local rows visible").
5. **Another user on the same device**: one rep must never see the other's notifications, and the bell count matches the current user — pinned in Task 5 test ("lists only the current users notifications") and Task 6 test ("counts only the current users unread").

---

## Task 1: CANCELLED — Portal tablet receiver, catalog events, and stubs (see revision note)

**Files:**
- Modify: `C:\Users\RyanLVillanueva\Herd\ommc2027\app\Constants\NotificationReceiver.php`
- Modify: `C:\Users\RyanLVillanueva\Herd\ommc2027\config\notification-template.php`
- Create: `C:\Users\RyanLVillanueva\Herd\ommc2027\resources\stubs\notification-templates\Tablet\Database\{31 stub files, listed below}`
- Modify: `C:\Users\RyanLVillanueva\Herd\ommc2027\tests\Feature\NotificationTemplateSeederTest.php`
- Create: `C:\Users\RyanLVillanueva\Herd\ommc2027\docs\work\OMMC-20261013-tablet-notifications.md`

**Interfaces:**
- Consumes: existing `App\Parsers\StubParser::getContents()`, existing seeder mechanics (`slug = receiver-module-event-channel`).
- Produces: `NotificationReceiver::TABLET = 'tablet'`; 31 seeded templates with slugs `tablet-{module}-{event}-in-app`, used by Task 2.

- [ ] **Step 1: Update the seeder test first (TDD RED)**

In `tests/Feature/NotificationTemplateSeederTest.php`, change the event count and add a tablet guard test. Replace `expect($events)->toHaveCount(42);` with:

```php
    expect($events)->toHaveCount(73);
```

Append this test at the end of the file:

```php
test('the notification template seeder stores the thirty-one tablet decision templates', function () {
    $this->seed(NotificationTemplateSeeder::class);

    $tablet = NotificationTemplate::query()->where('receiver', NotificationReceiver::TABLET);

    expect($tablet->count())->toBe(31)
        ->and(NotificationTemplate::query()->where('receiver', NotificationReceiver::TABLET)->where('slug', 'tablet-crr-approved-in-app')->exists())->toBeTrue()
        ->and(NotificationTemplate::query()->where('receiver', NotificationReceiver::TABLET)->where('slug', 'tablet-crr-submitted-in-app')->exists())->toBeFalse()
        ->and(NotificationTemplate::query()->where('receiver', NotificationReceiver::TABLET)->where('slug', 'tablet-training-completed-in-app')->exists())->toBeTrue();
});
```

- [ ] **Step 2: Run the seeder test to verify it fails**

Run in `ommc2027`: `php artisan test --compact --filter=NotificationTemplateSeeder`
Expected: FAIL — actual count 42 vs expected 73 (and `NotificationReceiver::TABLET` not defined).

- [ ] **Step 3: Add the TABLET receiver constant**

`app/Constants/NotificationReceiver.php` becomes:

```php
<?php

namespace App\Constants;

class NotificationReceiver
{
    /**
     * Admin
     *
     * @var string
     */
    final public const ADMIN = 'admin';

    /**
     * Tablet
     *
     * @var string
     */
    final public const TABLET = 'tablet';
}
```

Update the comment block above `return [` in `config/notification-template.php` from `| Admin Notifications` to `| Form Notifications` and the description line to "This array lists all of the available notification handlers (admin and tablet audiences) for specific modules."

- [ ] **Step 4: Create the 31 tablet stubs**

Create each file under `resources/stubs/notification-templates/Tablet/Database/` with exactly one line (no trailing newline needed; the seeder trims):

| File | Content |
| --- | --- |
| `crr-approved.stub` | `Your CRR {{reference}} for {{customer}} was approved by {{approver}} ({{stage}}).` |
| `crr-disapproved.stub` | `Your CRR {{reference}} for {{customer}} was disapproved by {{approver}} ({{stage}}). Remarks: {{remarks}}` |
| `crr-endorsed.stub` | `Your CRR {{reference}} for {{customer}} was endorsed by {{approver}} ({{stage}}).` |
| `crr-returned.stub` | `Your CRR {{reference}} for {{customer}} was returned for revision by {{approver}} ({{stage}}). Remarks: {{remarks}}` |
| `crr-completed.stub` | `Your CRR {{reference}} for {{customer}} was completed by {{approver}} ({{stage}}).` |
| `crr-signage-approved.stub` | `Your CRR Signage {{reference}} for {{customer}} was approved by {{approver}} ({{stage}}).` |
| `crr-signage-disapproved.stub` | `Your CRR Signage {{reference}} for {{customer}} was disapproved by {{approver}} ({{stage}}). Remarks: {{remarks}}` |
| `crr-signage-returned.stub` | `Your CRR Signage {{reference}} for {{customer}} was returned for revision by {{approver}} ({{stage}}). Remarks: {{remarks}}` |
| `crr-signage-completed.stub` | `Your CRR Signage {{reference}} for {{customer}} was completed by {{approver}} ({{stage}}).` |
| `giveaway-approved.stub` | `Your Giveaway {{reference}} for {{customer}} was approved by {{approver}} ({{stage}}).` |
| `giveaway-disapproved.stub` | `Your Giveaway {{reference}} for {{customer}} was disapproved by {{approver}} ({{stage}}). Remarks: {{remarks}}` |
| `marketing-approved.stub` | `Your Marketing Recommendation {{reference}} for {{customer}} was approved by {{approver}} ({{stage}}).` |
| `marketing-disapproved.stub` | `Your Marketing Recommendation {{reference}} for {{customer}} was disapproved by {{approver}} ({{stage}}). Remarks: {{remarks}}` |
| `marketing-endorsed.stub` | `Your Marketing Recommendation {{reference}} for {{customer}} was endorsed by {{approver}} ({{stage}}).` |
| `marketing-returned.stub` | `Your Marketing Recommendation {{reference}} for {{customer}} was returned for revision by {{approver}} ({{stage}}). Remarks: {{remarks}}` |
| `repair-approved.stub` | `Your Repair {{reference}} for {{customer}} was approved by {{approver}} ({{stage}}).` |
| `repair-disapproved.stub` | `Your Repair {{reference}} for {{customer}} was disapproved by {{approver}} ({{stage}}). Remarks: {{remarks}}` |
| `repair-endorsed.stub` | `Your Repair {{reference}} for {{customer}} was endorsed by {{approver}} ({{stage}}).` |
| `repair-returned.stub` | `Your Repair {{reference}} for {{customer}} was returned for revision by {{approver}} ({{stage}}). Remarks: {{remarks}}` |
| `repair-completed.stub` | `Your Repair {{reference}} for {{customer}} was completed by {{approver}} ({{stage}}).` |
| `trade-check-approved.stub` | `Your Trade Check {{reference}} for {{customer}} was approved by {{approver}} ({{stage}}).` |
| `trade-check-disapproved.stub` | `Your Trade Check {{reference}} for {{customer}} was disapproved by {{approver}} ({{stage}}). Remarks: {{remarks}}` |
| `trade-check-returned.stub` | `Your Trade Check {{reference}} for {{customer}} was returned for revision by {{approver}} ({{stage}}). Remarks: {{remarks}}` |
| `trade-request-approved.stub` | `Your Trade Request {{reference}} for {{customer}} was approved by {{approver}} ({{stage}}).` |
| `trade-request-disapproved.stub` | `Your Trade Request {{reference}} for {{customer}} was disapproved by {{approver}} ({{stage}}). Remarks: {{remarks}}` |
| `trade-request-returned.stub` | `Your Trade Request {{reference}} for {{customer}} was returned for revision by {{approver}} ({{stage}}). Remarks: {{remarks}}` |
| `trade-request-completed.stub` | `Your Trade Request {{reference}} for {{customer}} was completed by {{approver}} ({{stage}}).` |
| `training-approved.stub` | `Your Training {{reference}} for {{customer}} was approved by {{approver}} ({{stage}}).` |
| `training-disapproved.stub` | `Your Training {{reference}} for {{customer}} was disapproved by {{approver}} ({{stage}}). Remarks: {{remarks}}` |
| `training-returned.stub` | `Your Training {{reference}} for {{customer}} was returned for revision by {{approver}} ({{stage}}). Remarks: {{remarks}}` |
| `training-completed.stub` | `Your Training {{reference}} for {{customer}} was completed by {{approver}} ({{stage}}).` |

- [ ] **Step 5: Add the tablet events to the catalog**

In `config/notification-template.php`, append the following arrays to the END of each module's `'events' => [...]` list (keep the existing admin entries untouched).

CRR — append after the `crr` `resubmitted` entry:

```php
            [
                'subject' => 'CRR approved',
                'slug' => 'approved',
                'channels' => [
                    DatabaseChannel::class => StubParser::getContents('notification-templates/Tablet/Database/crr-approved.stub'),
                ],
                'receiver' => NotificationReceiver::TABLET,
                'description' => 'Notification content to inform the submitting user that their CRR has been approved.',
                'smart_tags' => ['reference', 'customer', 'approver', 'stage'],
            ],
            [
                'subject' => 'CRR disapproved',
                'slug' => 'disapproved',
                'channels' => [
                    DatabaseChannel::class => StubParser::getContents('notification-templates/Tablet/Database/crr-disapproved.stub'),
                ],
                'receiver' => NotificationReceiver::TABLET,
                'description' => 'Notification content to inform the submitting user that their CRR has been disapproved.',
                'smart_tags' => ['reference', 'customer', 'approver', 'stage', 'remarks'],
            ],
            [
                'subject' => 'CRR endorsed',
                'slug' => 'endorsed',
                'channels' => [
                    DatabaseChannel::class => StubParser::getContents('notification-templates/Tablet/Database/crr-endorsed.stub'),
                ],
                'receiver' => NotificationReceiver::TABLET,
                'description' => 'Notification content to inform the submitting user that their CRR has been endorsed to the next approval stage.',
                'smart_tags' => ['reference', 'customer', 'approver', 'stage'],
            ],
            [
                'subject' => 'CRR returned for revision',
                'slug' => 'returned',
                'channels' => [
                    DatabaseChannel::class => StubParser::getContents('notification-templates/Tablet/Database/crr-returned.stub'),
                ],
                'receiver' => NotificationReceiver::TABLET,
                'description' => 'Notification content to inform the submitting user that their CRR has been returned for revision.',
                'smart_tags' => ['reference', 'customer', 'approver', 'stage', 'remarks'],
            ],
            [
                'subject' => 'CRR completed',
                'slug' => 'completed',
                'channels' => [
                    DatabaseChannel::class => StubParser::getContents('notification-templates/Tablet/Database/crr-completed.stub'),
                ],
                'receiver' => NotificationReceiver::TABLET,
                'description' => 'Notification content to inform the submitting user that their CRR has been completed.',
                'smart_tags' => ['reference', 'customer', 'approver', 'stage'],
            ],
```

CRR Signage — append after the `crr-signage` `completed` entry:

```php
            [
                'subject' => 'CRR Signage approved',
                'slug' => 'approved',
                'channels' => [
                    DatabaseChannel::class => StubParser::getContents('notification-templates/Tablet/Database/crr-signage-approved.stub'),
                ],
                'receiver' => NotificationReceiver::TABLET,
                'description' => 'Notification content to inform the submitting user that their CRR Signage has been approved.',
                'smart_tags' => ['reference', 'customer', 'approver', 'stage'],
            ],
            [
                'subject' => 'CRR Signage disapproved',
                'slug' => 'disapproved',
                'channels' => [
                    DatabaseChannel::class => StubParser::getContents('notification-templates/Tablet/Database/crr-signage-disapproved.stub'),
                ],
                'receiver' => NotificationReceiver::TABLET,
                'description' => 'Notification content to inform the submitting user that their CRR Signage has been disapproved.',
                'smart_tags' => ['reference', 'customer', 'approver', 'stage', 'remarks'],
            ],
            [
                'subject' => 'CRR Signage returned for revision',
                'slug' => 'returned',
                'channels' => [
                    DatabaseChannel::class => StubParser::getContents('notification-templates/Tablet/Database/crr-signage-returned.stub'),
                ],
                'receiver' => NotificationReceiver::TABLET,
                'description' => 'Notification content to inform the submitting user that their CRR Signage has been returned for revision.',
                'smart_tags' => ['reference', 'customer', 'approver', 'stage', 'remarks'],
            ],
            [
                'subject' => 'CRR Signage completed',
                'slug' => 'completed',
                'channels' => [
                    DatabaseChannel::class => StubParser::getContents('notification-templates/Tablet/Database/crr-signage-completed.stub'),
                ],
                'receiver' => NotificationReceiver::TABLET,
                'description' => 'Notification content to inform the submitting user that their CRR Signage has been completed.',
                'smart_tags' => ['reference', 'customer', 'approver', 'stage'],
            ],
```

Giveaway — append after the `giveaway` `disapproved` entry:

```php
            [
                'subject' => 'Giveaway approved',
                'slug' => 'approved',
                'channels' => [
                    DatabaseChannel::class => StubParser::getContents('notification-templates/Tablet/Database/giveaway-approved.stub'),
                ],
                'receiver' => NotificationReceiver::TABLET,
                'description' => 'Notification content to inform the submitting user that their Giveaway has been approved.',
                'smart_tags' => ['reference', 'customer', 'approver', 'stage'],
            ],
            [
                'subject' => 'Giveaway disapproved',
                'slug' => 'disapproved',
                'channels' => [
                    DatabaseChannel::class => StubParser::getContents('notification-templates/Tablet/Database/giveaway-disapproved.stub'),
                ],
                'receiver' => NotificationReceiver::TABLET,
                'description' => 'Notification content to inform the submitting user that their Giveaway has been disapproved.',
                'smart_tags' => ['reference', 'customer', 'approver', 'stage', 'remarks'],
            ],
```

Marketing Recommendation — append after the `marketing` `resubmitted` entry:

```php
            [
                'subject' => 'Marketing Recommendation approved',
                'slug' => 'approved',
                'channels' => [
                    DatabaseChannel::class => StubParser::getContents('notification-templates/Tablet/Database/marketing-approved.stub'),
                ],
                'receiver' => NotificationReceiver::TABLET,
                'description' => 'Notification content to inform the submitting user that their Marketing Recommendation has been approved.',
                'smart_tags' => ['reference', 'customer', 'approver', 'stage'],
            ],
            [
                'subject' => 'Marketing Recommendation disapproved',
                'slug' => 'disapproved',
                'channels' => [
                    DatabaseChannel::class => StubParser::getContents('notification-templates/Tablet/Database/marketing-disapproved.stub'),
                ],
                'receiver' => NotificationReceiver::TABLET,
                'description' => 'Notification content to inform the submitting user that their Marketing Recommendation has been disapproved.',
                'smart_tags' => ['reference', 'customer', 'approver', 'stage', 'remarks'],
            ],
            [
                'subject' => 'Marketing Recommendation endorsed',
                'slug' => 'endorsed',
                'channels' => [
                    DatabaseChannel::class => StubParser::getContents('notification-templates/Tablet/Database/marketing-endorsed.stub'),
                ],
                'receiver' => NotificationReceiver::TABLET,
                'description' => 'Notification content to inform the submitting user that their Marketing Recommendation has been endorsed to the next approval stage.',
                'smart_tags' => ['reference', 'customer', 'approver', 'stage'],
            ],
            [
                'subject' => 'Marketing Recommendation returned for revision',
                'slug' => 'returned',
                'channels' => [
                    DatabaseChannel::class => StubParser::getContents('notification-templates/Tablet/Database/marketing-returned.stub'),
                ],
                'receiver' => NotificationReceiver::TABLET,
                'description' => 'Notification content to inform the submitting user that their Marketing Recommendation has been returned for revision.',
                'smart_tags' => ['reference', 'customer', 'approver', 'stage', 'remarks'],
            ],
```

Repair — append after the `repair` `resubmitted` entry:

```php
            [
                'subject' => 'Repair approved',
                'slug' => 'approved',
                'channels' => [
                    DatabaseChannel::class => StubParser::getContents('notification-templates/Tablet/Database/repair-approved.stub'),
                ],
                'receiver' => NotificationReceiver::TABLET,
                'description' => 'Notification content to inform the submitting user that their Repair has been approved.',
                'smart_tags' => ['reference', 'customer', 'approver', 'stage'],
            ],
            [
                'subject' => 'Repair disapproved',
                'slug' => 'disapproved',
                'channels' => [
                    DatabaseChannel::class => StubParser::getContents('notification-templates/Tablet/Database/repair-disapproved.stub'),
                ],
                'receiver' => NotificationReceiver::TABLET,
                'description' => 'Notification content to inform the submitting user that their Repair has been disapproved.',
                'smart_tags' => ['reference', 'customer', 'approver', 'stage', 'remarks'],
            ],
            [
                'subject' => 'Repair endorsed',
                'slug' => 'endorsed',
                'channels' => [
                    DatabaseChannel::class => StubParser::getContents('notification-templates/Tablet/Database/repair-endorsed.stub'),
                ],
                'receiver' => NotificationReceiver::TABLET,
                'description' => 'Notification content to inform the submitting user that their Repair has been endorsed to the next approval stage.',
                'smart_tags' => ['reference', 'customer', 'approver', 'stage'],
            ],
            [
                'subject' => 'Repair returned for revision',
                'slug' => 'returned',
                'channels' => [
                    DatabaseChannel::class => StubParser::getContents('notification-templates/Tablet/Database/repair-returned.stub'),
                ],
                'receiver' => NotificationReceiver::TABLET,
                'description' => 'Notification content to inform the submitting user that their Repair has been returned for revision.',
                'smart_tags' => ['reference', 'customer', 'approver', 'stage', 'remarks'],
            ],
            [
                'subject' => 'Repair completed',
                'slug' => 'completed',
                'channels' => [
                    DatabaseChannel::class => StubParser::getContents('notification-templates/Tablet/Database/repair-completed.stub'),
                ],
                'receiver' => NotificationReceiver::TABLET,
                'description' => 'Notification content to inform the submitting user that their Repair has been completed.',
                'smart_tags' => ['reference', 'customer', 'approver', 'stage'],
            ],
```

Trade Check — append after the `trade-check` `returned` entry:

```php
            [
                'subject' => 'Trade Check approved',
                'slug' => 'approved',
                'channels' => [
                    DatabaseChannel::class => StubParser::getContents('notification-templates/Tablet/Database/trade-check-approved.stub'),
                ],
                'receiver' => NotificationReceiver::TABLET,
                'description' => 'Notification content to inform the submitting user that their Trade Check has been approved.',
                'smart_tags' => ['reference', 'customer', 'approver', 'stage'],
            ],
            [
                'subject' => 'Trade Check disapproved',
                'slug' => 'disapproved',
                'channels' => [
                    DatabaseChannel::class => StubParser::getContents('notification-templates/Tablet/Database/trade-check-disapproved.stub'),
                ],
                'receiver' => NotificationReceiver::TABLET,
                'description' => 'Notification content to inform the submitting user that their Trade Check has been disapproved.',
                'smart_tags' => ['reference', 'customer', 'approver', 'stage', 'remarks'],
            ],
            [
                'subject' => 'Trade Check returned for revision',
                'slug' => 'returned',
                'channels' => [
                    DatabaseChannel::class => StubParser::getContents('notification-templates/Tablet/Database/trade-check-returned.stub'),
                ],
                'receiver' => NotificationReceiver::TABLET,
                'description' => 'Notification content to inform the submitting user that their Trade Check has been returned for revision.',
                'smart_tags' => ['reference', 'customer', 'approver', 'stage', 'remarks'],
            ],
```

Trade Request — append after the `trade-request` `completed` entry:

```php
            [
                'subject' => 'Trade Request approved',
                'slug' => 'approved',
                'channels' => [
                    DatabaseChannel::class => StubParser::getContents('notification-templates/Tablet/Database/trade-request-approved.stub'),
                ],
                'receiver' => NotificationReceiver::TABLET,
                'description' => 'Notification content to inform the submitting user that their Trade Request has been approved.',
                'smart_tags' => ['reference', 'customer', 'approver', 'stage'],
            ],
            [
                'subject' => 'Trade Request disapproved',
                'slug' => 'disapproved',
                'channels' => [
                    DatabaseChannel::class => StubParser::getContents('notification-templates/Tablet/Database/trade-request-disapproved.stub'),
                ],
                'receiver' => NotificationReceiver::TABLET,
                'description' => 'Notification content to inform the submitting user that their Trade Request has been disapproved.',
                'smart_tags' => ['reference', 'customer', 'approver', 'stage', 'remarks'],
            ],
            [
                'subject' => 'Trade Request returned for revision',
                'slug' => 'returned',
                'channels' => [
                    DatabaseChannel::class => StubParser::getContents('notification-templates/Tablet/Database/trade-request-returned.stub'),
                ],
                'receiver' => NotificationReceiver::TABLET,
                'description' => 'Notification content to inform the submitting user that their Trade Request has been returned for revision.',
                'smart_tags' => ['reference', 'customer', 'approver', 'stage', 'remarks'],
            ],
            [
                'subject' => 'Trade Request completed',
                'slug' => 'completed',
                'channels' => [
                    DatabaseChannel::class => StubParser::getContents('notification-templates/Tablet/Database/trade-request-completed.stub'),
                ],
                'receiver' => NotificationReceiver::TABLET,
                'description' => 'Notification content to inform the submitting user that their Trade Request has been completed.',
                'smart_tags' => ['reference', 'customer', 'approver', 'stage'],
            ],
```

Training — append after the `training` `completed` entry:

```php
            [
                'subject' => 'Training approved',
                'slug' => 'approved',
                'channels' => [
                    DatabaseChannel::class => StubParser::getContents('notification-templates/Tablet/Database/training-approved.stub'),
                ],
                'receiver' => NotificationReceiver::TABLET,
                'description' => 'Notification content to inform the submitting user that their Training has been approved.',
                'smart_tags' => ['reference', 'customer', 'approver', 'stage'],
            ],
            [
                'subject' => 'Training disapproved',
                'slug' => 'disapproved',
                'channels' => [
                    DatabaseChannel::class => StubParser::getContents('notification-templates/Tablet/Database/training-disapproved.stub'),
                ],
                'receiver' => NotificationReceiver::TABLET,
                'description' => 'Notification content to inform the submitting user that their Training has been disapproved.',
                'smart_tags' => ['reference', 'customer', 'approver', 'stage', 'remarks'],
            ],
            [
                'subject' => 'Training returned for revision',
                'slug' => 'returned',
                'channels' => [
                    DatabaseChannel::class => StubParser::getContents('notification-templates/Tablet/Database/training-returned.stub'),
                ],
                'receiver' => NotificationReceiver::TABLET,
                'description' => 'Notification content to inform the submitting user that their Training has been returned for revision.',
                'smart_tags' => ['reference', 'customer', 'approver', 'stage', 'remarks'],
            ],
            [
                'subject' => 'Training completed',
                'slug' => 'completed',
                'channels' => [
                    DatabaseChannel::class => StubParser::getContents('notification-templates/Tablet/Database/training-completed.stub'),
                ],
                'receiver' => NotificationReceiver::TABLET,
                'description' => 'Notification content to inform the submitting user that their Training has been completed.',
                'smart_tags' => ['reference', 'customer', 'approver', 'stage'],
            ],
```

- [ ] **Step 6: Run the seeder tests to verify they pass**

Run in `ommc2027`: `php artisan test --compact --filter=NotificationTemplateSeeder`
Expected: PASS (all tests in the file).

- [ ] **Step 7: Create the Work note**

Create `docs/work/OMMC-20261013-tablet-notifications.md`:

```markdown
# OMMC-20261013-tablet-notifications

- **State:** ACTIVE
- **Repositories:** Portal `ommc2027` and Tablet `ommc2027-tablet`
- **Authorization:** Human authorized local implementation and validation of per-user tablet notifications (form decision events for the submitting user), local read state, topbar bell and Notifications page. Terminal delivery is not authorized; no commit or push.

## Objective and accepted decisions

Notify the user who submitted a form when a decision is recorded, delivered to the tablet through a user-scoped sync pull. Admin: `tablet` receiver templates for approved/disapproved/endorsed/returned/completed, addressed to the submitter. Tablet: watermark pull into the local `notifications` table, Livewire bell + Filament page, local-only read state. Filtering by user is structural on both sides (submitter addressing; `$request->user()->notifications()`).

## Constraints

- No schema/dependency changes; reuse the existing template catalog, Notifier, sync conventions.
- Notifications must never break approvals, syncs, or page renders.
- Tablet read state is local; re-pulls must never overwrite `read_at`.

## Task-owned files

- Portal: `app/Constants/NotificationReceiver.php`, `config/notification-template.php`, `resources/stubs/notification-templates/Tablet/**`, `app/Services/Notifications/FormNotificationService.php`, `app/Http/Controllers/Api/SyncController.php`, `routes/api.php`, `tests/Feature/NotificationTemplateSeederTest.php`, `tests/Feature/TabletNotificationDeliveryTest.php`, `tests/Feature/Api/NotificationsPullTest.php`
- Tablet: `app/Services/SyncService.php`, `app/Filament/Pages/NotificationsPage.php`, `app/Livewire/NotificationsBell.php`, views under `resources/views/filament/**` and `resources/views/livewire/**`, `app/Providers/Filament/SaleshubPanelProvider.php`, `resources/views/filament/pages/settings-page.blade.php`, `tests/Feature/SyncNotificationsTest.php`, `tests/Feature/NotificationsPageTest.php`, `tests/Feature/NotificationsBellTest.php`
- Spec: `docs/superpowers/specs/2026-10-13-tablet-notifications-design.md` (tablet repo)

## Validation

Pending.

## Blockers and remaining work

None blocking.
```

- [ ] **Step 8: Checkpoint**

Run in `ommc2027`: `vendor/bin/pint --dirty --format agent`, then `php artisan test --compact --filter=NotificationTemplateSeeder`.
Do NOT commit (authorization boundary).

---

## Task 2: CANCELLED — Portal FormNotificationService submitter notifications (see revision note)

**Files:**
- Modify: `C:\Users\RyanLVillanueva\Herd\ommc2027\app\Services\Notifications\FormNotificationService.php`
- Create: `C:\Users\RyanLVillanueva\Herd\ommc2027\tests\Feature\TabletNotificationDeliveryTest.php`

**Interfaces:**
- Consumes: `NotificationReceiver::TABLET` and the 31 templates from Task 1; existing `Notifier`, `smartTags()`, `MODULES` map.
- Produces: on every decision event except `resubmitted`, one database notification addressed to the submitting user with title/body/tag (`crr`, etc.). Task 3 returns these rows through the sync endpoint.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/TabletNotificationDeliveryTest.php`:

```php
<?php

use App\Models\Customer;
use App\Models\Forms\CRR\Crr;
use App\Models\Itinerary;
use App\Models\Salescall;
use App\Models\User;
use App\Services\Notifications\FormNotificationService;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function tabletNotifySalescall(User $creator, string $customerName = 'ACME Parts'): Salescall
{
    $suffix = str()->random(6);

    $region = DB::table('regions')->insertGetId(['code' => 'TN'.$suffix, 'name' => 'Region', 'created_at' => now(), 'updated_at' => now()]);
    $regionSpecific = DB::table('region_specifics')->insertGetId(['region_id' => $region, 'name' => 'RS', 'created_at' => now(), 'updated_at' => now()]);
    $province = DB::table('provinces')->insertGetId(['region_specific_id' => $regionSpecific, 'name' => 'P', 'created_at' => now(), 'updated_at' => now()]);
    $municipality = DB::table('municipalities')->insertGetId(['region_id' => $region, 'province_id' => $province, 'name' => 'M', 'created_at' => now(), 'updated_at' => now()]);

    $customer = Customer::create([
        'region_specific_id' => $regionSpecific,
        'municipality_id' => $municipality,
        'name' => $customerName,
        'is_active' => true,
        'created_by' => $creator->id,
    ]);

    $itinerary = Itinerary::create(['created_by' => $creator->id, 'itinerary_status_id' => 1]);

    return Salescall::create([
        'local_uuid' => (string) str()->uuid(),
        'itinerary_id' => $itinerary->id,
        'customer_id' => $customer->id,
        'created_by' => $creator->id,
        'route_start_at' => now(),
    ]);
}

test('a decision notifies the submitting user with the tablet template', function () {
    $this->seed(NotificationTemplateSeeder::class);

    $submitter = User::factory()->create(['name' => 'Dana', 'last_name' => 'Reyes']);
    $salescall = tabletNotifySalescall($submitter);
    $crr = Crr::factory()->create(['salescall_id' => $salescall->id, 'submitted_by' => $submitter->id]);
    $actor = User::factory()->create(['name' => 'Juan', 'last_name' => 'Dela Cruz']);

    (new FormNotificationService)->formDecided($crr, $actor, 'rsm', 'approve', 'Looks good.');

    $notification = $submitter->notifications()->first();

    expect($notification)->not->toBeNull()
        ->and($notification->data['title'])->toBe('CRR approved')
        ->and($notification->data['body'])->toBe(
            "Your CRR {$salescall->ref_number} for ACME Parts was approved by {$actor->fullName()} (RSM)."
        )
        ->and($notification->data['tag'])->toBe('crr')
        ->and($notification->data['format'])->toBe('filament');
});

test('a form submission does not notify the submitting user', function () {
    $this->seed(NotificationTemplateSeeder::class);

    $submitter = User::factory()->create();
    $crr = Crr::factory()->create(['submitted_by' => $submitter->id]);

    (new FormNotificationService)->formSubmitted($crr);

    expect($submitter->notifications()->count())->toBe(0);
});

test('a resubmission decision does not notify the submitting user', function () {
    $this->seed(NotificationTemplateSeeder::class);

    $submitter = User::factory()->create();
    $crr = Crr::factory()->create(['submitted_by' => $submitter->id]);

    (new FormNotificationService)->formDecided($crr, User::factory()->create(), 'resubmit', 'resubmit', '');

    expect($submitter->notifications()->count())->toBe(0);
});

test('a decision on a form without a submitter does not break and sends nothing', function () {
    $this->seed(NotificationTemplateSeeder::class);

    $crr = Crr::factory()->create(['submitted_by' => null]);

    (new FormNotificationService)->formDecided($crr, User::factory()->create(), 'rsm', 'approve', '');

    expect(DB::table('notifications')->count())->toBe(0);
});

test('a decision without a seeded tablet catalog sends nothing and does not throw', function () {
    $submitter = User::factory()->create();
    $crr = Crr::factory()->create(['submitted_by' => $submitter->id]);

    (new FormNotificationService)->formDecided($crr, User::factory()->create(), 'rsm', 'approve', '');

    expect($submitter->notifications()->count())->toBe(0);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run in `ommc2027`: `php artisan test --compact --filter=TabletNotificationDelivery`
Expected: FAIL — the first test gets no notification for `$submitter` (admin-audience sends go only to `AdminRole` holders).

- [ ] **Step 3: Implement the submitter notification**

In `app/Services/Notifications/FormNotificationService.php`, replace `formDecided()` and `smartTags()` with the versions below and add `notifySubmitter()` + `resolveSubmitter()`:

```php
    /**
     * Notify the admin roles about a recorded approval decision and the
     * submitting user about the outcome.
     */
    public function formDecided(Model $form, User $actor, string $stage, string $decision, string $remarks = ''): void
    {
        $event = self::DECISIONS[$decision] ?? null;

        if ($event === null) {
            Log::warning("Form notification skipped: unknown decision [{$decision}].");

            return;
        }

        $extraTags = [
            'approver' => $actor->fullName(),
            'stage' => $stage === 'completion' ? 'Completion' : strtoupper($stage),
            'remarks' => $remarks,
        ];

        $this->send($form, $event, $extraTags);
        $this->notifySubmitter($form, $event, $extraTags);
    }

    /**
     * Notify the user who submitted the form about a decision outcome.
     *
     * @param  array<string, string>  $extraTags
     */
    protected function notifySubmitter(Model $form, string $event, array $extraTags): void
    {
        if ($event === 'resubmitted') {
            return;
        }

        $module = self::MODULES[$form::class] ?? null;

        if ($module === null) {
            return;
        }

        $submitter = $this->resolveSubmitter($form);

        if (! $submitter instanceof User) {
            return;
        }

        $slug = implode('-', [
            NotificationReceiver::TABLET,
            $module['slug'],
            $event,
            NotificationChannel::IN_APP,
        ]);

        if (NotificationTemplate::query()->where('slug', $slug)->doesntExist()) {
            Log::warning("Tablet notification skipped: no template for slug [{$slug}].");

            return;
        }

        Notifier::setTag($module['slug'])->send($slug, $submitter, array_merge($this->smartTags($form), $extraTags));
    }

    /**
     * The user who submitted the form, resolved across both submission shapes.
     */
    protected function resolveSubmitter(Model $form): ?User
    {
        $submitter = $form->submittedBy ?? $form->firstAnswer?->submittedBy;

        return $submitter instanceof User ? $submitter : null;
    }

    /**
     * @return array<string, string>
     */
    protected function smartTags(Model $form): array
    {
        $salescall = $form->salescall ?? $form->firstAnswer?->salescall;
        $submitter = $this->resolveSubmitter($form);

        return [
            'reference' => (string) ($salescall?->ref_number ?? ''),
            'customer' => (string) ($salescall?->customer?->name ?? ''),
            'submitted_by' => $submitter?->fullName() ?? '',
        ];
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run in `ommc2027`: `php artisan test --compact --filter=TabletNotificationDelivery`
Expected: PASS (5 tests).

- [ ] **Step 5: Run the existing integration tests to check for regressions**

Run in `ommc2027`: `php artisan test --compact --filter=FormNotificationIntegration`
Expected: PASS. (The existing tests do not count submitter notifications; if any assertion now conflicts, update only the affected assertion — do not weaken coverage.)

- [ ] **Step 6: Checkpoint**

Run in `ommc2027`: `vendor/bin/pint --dirty --format agent`.
Update the Work note Validation section with the focused test results.
Do NOT commit (authorization boundary).

---

## Task 3: Portal — user-scoped notification pull endpoint

**Files:**
- Modify: `C:\Users\RyanLVillanueva\Herd\ommc2027\routes\api.php`
- Modify: `C:\Users\RyanLVillanueva\Herd\ommc2027\app\Http\Controllers\Api\SyncController.php`
- Create: `C:\Users\RyanLVillanueva\Herd\ommc2027\tests\Feature\Api\NotificationsPullTest.php`

**Interfaces:**
- Consumes: existing Portal notification rows produced by the untouched `FormNotificationService` / `Notifier` flow; existing `SyncTokenMiddleware` (`tablet-api` guard); `Request`, `JsonResponse`, `Carbon` already imported in `SyncController`.
- Produces: `GET /api/sync/pull/notifications?since=<ISO8601>&page=<n>` → `{ notifications: [{id,title,body,module,created_at}], has_more: bool }`. Task 4 consumes this exact contract.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Api/NotificationsPullTest.php`:

```php
<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function notificationsPullUser(): User
{
    $user = User::factory()->create();
    $user->generateApiToken();

    return $user;
}

function notificationsPullCreate(User $user, string $title, ?string $createdAt = null, ?string $tag = 'crr'): string
{
    return $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'templated-notification',
        'data' => ['title' => $title, 'body' => "Body for {$title}", 'tag' => $tag],
        'created_at' => $createdAt ?? now(),
        'updated_at' => $createdAt ?? now(),
    ])->id;
}

test('the notifications pull requires a token', function () {
    $this->getJson('/api/sync/pull/notifications')->assertUnauthorized();
});

test('the notifications pull returns only the authenticated users notifications', function () {
    $user = notificationsPullUser();
    $other = notificationsPullUser();

    notificationsPullCreate($user, 'Mine', tag: 'repair');
    notificationsPullCreate($other, 'Theirs', tag: 'crr');

    $this->withToken($user->api_token)
        ->getJson('/api/sync/pull/notifications')
        ->assertOk()
        ->assertJsonCount(1, 'notifications')
        ->assertJsonPath('notifications.0.title', 'Mine')
        ->assertJsonPath('notifications.0.body', 'Body for Mine')
        ->assertJsonPath('notifications.0.module', 'repair')
        ->assertJsonPath('has_more', false);
});

test('the notifications pull honors the since watermark with a five minute overlap', function () {
    $user = notificationsPullUser();

    notificationsPullCreate($user, 'Old', now()->subHour()->toDateTimeString());
    notificationsPullCreate($user, 'Recent', now()->subMinute()->toDateTimeString());

    $this->withToken($user->api_token)
        ->getJson('/api/sync/pull/notifications?since='.urlencode(now()->subMinutes(10)->toIso8601String()))
        ->assertOk()
        ->assertJsonCount(1, 'notifications')
        ->assertJsonPath('notifications.0.title', 'Recent');
});

test('the notifications pull orders oldest first', function () {
    $user = notificationsPullUser();

    notificationsPullCreate($user, 'Second', now()->subMinute()->toDateTimeString());
    notificationsPullCreate($user, 'First', now()->subMinutes(5)->toDateTimeString());

    $this->withToken($user->api_token)
        ->getJson('/api/sync/pull/notifications')
        ->assertOk()
        ->assertJsonPath('notifications.0.title', 'First')
        ->assertJsonPath('notifications.1.title', 'Second');
});

test('the notifications pull returns nulls for payloads without title body or tag', function () {
    $user = notificationsPullUser();

    $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'templated-notification',
        'data' => ['format' => 'filament'],
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->withToken($user->api_token)
        ->getJson('/api/sync/pull/notifications')
        ->assertOk()
        ->assertJsonPath('notifications.0.title', null)
        ->assertJsonPath('notifications.0.body', null)
        ->assertJsonPath('notifications.0.module', null);
});

test('the notifications pull paginates at two hundred rows per page', function () {
    $user = notificationsPullUser();

    for ($index = 1; $index <= 201; $index++) {
        notificationsPullCreate($user, "Notification {$index}");
    }

    $this->withToken($user->api_token)
        ->getJson('/api/sync/pull/notifications')
        ->assertOk()
        ->assertJsonCount(200, 'notifications')
        ->assertJsonPath('has_more', true);

    $this->withToken($user->api_token)
        ->getJson('/api/sync/pull/notifications?page=2')
        ->assertOk()
        ->assertJsonCount(1, 'notifications')
        ->assertJsonPath('has_more', false);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run in `ommc2027`: `php artisan test --compact --filter=NotificationsPull`
Expected: FAIL — route `/api/sync/pull/notifications` returns 404 for authenticated requests while the unauthenticated test may already pass.

- [ ] **Step 3: Add the route**

In `routes/api.php`, inside the `Route::middleware(SyncTokenMiddleware::class)->prefix('sync')->group(...)` block, add directly after the `/pull/customers` line:

```php
    Route::get('/pull/notifications', [SyncController::class, 'pullNotifications']);
```

- [ ] **Step 4: Implement the endpoint**

In `app/Http/Controllers/Api/SyncController.php`, add this method (place it after `pullCustomers()`):

```php
    /**
     * Tablet notification pull: the authenticated user's in-app notifications,
     * oldest first, two hundred rows per page.
     */
    public function pullNotifications(Request $request): JsonResponse
    {
        $data = $request->validate([
            'since' => 'nullable|date',
            'page' => 'nullable|integer|min:1',
        ]);

        $query = $request->user()
            ->notifications()
            ->orderBy('created_at');

        if (filled($data['since'] ?? null)) {
            $query->where('created_at', '>=', Carbon::parse($data['since'])->subMinutes(5));
        }

        $page = $query->paginate(200, ['*'], 'page', (int) ($data['page'] ?? 1));

        return response()->json([
            'notifications' => collect($page->items())->map(fn ($notification): array => [
                'id' => $notification->id,
                'title' => $notification->data['title'] ?? null,
                'body' => $notification->data['body'] ?? null,
                'module' => $notification->data['tag'] ?? null,
                'created_at' => $notification->created_at->toIso8601String(),
            ])->all(),
            'has_more' => $page->hasMorePages(),
        ]);
    }
```

- [ ] **Step 5: Run the tests to verify they pass**

Run in `ommc2027`: `php artisan test --compact --filter=NotificationsPull`
Expected: PASS (6 tests).

- [ ] **Step 6: Checkpoint**

Run in `ommc2027`: `vendor/bin/pint --dirty --format agent`.
Update the Work note Validation section.
Do NOT commit (authorization boundary).

---

## Task 4: Tablet — `SyncService::pullNotifications()` and `pull()` integration

**Files:**
- Modify: `C:\Users\RyanLVillanueva\Herd\ommc2027-tablet\app\Services\SyncService.php`
- Create: `C:\Users\RyanLVillanueva\Herd\ommc2027-tablet\tests\Feature\SyncNotificationsTest.php`
- Create: `C:\Users\RyanLVillanueva\Herd\ommc2027-tablet\docs\work\OMMC-20261013-tablet-notifications.md`

**Interfaces:**
- Consumes: Task 3's endpoint contract; existing `fetchPullPayload(string $path, array $query)`, `syncState()`, `putSyncState()`, `SyncResult`.
- Produces: `SyncService::pullNotifications(): SyncResult`; rows in the local `notifications` table with `type = 'templated-notification'`, `notifiable = local user`, `data = {title, body, tag}`, local `read_at`. Task 5 reads these rows.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/SyncNotificationsTest.php`:

```php
<?php

use App\Models\User;
use App\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function syncNotificationsUser(): User
{
    return User::factory()->create(['api_token' => 'notify-token', 'server_id' => 9001]);
}

function syncNotificationsRow(string $id, string $title, string $createdAt, string $tag = 'crr'): array
{
    return [
        'id' => $id,
        'title' => $title,
        'body' => "Body for {$title}",
        'module' => $tag,
        'created_at' => $createdAt,
    ];
}

test('pull notifications stores rows for the logged-in user with mapped data', function () {
    config(['sync.server_url' => 'http://portal.test']);

    $user = syncNotificationsUser();
    $this->actingAs($user);

    $id = (string) Str::uuid();
    $createdAt = now()->subMinutes(5)->toIso8601String();

    Http::fake([
        'portal.test/api/sync/pull/notifications*' => Http::response([
            'notifications' => [syncNotificationsRow($id, 'CRR approved', $createdAt)],
            'has_more' => false,
        ], 200),
    ]);

    $result = app(SyncService::class)->pullNotifications();

    expect($result->success)->toBeTrue($result->message);

    $row = DB::table('notifications')->where('id', $id)->first();

    expect($row)->not->toBeNull()
        ->and($row->type)->toBe('templated-notification')
        ->and($row->notifiable_type)->toBe(User::class)
        ->and((int) $row->notifiable_id)->toBe($user->id)
        ->and($row->read_at)->toBeNull();

    $data = json_decode($row->data, true);

    expect($data['title'])->toBe('CRR approved')
        ->and($data['body'])->toBe('Body for CRR approved')
        ->and($data['tag'])->toBe('crr');
});

test('pull notifications is idempotent and preserves local read state', function () {
    config(['sync.server_url' => 'http://portal.test']);

    $user = syncNotificationsUser();
    $this->actingAs($user);

    $id = (string) Str::uuid();

    Http::fake([
        'portal.test/api/sync/pull/notifications*' => Http::response([
            'notifications' => [syncNotificationsRow($id, 'CRR approved', now()->subMinute()->toIso8601String())],
            'has_more' => false,
        ], 200),
    ]);

    app(SyncService::class)->pullNotifications();

    DB::table('notifications')->where('id', $id)->update(['read_at' => now()]);
    $readAt = DB::table('notifications')->where('id', $id)->value('read_at');

    app(SyncService::class)->pullNotifications();

    expect(DB::table('notifications')->count())->toBe(1)
        ->and(DB::table('notifications')->where('id', $id)->value('read_at'))->toBe($readAt);
});

test('pull notifications sends the watermark as since on the next pull', function () {
    config(['sync.server_url' => 'http://portal.test']);

    $user = syncNotificationsUser();
    $this->actingAs($user);

    $createdAt = now()->subMinutes(3)->toIso8601String();

    Http::fake([
        'portal.test/api/sync/pull/notifications*' => Http::response([
            'notifications' => [syncNotificationsRow((string) Str::uuid(), 'CRR approved', $createdAt)],
            'has_more' => false,
        ], 200),
    ]);

    app(SyncService::class)->pullNotifications();
    app(SyncService::class)->pullNotifications();

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'since=')
        && str_contains(urldecode($request->url()), $createdAt));
});

test('pull notifications follows has_more pages', function () {
    config(['sync.server_url' => 'http://portal.test']);

    $user = syncNotificationsUser();
    $this->actingAs($user);

    Http::fake(function ($request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
        $page = (int) ($query['page'] ?? 1);

        return Http::response([
            'notifications' => [syncNotificationsRow((string) Str::uuid(), "Page {$page}", now()->subMinutes(10 - $page)->toIso8601String())],
            'has_more' => $page === 1,
        ], 200);
    });

    $result = app(SyncService::class)->pullNotifications();

    expect($result->success)->toBeTrue($result->message)
        ->and(DB::table('notifications')->count())->toBe(2);
});

test('pull notifications fails with a token error on 401', function () {
    config(['sync.server_url' => 'http://portal.test']);

    $user = syncNotificationsUser();
    $this->actingAs($user);

    Http::fake([
        'portal.test/api/sync/pull/notifications*' => Http::response(['error' => 'Unauthorized'], 401),
    ]);

    $result = app(SyncService::class)->pullNotifications();

    expect($result->success)->toBeFalse()
        ->and($result->errorCode)->toBe('token_expired');
});

test('the schedule pull succeeds even when the notifications pull fails', function () {
    config(['sync.server_url' => 'http://portal.test']);

    $user = syncNotificationsUser();
    $this->actingAs($user);

    Http::fake([
        'portal.test/api/sync/pull/locations' => Http::response(emptyLocationReferenceSnapshot(), 200),
        'portal.test/api/sync/pull/schedule' => Http::response([
            'customers' => [],
            'users' => [],
            'itineraries' => [],
            'customer_notes' => [],
        ], 200),
        'portal.test/api/sync/pull/notifications*' => Http::response(['error' => 'Server error'], 500),
    ]);

    $result = app(SyncService::class)->pull();

    expect($result->success)->toBeTrue($result->message);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run in `ommc2027-tablet`: `php artisan test --compact --filter=SyncNotifications`
Expected: FAIL — `SyncService::pullNotifications()` does not exist (6 failures/errors).

- [ ] **Step 3: Implement `pullNotifications()` and wire it into `pull()`**

In `app/Services/SyncService.php`:

1. Add the import at the top with the other `Illuminate\Support` imports:

```php
use Illuminate\Support\Carbon;
```

2. Add these constants next to `CUSTOMER_PULL_PAGE_SIZE`:

```php
    /** Per-user watermark key for the notification pull. */
    private const NOTIFICATIONS_PULL_WATERMARK = 'notifications.pulled_through.';

    /** Hard ceiling on notification pull pages per run. */
    private const NOTIFICATIONS_PULL_PAGE_LIMIT = 100;
```

3. Add this method after `pullLocations()`:

```php
    /**
     * Notification pull: the logged-in user's in-app notifications, stored
     * locally with device-local read state.
     */
    public function pullNotifications(): SyncResult
    {
        $user = auth()->user() ?? User::whereNotNull('api_token')->first();

        if (! $user || blank($user->api_token)) {
            return SyncResult::fail('No API token found. Please log in first.', 'no_token');
        }

        $watermarkKey = self::NOTIFICATIONS_PULL_WATERMARK.($user->server_id ?? $user->id);
        $state = $this->syncState($watermarkKey);
        $since = is_array($state) ? ($state['last_created_at'] ?? null) : null;

        $rows = [];
        $page = 1;

        do {
            $query = ['page' => $page];

            if (is_string($since) && $since !== '') {
                $query['since'] = $since;
            }

            $data = $this->fetchPullPayload($user, '/api/sync/pull/notifications', $query);

            if ($data instanceof SyncResult) {
                return $data;
            }

            foreach ($data['notifications'] ?? [] as $row) {
                if (is_array($row) && is_string($row['id'] ?? null) && $row['id'] !== '') {
                    $rows[$row['id']] = $row;
                }
            }

            $page++;
        } while (($data['has_more'] ?? false) === true && $page <= self::NOTIFICATIONS_PULL_PAGE_LIMIT);

        $pulledAt = now();

        if ($rows !== []) {
            $insertRows = array_map(function (array $row) use ($user, $pulledAt): array {
                $createdAt = is_string($row['created_at'] ?? null)
                    ? Carbon::parse($row['created_at'])->setTimezone(config('app.timezone'))->toDateTimeString()
                    : $pulledAt->toDateTimeString();

                return [
                    'id' => $row['id'],
                    'type' => 'templated-notification',
                    'notifiable_type' => User::class,
                    'notifiable_id' => $user->id,
                    'data' => json_encode([
                        'title' => $row['title'] ?? null,
                        'body' => $row['body'] ?? null,
                        'tag' => $row['module'] ?? null,
                    ], JSON_THROW_ON_ERROR),
                    'read_at' => null,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ];
            }, array_values($rows));

            foreach (array_chunk($insertRows, 100) as $chunk) {
                DB::table('notifications')->insertOrIgnore($chunk);
            }
        }

        $createdAts = array_filter(
            array_map(fn (array $row): mixed => $row['created_at'] ?? null, array_values($rows)),
            'is_string',
        );

        $this->putSyncState($watermarkKey, [
            'last_created_at' => $createdAts === [] ? $since : max($createdAts),
            'pulled_at' => $pulledAt->toISOString(),
        ]);

        return SyncResult::ok('Pulled '.count($rows).' notifications.', count($rows));
    }
```

4. In `pull()`, directly after the `try/catch` block around `DB::transaction(...)` and before `$itineraryCount = ...`, add:

```php
        try {
            $this->pullNotifications();
        } catch (Throwable $exception) {
            Log::warning('Notification pull skipped: '.$exception->getMessage());
        }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run in `ommc2027-tablet`: `php artisan test --compact --filter=SyncNotifications`
Expected: PASS (6 tests).

- [ ] **Step 5: Run the existing pull tests to check for regressions**

Run in `ommc2027-tablet`: `php artisan test --compact --filter=SyncServicePullFreshInstall` and `php artisan test --compact --filter=SyncPullResponseSink`
Expected: PASS. (`pull()` now also calls the notifications endpoint; if a test asserts the exact set of requested URLs, add the notifications stub to its `Http::fake` map without weakening the original assertions.)

- [ ] **Step 6: Create the Work note**

Create `docs/work/OMMC-20261013-tablet-notifications.md` with the same objective/decisions/constraints/task-owned-files sections as the Portal note (Task 1), adjusted for the tablet repo files listed in this plan, plus:

```markdown
## Validation

Pending.

## Blockers and remaining work

None blocking.
```

- [ ] **Step 7: Checkpoint**

Run in `ommc2027-tablet`: `vendor/bin/pint --dirty --format agent`.
Update the Work note Validation section.
Do NOT commit (authorization boundary).

---

## Task 5: Tablet — Notifications page (list, tap to read + expand, mark all)

**Files:**
- Create: `C:\Users\RyanLVillanueva\Herd\ommc2027-tablet\app\Filament\Pages\NotificationsPage.php`
- Create: `C:\Users\RyanLVillanueva\Herd\ommc2027-tablet\resources\views\filament\pages\notifications-page.blade.php`
- Modify: `C:\Users\RyanLVillanueva\Herd\ommc2027-tablet\resources\views\filament\pages\settings-page.blade.php`
- Create: `C:\Users\RyanLVillanueva\Herd\ommc2027-tablet\tests\Feature\NotificationsPageTest.php`

**Interfaces:**
- Consumes: local rows from Task 4; `SyncService::pullNotifications()` and `isReachable()`.
- Produces: route name `filament.saleshub.pages.notifications` (URL `/app/notifications`), Livewire events `notifications-updated` (consumed by Task 6).

Note: the spec's "refresh on mount" is implemented as a non-blocking `wire:init="refresh"` so the page paints immediately and the pull happens in the background (it can take up to the sync timeout when the network is flaky).

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/NotificationsPageTest.php`:

```php
<?php

use App\Filament\Pages\NotificationsPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function notificationsPageCreate(User $user, string $title, ?string $readAt = null, ?string $tag = 'crr'): string
{
    return $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'templated-notification',
        'data' => ['title' => $title, 'body' => "Body for {$title}", 'tag' => $tag],
        'read_at' => $readAt,
        'created_at' => now(),
        'updated_at' => now(),
    ])->id;
}

test('the notifications page lists only the current users notifications', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    notificationsPageCreate($user, 'Mine');
    notificationsPageCreate($other, 'Theirs');

    $this->actingAs($user);

    Livewire::test(NotificationsPage::class)
        ->assertSee('Mine')
        ->assertSee('CRR')
        ->assertDontSee('Theirs');
});

test('tapping a notification marks it read and expands it', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $id = notificationsPageCreate($user, 'CRR approved');

    Livewire::test(NotificationsPage::class)
        ->call('toggle', $id)
        ->assertSet('expandedId', $id);

    expect(DB::table('notifications')->where('id', $id)->value('read_at'))->not->toBeNull();
});

test('tapping an expanded notification collapses it', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $id = notificationsPageCreate($user, 'CRR approved', readAt: now()->toDateTimeString());

    Livewire::test(NotificationsPage::class)
        ->call('toggle', $id)
        ->assertSet('expandedId', $id)
        ->call('toggle', $id)
        ->assertSet('expandedId', null);
});

test('mark all as read clears unread notifications and dispatches a refresh', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    notificationsPageCreate($user, 'One');
    notificationsPageCreate($user, 'Two');

    Livewire::test(NotificationsPage::class)
        ->call('markAllAsRead')
        ->assertDispatched('notifications-updated');

    expect($user->unreadNotifications()->count())->toBe(0);
});

test('load more increases the visible window', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(NotificationsPage::class)
        ->assertSet('perPage', 20)
        ->call('loadMore')
        ->assertSet('perPage', 40);
});

test('a failed background refresh keeps local rows visible', function () {
    config(['sync.server_url' => 'http://portal.test']);

    $user = User::factory()->create(['api_token' => 'page-token']);
    $this->actingAs($user);

    notificationsPageCreate($user, 'Offline visible');

    Http::fake([
        'portal.test/api/ping' => Http::response([], 200),
        'portal.test/api/sync/pull/notifications*' => Http::response(['error' => 'Server error'], 500),
    ]);

    Livewire::test(NotificationsPage::class)
        ->call('refresh')
        ->assertSee('Offline visible');
});

test('the notifications page renders an empty state without rows', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(NotificationsPage::class)
        ->assertSee('No notifications yet');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run in `ommc2027-tablet`: `php artisan test --compact --filter=NotificationsPage`
Expected: FAIL — `App\Filament\Pages\NotificationsPage` does not exist.

- [ ] **Step 3: Create the page class**

Create `app/Filament/Pages/NotificationsPage.php`:

```php
<?php

namespace App\Filament\Pages;

use App\Services\SyncService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Log;
use Throwable;

class NotificationsPage extends Page
{
    /**
     * Module tag to display label.
     *
     * @var array<string, string>
     */
    protected const MODULE_LABELS = [
        'crr' => 'CRR',
        'crr-signage' => 'CRR Signage',
        'giveaway' => 'Giveaway',
        'marketing' => 'Marketing',
        'repair' => 'Repair',
        'trade-check' => 'Trade Check',
        'trade-request' => 'Trade Request',
        'training' => 'Training',
    ];

    protected string $view = 'filament.pages.notifications-page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBell;

    protected static ?string $slug = 'notifications';

    protected static ?string $title = '';

    protected static bool $shouldRegisterNavigation = false;

    public int $perPage = 20;

    public ?string $expandedId = null;

    /**
     * Refresh from the Portal in the background; failures keep local data.
     */
    public function refresh(): void
    {
        try {
            $sync = app(SyncService::class);

            if ($sync->isReachable()) {
                $sync->pullNotifications();
            }
        } catch (Throwable $exception) {
            Log::debug('Notification refresh skipped: '.$exception->getMessage());
        }

        $this->dispatch('notifications-updated');
    }

    /**
     * @return Collection<int, DatabaseNotification>
     */
    public function getNotifications(): Collection
    {
        return auth()->user()
            ?->notifications()
            ->latest()
            ->limit($this->perPage)
            ->get() ?? new Collection;
    }

    /**
     * Mark the notification read and expand or collapse its full body.
     */
    public function toggle(string $notificationId): void
    {
        $notification = auth()->user()
            ?->notifications()
            ->whereKey($notificationId)
            ->first();

        if (! $notification) {
            return;
        }

        if ($notification->read_at === null) {
            $notification->markAsRead();
        }

        $this->expandedId = $this->expandedId === $notificationId ? null : $notificationId;

        $this->dispatch('notifications-updated');
    }

    public function loadMore(): void
    {
        $this->perPage += 20;
    }

    public function markAllAsRead(): void
    {
        auth()->user()?->unreadNotifications()->update(['read_at' => now()]);

        $this->expandedId = null;

        $this->dispatch('notifications-updated');
    }

    public function moduleLabel(?string $tag): string
    {
        if ($tag === null || $tag === '') {
            return 'Form';
        }

        return self::MODULE_LABELS[$tag] ?? (string) str($tag)->headline();
    }
}
```

- [ ] **Step 4: Create the page view**

Create `resources/views/filament/pages/notifications-page.blade.php`:

```blade
<x-filament-panels::page>

@php($notifications = $this->getNotifications())

<div class="max-w-lg mx-auto space-y-4 pb-8" wire:init="refresh">

    <div class="px-1 pt-2 pb-1 flex items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-black text-[#191c1e]">Notifications</h1>
            <p class="text-sm text-[#737685] mt-0.5">Form updates for your submissions</p>
        </div>

        @if ($notifications->whereNull('read_at')->isNotEmpty())
            <button type="button" wire:click="markAllAsRead"
                    class="text-xs font-bold text-[#890f00] uppercase tracking-wide whitespace-nowrap pb-1">
                Mark all read
            </button>
        @endif
    </div>

    @if ($notifications->isEmpty())
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 px-6 py-12 text-center">
            <span class="material-symbols-outlined text-4xl text-gray-300">notifications_off</span>
            <p class="mt-3 font-semibold text-sm text-[#191c1e]">No notifications yet</p>
            <p class="text-xs text-[#737685] mt-1">Form approval updates will appear here.</p>
        </div>
    @else
        <div class="space-y-3">
            @foreach ($notifications as $notification)
                @php($isUnread = $notification->read_at === null)
                @php($isExpanded = $expandedId === $notification->id)

                <button type="button" wire:click="toggle('{{ $notification->id }}')"
                        class="w-full text-left bg-white rounded-2xl shadow-sm border border-gray-100 px-5 py-4 {{ $isUnread ? 'border-l-4 border-l-[#890f00]' : '' }}">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-[10px] font-black uppercase tracking-widest text-[#890f00]">
                            {{ $this->moduleLabel($notification->data['tag'] ?? null) }}
                        </span>
                        <span class="text-[10px] text-[#737685]">{{ $notification->created_at?->diffForHumans() }}</span>
                    </div>
                    <div class="mt-1 flex items-start gap-2">
                        @if ($isUnread)
                            <span class="mt-1.5 w-2 h-2 rounded-full bg-[#890f00] shrink-0"></span>
                        @endif
                        <div class="flex-1 min-w-0">
                            <p class="font-semibold text-sm text-[#191c1e]">{{ $notification->data['title'] ?? 'Form update' }}</p>
                            <p class="text-xs text-[#737685] mt-0.5 {{ $isExpanded ? '' : 'truncate' }}">
                                {{ $notification->data['body'] ?? '' }}
                            </p>
                        </div>
                    </div>
                </button>
            @endforeach
        </div>

        @if ($notifications->count() >= $perPage)
            <button type="button" wire:click="loadMore"
                    class="w-full text-center text-xs font-bold text-[#890f00] uppercase tracking-wide py-2">
                Load more
            </button>
        @endif
    @endif
</div>

</x-filament-panels::page>
```

- [ ] **Step 5: Link the page from Settings**

In `resources/views/filament/pages/settings-page.blade.php`, replace the disabled Notifications row (the `<div class="flex items-center gap-4 px-5 py-4 opacity-50">` block containing `<p class="font-semibold text-sm text-[#191c1e]">Notifications</p>` and the `Soon` span) with:

```blade
            <a href="/app/notifications"
               class="flex items-center gap-4 px-5 py-4 hover:bg-gray-50 transition-colors active:bg-gray-100">
                <div class="w-10 h-10 bg-blue-50 rounded-xl flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-blue-600">notifications</span>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="font-semibold text-sm text-[#191c1e]">Notifications</p>
                    <p class="text-xs text-[#737685]">Form approval updates</p>
                </div>
                <span class="material-symbols-outlined text-gray-300 text-xl">chevron_right</span>
            </a>
```

- [ ] **Step 6: Run the tests to verify they pass**

Run in `ommc2027-tablet`: `php artisan test --compact --filter=NotificationsPage`
Expected: PASS (7 tests). Also run `php artisan test --compact --filter=SettingsPageReleaseMetadata` to confirm the settings edit did not regress.

- [ ] **Step 7: Checkpoint**

Run in `ommc2027-tablet`: `vendor/bin/pint --dirty --format agent`.
Update the Work note Validation section.
Do NOT commit (authorization boundary).

---

## Task 6: Tablet — topbar notification bell

**Files:**
- Create: `C:\Users\RyanLVillanueva\Herd\ommc2027-tablet\app\Livewire\NotificationsBell.php`
- Create: `C:\Users\RyanLVillanueva\Herd\ommc2027-tablet\resources\views\livewire\notifications-bell.blade.php`
- Create: `C:\Users\RyanLVillanueva\Herd\ommc2027-tablet\resources\views\filament\partials\notifications-bell.blade.php`
- Modify: `C:\Users\RyanLVillanueva\Herd\ommc2027-tablet\app\Providers\Filament\SaleshubPanelProvider.php`
- Create: `C:\Users\RyanLVillanueva\Herd\ommc2027-tablet\tests\Feature\NotificationsBellTest.php`

**Interfaces:**
- Consumes: local rows from Task 4; `notifications-updated` events from Task 5; route `filament.saleshub.pages.notifications`.
- Produces: unread badge in the topbar on every authenticated page.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/NotificationsBellTest.php`:

```php
<?php

use App\Livewire\NotificationsBell;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function notificationsBellCreate(User $user, ?string $readAt = null): string
{
    return $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'templated-notification',
        'data' => ['title' => 'CRR approved', 'body' => 'Body', 'tag' => 'crr'],
        'read_at' => $readAt,
        'created_at' => now(),
        'updated_at' => now(),
    ])->id;
}

test('the bell counts only the current users unread notifications', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $this->actingAs($user);

    notificationsBellCreate($user);
    notificationsBellCreate($user, readAt: now()->toDateTimeString());
    notificationsBellCreate($other);

    Livewire::test(NotificationsBell::class)
        ->assertSet('unreadCount', 1)
        ->assertSee('1');
});

test('the bell refreshes when a notifications-updated event is dispatched', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $component = Livewire::test(NotificationsBell::class)->assertSet('unreadCount', 0);

    notificationsBellCreate($user);

    $component->dispatch('notifications-updated')->assertSet('unreadCount', 1);
});

test('the bell links to the notifications page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(NotificationsBell::class)
        ->assertSee(route('filament.saleshub.pages.notifications'));
});

test('the panel topbar renders the notification bell for authenticated users', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get('/app')
        ->assertOk()
        ->assertSee(route('filament.saleshub.pages.notifications'));
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run in `ommc2027-tablet`: `php artisan test --compact --filter=NotificationsBell`
Expected: FAIL — `App\Livewire\NotificationsBell` does not exist.

- [ ] **Step 3: Create the Livewire component and views**

Create `app/Livewire/NotificationsBell.php`:

```php
<?php

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class NotificationsBell extends Component
{
    public int $unreadCount = 0;

    public function mount(): void
    {
        $this->refreshUnreadCount();
    }

    #[On('notifications-updated')]
    public function refreshUnreadCount(): void
    {
        $this->unreadCount = (int) (auth()->user()?->unreadNotifications()->count() ?? 0);
    }

    public function render(): View
    {
        return view('livewire.notifications-bell');
    }
}
```

Create `resources/views/livewire/notifications-bell.blade.php`:

```blade
<a href="{{ route('filament.saleshub.pages.notifications') }}" title="Notifications"
   wire:poll.60s="refreshUnreadCount"
   style="position:relative; display:inline-flex; align-items:center; justify-content:center;
          width:2rem; height:2rem; border-radius:9999px; background:#000; color:#fff;
          margin-right:0.25rem; text-decoration:none; font-size:1.1rem;">
    <span class="material-symbols-outlined" style="font-size:1.1rem;">notifications</span>
    @if ($unreadCount > 0)
        <span style="position:absolute; top:-4px; right:-4px; min-width:16px; height:16px; padding:0 4px;
                     border-radius:9999px; background:#dc2626; color:#fff; font-size:10px; font-weight:800;
                     line-height:16px; text-align:center;">
            {{ $unreadCount > 9 ? '9+' : $unreadCount }}
        </span>
    @endif
</a>
```

Create `resources/views/filament/partials/notifications-bell.blade.php`:

```blade
@livewire('notifications-bell')
```

- [ ] **Step 4: Register the bell in the topbar**

In `app/Providers/Filament/SaleshubPanelProvider.php`, add this render hook registration immediately BEFORE the existing `->renderHook('panels::user-menu.before', ...)` refresh-button block (so the bell renders to its left):

```php
            ->renderHook(
                'panels::user-menu.before',
                fn () => view('filament.partials.notifications-bell'),
            )
```

- [ ] **Step 5: Run the tests to verify they pass**

Run in `ommc2027-tablet`: `php artisan test --compact --filter=NotificationsBell`
Expected: PASS (4 tests).

If the topbar test fails because mounting a Livewire component inside the render hook is unsupported, STOP and report the blocker instead of silently changing approach; per the spec the approved fallback is a server-rendered bell plus a CustomEvent bridge, but that changes this task's deliverables and must be confirmed.

- [ ] **Step 6: Checkpoint**

Run in `ommc2027-tablet`: `vendor/bin/pint --dirty --format agent`.
Update the Work note Validation section.
Do NOT commit (authorization boundary).

---

## Task 7: Final validation and Work-note reconciliation (both repos)

**Files:**
- Modify: `C:\Users\RyanLVillanueva\Herd\ommc2027\docs\work\OMMC-20261013-tablet-notifications.md`
- Modify: `C:\Users\RyanLVillanueva\Herd\ommc2027-tablet\docs\work\OMMC-20261013-tablet-notifications.md`

- [ ] **Step 1: Run the full Portal suite**

Run in `ommc2027`: `php artisan test --compact`
Expected: all PASS except any pre-existing failure already recorded in that repo's Work notes (verify it reproduces in isolation before treating it as pre-existing).

- [ ] **Step 2: Run the full tablet suite**

Run in `ommc2027-tablet`: `php artisan test --compact`
Expected: all PASS.

- [ ] **Step 3: Final Pint pass on both repos**

Run in each repo: `vendor/bin/pint --dirty --format agent`
Expected: clean (no further changes needed).

- [ ] **Step 4: Update both Work notes**

For each repo's `docs/work/OMMC-20261013-tablet-notifications.md`:
- Set `State:` to `READY_FOR_TERMINAL_REVIEW`.
- Validation: focus-suite and full-suite results with counts and the exact commands.
- Manual acceptance: pending Human review (endpoint payload, bell badge, page behavior).
- Record any unrelated/pre-existing worktree changes observed and preserved.
- Remaining work: terminal delivery only, requiring separate explicit authorization.

- [ ] **Step 5: Report readiness**

Report: task-owned files, focused + full test evidence, pint status, manual-acceptance items, and that no commit/push occurred. STOP — do not commit or push without explicit terminal authorization (`TERMINAL: GO` / `COMMIT AND PUSH`).
