# OMMC-20261001-customer-code-tablet

- **Title:** Tablet authoritative Customer Code generation
- **Objective:** Add Portal-backed Customer Code reservation and delayed-sync reconciliation to the Tablet Add Customer flow.
- **Repositories:** Portal `ommc2027` (existing authority preserved); Tablet `ommc2027-tablet`.
- **State:** TERMINAL_DELIVERED
- **Implementation authorization:** Granted by explicit Human `GO` instruction.
- **Terminal authorization:** Granted by explicit Human `TERMINAL: GO` instruction.
- **Human-authorized scope:** Tablet Add Customer Customer Code reservation, retained reservation-token metadata, existing Customer sync payload extension, and focused validation.
- **Accepted decisions:** Portal is the sole allocator. Tablet never calculates sequences. Supported namespaces remain OMMC, LAST MILE, CAR CLUBS, FLEET, OE, and IB; OB and IE are not introduced. Reservation failures leave the code blank/pending. Reservation tokens are retained locally for delayed sync and cleared after successful Portal reconciliation.
- **Task-owned Tablet files:** `app/Filament/Pages/CustomerCreatePage.php`, `app/Services/SyncService.php`, `resources/views/filament/pages/customer-create-page.blade.php`, the additive Customer reservation-token migration, focused Customer Code tests, and this Work note.
- **Unrelated changes preserved:** Prior delivered field-border work was preserved. No Portal files were modified by this implementation.
- **Implementation:** Company changes clear code/token, then call the existing authenticated Portal reservation endpoint. The Customer Code control is read-only. Offline or failed reservations do not allocate locally. Pending Customer sync sends `customer_code_reservation_token`; successful sync stores Portal `unique_id` and clears the consumed token.
- **Validation:** Focused Tablet tests passed: 5 tests, 20 assertions. Tablet Blade cache, PHP syntax, and staged `git diff --check` passed. Full Tablet suite ran with 111 passing, 2 skipped, and 6 unrelated pre-existing failures. Tablet Pint reported existing style violations in the two modified legacy classes; no formatter rewrite was included. No Portal functional files were modified in the Tablet repository.
- **Manual acceptance:** Browser acceptance PASS. Physical iPad acceptance PASS. Accepted behavior covers Portal-authoritative generation, FLEET formatting, CARCLUBS padding, Company changes, no Tablet-local sequence, and physical iPad operation.
- **Blockers:** None for the bounded functionality. Unrelated baseline test/style failures remain outside scope.
- **Remaining work:** None within this bounded Work.
- **Delivery evidence:** The terminal commit and normal remote push are recorded by the delivery response. No force push, reset, history rewrite, local database, runtime, or unrelated Customer functionality was included.
