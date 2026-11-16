# CLAUDE.md

Instructions for the AI coding agent. Read this first in every session.

## Project

**Synop** is a multi-user personal productivity app (web first, installable on mobile as a PWA). Modules:
- **Tasks + Today view**: to-do list and a single "what's happening today" screen
- **Personal Care**: skincare, makeup and vitamin inventory, AM/PM routines with per-product schedules, spending
- **Finance**: accounts, current balance, debts both ways (owed to me / I owe), wants list with AI purchase analysis
- **Education**: semesters, courses, class schedule, activities and deadlines

Each user's data is private. No user can see or change another user's data.

## Docs (read the one relevant to your task, not all of them)

| File | Contents | Status |
|---|---|---|
| `docs/PRD.md` | Features, user stories, MVP scope | To be written (Prompt 1.1) |
| `docs/DATABASE.md` | Schema, ERD, conventions | To be written (Prompt 1.2) |
| `docs/ARCHITECTURE.md` | Stack, folders, security model, engines | To be written (Prompt 1.3) |
| `docs/ROADMAP.md` | Phases and progress checkboxes | To be written (Prompt 1.4) |
| `docs/DECISIONS.md` | Decision log | To be written (Prompt 1.4) |
| `docs/BUILD-PROMPTS.md` | The owner's prompt playbook | Exists. Do not edit unless asked. |

If a doc is marked "To be written", do not assume its contents. Ask.

## Stack

- Laravel (latest stable), PHP 8.3+
- Livewire starter kit, Tailwind CSS
- MySQL
- Pest for tests
- PWA for mobile

## Commands

<!-- Fill in after scaffolding (Prompt 2.1) -->
- Serve: `php artisan serve` + `npm run dev`
- Migrate: `php artisan migrate`
- Test: `php artisan test`
- Format: `./vendor/bin/pint`

## Hard rules

1. Every user-owned table has `user_id`. Every user-owned model uses the `BelongsToUser` trait and has a Policy.
2. Money is stored as integers in centavos. Never use floats for money.
3. Store timestamps in UTC; display them in the user's timezone (default Asia/Manila).
4. Business logic goes in `app/Services` or `app/Actions`, never inside Livewire components or controllers.
5. Every feature gets Pest tests, including a test that another user cannot access the data.
6. Never edit `.env`. Never run `migrate:fresh`, `db:wipe`, or delete files without asking.
7. Make small, focused changes. Don't refactor unrelated code.
8. API keys stay server-side only.

## Workflow

- For anything touching more than 2 files, propose a plan first and wait for approval.
- When a prompt says "stop and wait", stop. The owner controls when the next phase starts.
- At the end of a task, tick the finished items in `docs/ROADMAP.md` and log any decision in `docs/DECISIONS.md`.

## Learning mode

The owner is learning PHP and Laravel through this project. When you use a concept she's likely seeing for the first time (trait, global scope, cast, policy, service container, etc.), explain it in 1–2 sentences. No long tutorials.