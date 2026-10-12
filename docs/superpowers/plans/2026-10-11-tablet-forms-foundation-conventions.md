# Tablet Forms foundation — implementation conventions

This file is the authoritative convention brief for building one form's
foundation slice. Read it fully before writing code.

Spec: `docs/superpowers/specs/2026-10-11-tablet-forms-foundation-design.md`

## Already built (do not modify)

- `app/Enums/Traits/BaseEnum.php` and all 16 enums under `app/Enums`
  (`RSMStatus`, `SPSStatus`, `SPMStatus`, `RACStatus`, `TDHStatus`, `TDSStatus`,
  `FormKey`, and the per-form enums).
- `app/Contracts/ProvidesFormRules.php` — `public static function rules(): array`.
- `app/Services/Forms/AbstractFormService.php` — shared base class.
- `tests/Feature/Forms/FoundationEnumsTest.php`,
  `FoundationSchemaTest.php`, `AbstractFormServiceTest.php`.

Never edit those, other forms' files, or existing unrelated tests.

## Reference sources (read these)

- Portal migrations: `C:\Users\RyanLVillanueva\Herd\ommc2027\database\migrations\`
- Portal models: `C:\Users\RyanLVillanueva\Herd\ommc2027\app\Models\Forms\<Folder>\`
- Portal push request: `C:\Users\RyanLVillanueva\Herd\ommc2027\app\Http\Requests\Api\Sync\`
- Tablet table convention:
  `C:\Users\RyanLVillanueva\Herd\ommc2027-tablet\database\migrations\2026_09_24_000002_create_expenses_table.php`
- Tablet model convention:
  `C:\Users\RyanLVillanueva\Herd\ommc2027-tablet\app\Models\Expense.php`

## Migration conventions

- Forward-only. `up()` creates tables; `down(): void {}` is an empty body with a
  one-line comment `// Forms schema is forward-only by project safety policy.`
- Use `Schema::create('<table>', function (Blueprint $table): void { ... });`.
- No `softDeletes()` locally. Always `$table->timestamps();`.
- **Master tables:** `id`, `server_id` (`unsignedBigInteger` nullable **unique**),
  `name` (string), `timestamps`.
- **Submission header tables:** the Portal columns with the same types, plus the
  offline envelope in this order:
  - `$table->string('local_uuid', 36)->unique();`
  - `$table->unsignedBigInteger('server_id')->nullable()->index();`
  - `$table->foreignId('submitted_by')->constrained('users');`
  - `$table->foreignId('salescall_id')->nullable()->constrained('salescalls')->nullOnDelete();`
  - every status column: `unsignedTinyInteger` with `->default(1)` (even where the
    Portal has no default or a different one)
  - `$table->string('sync_status', 20)->default('pending');`
  - `$table->unsignedTinyInteger('sync_attempts')->default(0);`
  - `$table->text('sync_error')->nullable();`
  - `$table->timestamp('synced_at')->nullable();`
  - `$table->timestamps();`
- **Child tables:** the Portal columns, with the parent foreign key as
  `$table->foreignId('<parent>_id')->constrained('<parent_table>')->cascadeOnDelete();`.
  No sync envelope.
- **Attachment tables:** the Portal typed columns (e.g. `type`, `item_id`,
  `answer_id`) plus the parent foreign key, but replace the Portal's
  `uploaded_file_id` with:
  - `$table->string('local_uuid', 36)->unique();`
  - `$table->unsignedBigInteger('server_id')->nullable()->index();`
  - `$table->string('local_path')->nullable();`
  - `$table->string('original_name')->nullable();`
  - `$table->string('mime_type')->nullable();`
  - `$table->string('extension')->nullable();`
  - `$table->unsignedBigInteger('byte_size')->nullable();`
  - the sync envelope (`sync_status`, `sync_attempts`, `sync_error`, `synced_at`)
  - `$table->timestamps();`
- Foreign keys between local form tables must point at the **local** table names
  from the table map below (e.g. `marketings.program_id` → `marketing_programs`,
  not the Portal's generic `programs`).
- Do not create `uploaded_files`, `form_approvals`, `form_types`, or `forms`.

## Model conventions

- Namespace `App\Models\Forms\<Folder>`; `protected $guarded = ['id'];`.
- Casts: status/type columns to the matching `App\Enums\...` class; decimals to
  `decimal:2`; datetimes to `datetime`; json `options` to `array`; booleans.
- Relationships mirror the Portal (belongsTo / hasMany / belongsToMany replaced
  by hasMany where the Portal used an attachment pivot). The local attachment
  table is a plain hasMany with the parent FK.
- A master model whose class name differs from its table sets `protected $table`.

## Rules conventions

- `App\Support\Forms\<Name>Rules implements App\Contracts\ProvidesFormRules`.
- `public static function rules(): array` mirrors the Portal `Push*Request`
  rules for the header + children (do not include attachment `file` rules).
- Adapt `exists:` rules to the **local** table names (table map below).
- Keep nullable/required/type constraints identical to the Portal.

## Test conventions

- Pest, `tests/Feature/Forms/<Form>FoundationTest.php`, `uses(RefreshDatabase::class)`.
- Assert the tables and key columns exist (`Schema::hasColumns`).
- Build a representative record set (create a `User` with `User::factory()`),
  then assert at least one enum cast and one relationship per model.
- Assert `Validator::make($validPayload, Rules::rules())->passes()` is true and a
  deliberately invalid payload fails. Per-form rules tests use
  `Illuminate\Support\Facades\Validator`.
- **Do not run the test suite** (parallel agents would race on migrations).
  Run `php -l` on every PHP file you create, and nothing else.
- Do not run `composer`, `pint`, or `php artisan migrate`.

## Table map (local names)

Masters are marked (M). Submission headers carry the envelope.

| Form | Tables |
|---|---|
| CRR | `crr_programs` (M), `crrs`, `crr_attachments` |
| Marketing | `marketing_programs` (M), `marketings`, `marketing_volumes`, `marketing_realizations`, `marketing_mechanics` |
| Trade Check | `trade_checks`, `trade_check_groups`, `trade_check_items`, `trade_check_answers`, `trade_check_answer_items`, `trade_check_attachments` |
| Giveaway | `giveaways`, `giveaway_items`, `giveaway_answers`, `giveaway_answer_items`, `giveaway_signatures` |
| Repair | `repairs`, `repair_attachments` |
| CRR Signage | `signage_products` (M), `signage_types` (M), `signages`, `signage_attachments` |
| Trade Request | `trade_products` (M), `trade_types` (M), `trade_requests`, `trade_request_items`, `trade_request_attachments` |
| Training | `training_topics` (M), `training_positions` (M), `trainings`, `training_participants`, `training_attachments` |

## Notes

- Marketing `submitted_at`/`duration_start_at`/`duration_end_at` are NOT NULL
  datetimes; `program_id` is NOT NULL and points at `marketing_programs`.
- Trade Check and Giveaway have no separate master tables; the definition rows
  travel inside the submission.
- Trade Check uses `TradeCheckAnswerItems` (class) / `trade_check_answer_items`
  (table); Giveaway uses `GiveawayAnswerItem` / `giveaway_answer_items`.
- Repair/Signage/TradeRequest/Training use typed attachment `type` columns.
- Never add HTTP controllers, routes, or FormRequest classes.
