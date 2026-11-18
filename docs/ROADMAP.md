# Synop — Roadmap

**Last updated:** 2026-11-18
**Sources:** [PRD.md](PRD.md), [DATABASE.md](DATABASE.md), [ARCHITECTURE.md](ARCHITECTURE.md). Build prompts: [BUILD-PROMPTS.md](BUILD-PROMPTS.md).

Tick items when they are finished **and tested**. Every phase that adds user-owned data ends with a passing cross-user isolation test for each new model.

---

## Phase 1 — Foundation (scaffold, auth, multi-user base)

**Planning docs**
- [x] PRD.md
- [x] DATABASE.md
- [x] ARCHITECTURE.md
- [x] ROADMAP.md and DECISIONS.md

**Scaffold**
- [x] Laravel app with the Livewire starter kit, MySQL and Pest
- [x] `APP_TIMEZONE=UTC`, Pint configured, `.env.example` complete, `.env` gitignored
- [x] Commands section of CLAUDE.md filled in
- [x] Register, log in and `php artisan test` all work

**Multi-user base**
- [x] `users` columns: `timezone` (Asia/Manila), `currency` (PHP), `safety_buffer_cents`, `payments_due_days` (7), `morning_summary_time`
- [x] Morph map registered with `Relation::enforceMorphMap()`
- [x] `BelongsToUser` trait: global scope (returns no rows when no one is logged in), auto-fills `user_id`
- [x] Reusable Pest isolation helper (user B can't list, view, edit, delete or link to A's records)
- [x] `Money` value object + `MoneyCast` + `money()` / `@money` helpers, with unit tests (parsing never uses floats)
- [x] User settings page (Settings → Preferences): timezone, currency, safety buffer, payments-due window

## Phase 2 — Tasks + Today view

- [x] `tasks` migration, model, factory, Policy (polymorphic `source`, unique per source)
- [x] Actions: create, update, complete, reopen, delete
- [ ] Tasks page: quick-add, filters (today / upcoming / overdue / done), edit, complete checkbox
- [ ] Today page as the home screen after login
- [ ] Today sections as separate Livewire components: Tasks (live), Personal Care, Vitamins, Classes, Payments Due (empty states)
- [x] Isolation tests for tasks

## Phase 3 — Finance

- [ ] `accounts` (cash / e-wallet / bank, opening balance, soft deletes)
- [ ] `transaction_categories` + default set created on registration
- [ ] `transactions` with types `income`, `expense`, `transfer`, `debt_in`, `debt_out` and the type rules from DATABASE §3.2
- [ ] Account balance and total balance (transfers don't change the total), with tests
- [ ] `contacts`
- [ ] One-off `debts` in both directions; principal creates a `debt_in`/`debt_out` transaction when an account is set
- [ ] `debt_payments`: each creates one transaction; overpayment rejected; settles at remaining = 0
- [ ] A debt with a due date creates a linked task; settling the debt completes it; its task can't be deleted directly
- [ ] Finance UI: overview (balance, owed to me, I owe, net), accounts, transactions with filters, debts grouped by contact
- [ ] Today "Payments Due": due within `payments_due_days` plus overdue
- [ ] Isolation tests for every Finance model

## Phase 4 — Schedule Engine

- [ ] Design written to DECISIONS.md and approved (stop gate)
- [ ] `schedule_rules` + `schedule_logs` migrations, models, Policies
- [ ] `ScheduleEngine` (pure PHP), tests written first: daily, every N days, weekly, monthly (day 31 → last day), start/end dates, paused rules
- [ ] `UpdateSchedule` Action: versioned rules (old rule ends yesterday, new one starts today)
- [ ] Recurring tasks
- [ ] Today view uses the engine; mark done / skip / un-tick writes `schedule_logs`
- [ ] Installment debts: rule + `installment_cents`, next due date, the linked task's due date moves forward
- [ ] Isolation tests for rules and logs

## Phase 5 — Personal Care

- [ ] `products` (skincare / makeup / vitamin), status, `expires_on` = earlier of printed expiry and opened + PAO
- [ ] "Expiring soon" list (≤ 14 days)
- [ ] Ingredient list (pasted text)
- [ ] `ingredient_tags` (+ starter tags on registration), pivot rebuilt when ingredients or keywords change
- [ ] `conflict_rules` (same routine / same day)
- [ ] One AM and one PM `routine` per user (created on registration), ordered `routine_steps`, each with a schedule
- [ ] Conflict warnings in Today's routine
- [ ] `vitamin_doses` with schedules; done decrements `units_left`
- [ ] Low-stock task (a new one only after restock and a later drop)
- [ ] Monthly spending per type; optional "record expense" transaction on purchase
- [ ] Today: Personal Care and Vitamins sections live
- [ ] Isolation tests for every Personal Care model

## Phase 6 — Education

- [ ] `semesters` (one active per user, archive)
- [ ] `courses`
- [ ] `class_sessions`, each with a schedule rule (defaults to the semester dates)
- [ ] `activities`; each creates and updates a linked task, which can't be deleted directly
- [ ] Today: Classes section live; archived semesters hidden
- [ ] Isolation tests for every Education model

## Phase 7 — Wants + AI

- [ ] `wants`: link fetch (Open Graph, short timeout, SSRF-safe) with manual entry and pasted-description fallback
- [ ] `SafeToSpend` service: balance − `i_owe` due before the chosen date (capped at remaining) − safety buffer, fully tested
- [ ] Ingredient check: conflict-rule matches and duplicates of owned products
- [ ] `AiClient` interface + Gemini implementation (API key in `.env` only)
- [ ] `ai_requests`: 10 real calls per user per local day
- [ ] Ingredient label scan (image sent once, never stored; the user reviews the text) for products and wants
- [ ] Want verdict (buy now / wait / skip + 2–3 reasons), reply validated, cached in `want_analyses` by `inputs_hash`
- [ ] Rules-based fallback when the AI fails or the limit is reached
- [ ] In-app notice when AI is used
- [ ] Marking a want bought can record an expense
- [ ] AI mocked in tests; isolation tests for wants, analyses and AI requests

## Phase 8 — PWA + reminders

- [ ] Web manifest, icons (192 / 512 / maskable), iOS meta tags
- [ ] Service worker: static asset cache + offline fallback page
- [ ] Install hint (Android prompt, iPhone Share → Add to Home Screen steps)
- [ ] Web push package, VAPID keys, `push_subscriptions`, subscribe/unsubscribe in settings
- [ ] Scheduled morning summary at each user's `morning_summary_time` in their own timezone
- [ ] Tested installing and receiving push on Android and iPhone (iOS 16.4+, installed app)

## Phase 9 — Security review

- [ ] Audit (stop gate): cross-user access on every model, route and Livewire action; mass assignment; validation; XSS; CSRF; rate limits on auth and AI; secrets; SSRF in the link fetcher
- [ ] Findings fixed after approval
- [ ] Every user-owned model confirmed to have `BelongsToUser`, a Policy and an isolation test

## Later (after MVP)

- [ ] Currency conversion when a user changes currency (exchange rate + convert every money column). Today changing currency only swaps the symbol (D-035).
