# Multi-Agent Delivery and Continuation Workflow

This document is the shared workflow authority for Claude Code and OpenAI
Codex. `AGENTS.md` and `CLAUDE.md` are bootstrap surfaces; they must point
here rather than maintain conflicting delivery rules.

## Work state

Every implementation Work has one stable ID and a durable note under
`docs/work/`. Supported states are:

- `PLANNED`
- `ACTIVE`
- `BLOCKED`
- `READY_FOR_TERMINAL_REVIEW`
- `TERMINAL_DELIVERED`

The note records the objective, affected repositories, Human-authorized scope,
accepted decisions, constraints, current state, authorization status, owned
files, unrelated changes to preserve, decisions, validation, manual
acceptance, blockers, remaining work, and delivery evidence status.

Never put secrets, credentials, tokens, environment contents, machine-local
configuration, or transcript dumps in a Work note.

## Authorization boundary

Implementation authorization permits local implementation and validation only.
It does not permit commit, push, or terminal delivery.

After implementation is complete, update the Work note with validation,
manual-acceptance, ownership, and blocker evidence, set
`READY_FOR_TERMINAL_REVIEW`, report readiness, and stop.

Only separate, unambiguous Human authorization such as `TERMINAL: GO`,
`TERMINAL DELIVERY: GO`, `DELIVER`, or `COMMIT AND PUSH` permits terminal
delivery. Never infer it from successful implementation, validation, manual
acceptance, “continue”, or discussion moving to another issue.

## Fresh-context procedure

An agent continuing a Work must:

1. Read the repository-native bootstrap instructions.
2. Read this document and locate the relevant Work note.
3. Read only additional project guidance required for the Work.
4. Inspect `git status` and relevant diffs, including untracked files.
5. Reconcile durable state with the actual repository.
6. Separate task-owned changes from unrelated/pre-existing work.

If the Work note and repository materially disagree, stop and report the
inconsistency. Do not guess ownership or reconstruct state from conversation
history.

## Cross-repository Work

Use the same Work ID in each affected repository and maintain materially
equivalent Work-note state in both. Before delivery, both repositories must be
ready, agree, pass final validation, and have reviewed task-owned staging
sets. If either side is blocked or inconsistent, deliver neither side.

Commits and pushes remain separate per repository.

## Terminal delivery

After explicit terminal authorization, revalidate the complete worktree,
classify owned and unrelated changes, stage only owned paths or hunks, inspect
the staged diff, create one final Work commit per affected repository, and
push the existing authorized branch normally.

The final commit includes these trailers:

```text
Work-ID: <work-id>
Work-State: TERMINAL_DELIVERED
```

The commit must not contain its own hash. Report the resulting hash after
delivery or use another non-self-referential evidence surface.

Never use broad staging when unrelated work exists. Never force push, reset,
rebase, pull, merge, amend published history, bypass hooks, discard unrelated
changes, or switch branches merely to deliver. If normal delivery is
rejected, stop and report it.

Protect `.env`, `.env.*` except intentionally tracked templates such as
`.env.example`, credentials, secrets, machine-local configuration, Claude
local settings, and generated runtime state.
