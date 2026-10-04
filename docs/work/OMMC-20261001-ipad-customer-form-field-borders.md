# OMMC-20261001-ipad-customer-form-field-borders

## Work state

`TERMINAL_DELIVERED`

## Human visual review follow-up

The first Human visual review did not pass: several rendered controls,
including the Owner Profile inputs, still appeared as label plus blank white
space without a visible boundary.

The next Human visual review confirmed that the borders were visible and the
original requirement was satisfied. A final presentation refinement was then
requested because the controls remained too square and tight compared with
the Portal form reference.

## Clarified client requirement

The iPad Add Customer screen needs clear, consistent visible boundaries around
editable text fields and dropdown/select controls against the white form/card.
This is a presentation-only fix. Labels, spacing, field values, validation,
persistence, synchronization, and all other form behavior remain out of scope.

## Scope and authorization

- Repository: `/Users/highlite/Workspace-Kaisa/PHP/ommc2027-tablet`
- Work is limited to the iPad Add Customer form.
- Portal is not modified.
- Customer Code generation and behavior changes are explicitly deferred.
- Broader Customer Form content/behavior alignment is explicitly deferred.
- Commit, push, and terminal delivery required separate authorization; that
  authorization was subsequently granted after Human visual acceptance.

Terminal delivery was explicitly authorized after Human acceptance of the
final Add Customer field styling.

## Implementation

The Add Customer form has a page-specific `customer-create-form` hook. The
first correction only set `border-color`, but Filament's rendered `.fi-input`
base style sets `border: none`, so that rule could not create a visible edge.
The corrective implementation now sets an explicit 1px neutral border on
Filament inputs, native selects, textareas, and non-checkbox/radio inputs,
with the existing primary color retained for focus. The Vite theme asset was
rebuilt so the browser receives the correction. The styling is
Add-Customer-specific rather than a shared/global input-component change, so
no unrelated-screen impact is expected.

The final polish keeps that scoped treatment and adds modest Portal-inspired
rounding, horizontal and vertical padding, and label-to-control spacing. The
existing rows, multi-select heights, textarea sizing, dropdown indicators,
and disabled-state styling are otherwise preserved.

The final Human-requested color refinement keeps that geometry and changes the
control treatment to a soft cool light-gray border, near-white field
background, readable muted placeholders, and a slightly muted disabled state.
Focus remains visually distinct without using the darker outline treatment.

## Controls covered

The rule covers the representative controls named in the issue, including
Fleet Account Name, Customer Code, Company, General Category, Access,
Address, Contact Person, Business Landline Number, Business Mobile Number,
Date Established, and equivalent editable controls in the Location, profile,
annual-category, and owner sections.

## Validation

- `git diff --check`: passed after the corrective change.
- `php artisan view:cache`: passed.
- Vite production asset build: passed.
- Final visual refinement is scoped to the existing Add Customer controls; no
  form content or layout structure was changed.
- Final color/contrast refinement preserves the accepted radius, padding,
  sizing, spacing, and layout.
- Automated application tests were not created or run, per Work scope.
- Manual visual acceptance completed on the complete Add Customer screen:
  borders and focus treatment, readable labels, visible dropdown indicators,
  understandable disabled/read-only controls, unchanged layout/scrolling, and
  unchanged behavior were accepted.
- Human acceptance granted for the final presentation: visible `#d1d5db`
  borders, near-white backgrounds, rounded corners, accepted padding and
  spacing, readable muted placeholders, and softer disabled controls.
- Terminal validation reconfirmed before delivery: Vite build passed, Blade
  view cache passed, and `git diff --check` passed. The existing Node/Vite
  version warning was non-blocking; the build completed successfully.

## Behavior safety and remaining work

No Livewire bindings, field values, validation rules, persistence, SQLite,
offline behavior, synchronization, API contracts, or customer-creation
semantics were changed. Customer Code generation and broader form alignment
remain deferred to their separate Work.

## Terminal delivery

Only the tablet Add Customer view, its scoped stylesheet, and this Work record
belong to this delivery. No Portal files or unrelated worktree changes were
included. The commit and push were performed only after explicit `TERMINAL:
GO` authorization.

Customer Code generation based on Company and broader Customer Form
content/behavior alignment remain deferred to the next separate iPad Work.
