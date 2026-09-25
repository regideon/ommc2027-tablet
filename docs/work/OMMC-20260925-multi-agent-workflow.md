# OMMC-20260925-multi-agent-workflow

- **Title:** Multi-Agent Delivery and Continuation Protocol
- **Objective:** Establish a shared Claude Code/Codex continuation and terminal-delivery workflow.
- **Repositories:** Portal `ommc2027`; Tablet `ommc2027-tablet`
- **State:** TERMINAL_DELIVERED
- **Implementation authorization:** Granted for this Work.
- **Terminal authorization:** Granted by explicit Human `TERMINAL: GO` instruction.
- **Human-authorized scope:** Add shared workflow documentation, Codex bootstrap files, durable Work-note contract, and agent bootstrap references. No application feature changes.
- **Accepted decisions:** Implementation and terminal delivery are separate authorization boundaries. Cross-repository Work uses one Work ID and equivalent notes. Delivery uses separate commits and normal pushes.
- **Constraints:** Do not commit, push, access databases or production, or modify existing Phase 8 and acceptance-data work.
- **Task-owned files:** `AGENTS.md`, `CLAUDE.md`, `docs/agent-workflow.md`, `docs/work/README.md`, and this Work note in each repository.
- **Unrelated worktree changes to preserve:** Portal `database/seeders/ExpenseAcceptanceDataSeeder.php`. Tablet `.gitignore`, `app/Filament/Pages/SalescallPage.php`, `resources/views/filament/pages/salescall-page.blade.php`, `app/Filament/Pages/ExpenseCreatePage.php`, `resources/views/filament/pages/expense-create-page.blade.php`, and local environment files.
- **Implementation decisions:** The shared workflow document is authoritative; `AGENTS.md` and `CLAUDE.md` are bootstrap surfaces. Work notes contain no secrets or environment contents. The final delivery commit uses Work-ID and Work-State trailers without embedding its own hash.
- **Validation:** Protocol files reviewed; shared workflow and Work-note contracts are materially synchronized; `git diff --check` passed for task-owned changes.
- **Manual acceptance:** Not applicable; documentation-only protocol change.
- **Blockers:** None.
- **Remaining work:** None for this Work.
- **Delivery evidence:** Final commit trailers identify this Work ID and `TERMINAL_DELIVERED`; resulting commit hash and push result are reported externally after delivery.
