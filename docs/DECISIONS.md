# Synop — Decision Log

Newest entries go at the bottom. Each entry gives the date, the decision, the reason and the alternatives rejected. To change a decision, add a new entry that points to the old one; don't edit old entries.

---

## Data and storage

### D-001 · Money as integer centavos
- **Date:** 2026-11-16 · **Source:** DATABASE §1.2
- **Decision:** Store money as signed `BIGINT` centavos in `*_cents` columns. The sign of a transaction comes from its `type`, never from a negative amount.
- **Reason:** Floats can't represent amounts like 0.10 exactly, so sums drift.
- **Rejected:** `FLOAT`; `DECIMAL(12,2)` (exact, but PHP still reads it as a string or float).

### D-002 · Two kinds of time
- **Date:** 2026-11-16 · **Source:** DATABASE §1.3
- **Decision:** Instants (`*_at`) are UTC `TIMESTAMP`s. Plans (`*_on` dates, `*_time` times) are stored as the user means them, in their timezone, with no conversion. "Today" = `now($user->timezone)`.
- **Reason:** A class at 08:00 or a due date of Nov 20 must not shift when converted to UTC.
- **Rejected:** Storing everything as UTC timestamps.

### D-003 · `user_id` on every user-owned table, including child tables
- **Date:** 2026-11-16 · **Source:** DATABASE §1.1
- **Decision:** Child rows (e.g. `routine_steps`, `schedule_logs`) also carry `user_id`, copied from the parent by the Action. The only exception is the `ingredient_tag_product` pivot.
- **Reason:** One global scope can then filter every query the same way.
- **Rejected:** Reaching ownership through the parent (easy to forget; needs joins).

### D-004 · Selective soft deletes
- **Date:** 2026-11-16 · **Source:** DATABASE §1.4
- **Decision:** Soft deletes only on `products`, `accounts`, `transactions`, `contacts`, `debts`, `debt_payments`, `routine_steps`, `vitamin_doses`. Archived or finished things use a status or `archived_at`.
- **Reason:** Protect money history and done/skipped history; keep everything else simple.
- **Rejected:** Soft deletes everywhere; no soft deletes.

### D-005 · Enums as VARCHAR + PHP backed enums
- **Date:** 2026-11-16 · **Source:** DATABASE §1.5
- **Decision:** `VARCHAR(20)` columns cast to enums in `app/Enums`.
- **Reason:** Adding a value needs no migration.
- **Rejected:** MySQL `ENUM`.

### D-006 · Morph map for polymorphic relations
- **Date:** 2026-11-16 · **Source:** DATABASE §1.5
- **Decision:** Short aliases (`task`, `debt`…) via `Relation::enforceMorphMap()`. Child rows are cleaned up in the parent's `deleting` event or Action, and tests cover it.
- **Reason:** Renaming a class won't break stored data; there are no FKs on morph columns.
- **Rejected:** Storing full class names.

## Finance

### D-007 · Debts use their own transaction types (Q1)
- **Date:** 2026-11-16 · **Source:** DATABASE §3.2, §5
- **Decision:** Lending, borrowing and repayments use `debt_out` / `debt_in`. They change balances, but reports ignore them.
- **Reason:** The money is expected back, so it isn't spending or income.
- **Rejected:** Recording debts as `expense` / `income`; not recording them at all.

### D-008 · Safe-to-spend without scheduled expenses (Q2)
- **Date:** 2026-11-16 · **Source:** DATABASE §5 (changes PRD §2.5)
- **Decision:** Safe-to-spend = total balance − `i_owe` amounts due before the chosen date (one-off + installments, capped at remaining) − safety buffer.
- **Reason:** No recurring transactions exist in the MVP, so there are no scheduled expenses to subtract.
- **Rejected:** Asking users to enter scheduled expenses by hand.

### D-009 · "Payments Due" window per user (Q4)
- **Date:** 2026-11-16 · **Source:** DATABASE §3.1
- **Decision:** Show debts due within `users.payments_due_days` (default 7) plus overdue.
- **Reason:** A sensible default that users can adjust.
- **Rejected:** A fixed window.

### D-010 · Installment debts in the MVP (Q5)
- **Date:** 2026-11-16 · **Source:** DATABASE §3.4
- **Decision:** A debt is either one-off (`due_on`) or installment (`installment_cents` + a schedule rule). The next due date is computed from payments so far. One linked task per debt, and its due date moves forward.
- **Reason:** Installments are common; computing from payments handles partial, late and extra payments.
- **Rejected:** Storing one row per installment; leaving installments for later.

### D-011 · Debt "remaining" is computed, not stored
- **Date:** 2026-11-16 · **Source:** DATABASE §3.4
- **Decision:** Remaining = principal − sum of payments.
- **Reason:** A stored copy could drift from the payments.
- **Rejected:** A `remaining_cents` column.

## Tasks and schedules

### D-012 · One auto-task per source; deletion rules (Q6)
- **Date:** 2026-11-16 · **Source:** DATABASE §3.3
- **Decision:** Unique (`source_type`, `source_id`). Tasks from activities and debts can't be deleted directly (delete the source instead). Low-stock tasks can be deleted.
- **Reason:** A deleted deadline task would silently drop a real obligation.
- **Rejected:** Allowing every deletion; blocking every deletion.

### D-013 · Versioned schedule rules (Q12)
- **Date:** 2026-11-16 · **Source:** DATABASE §3.2
- **Decision:** Editing a rule that started before today ends it yesterday and creates a new rule from today. Changes go only through the `UpdateSchedule` Action.
- **Reason:** Old done/skipped logs keep pointing at the rule they were logged against.
- **Rejected:** Editing rules in place (rewrites history).

### D-014 · Monthly on day 29–31 falls on the last day (Q3)
- **Date:** 2026-11-16 · **Source:** DATABASE §3.2
- **Decision:** Day 31 → Nov 30, Feb 28/29.
- **Reason:** Users expect "end of month", not a skipped month.
- **Rejected:** Skipping the month; rolling into the next month.

### D-015 · Schedule Engine is pure PHP
- **Date:** 2026-11-16 · **Source:** ARCHITECTURE §5
- **Decision:** `ScheduleEngine` doesn't touch the database. It answers `occursOn` and `occurrencesBetween`. Callers pick the rule version and merge in logs. The Today view loads rules in a few queries and evaluates them in memory.
- **Reason:** Easy to unit-test; one engine for all modules (PRD §2.7).
- **Rejected:** SQL-based recurrence; storing generated occurrences.

## Personal Care

### D-016 · Expiry = earlier of printed date and opened + PAO (Q7)
- **Date:** 2026-11-16 · **Source:** DATABASE §3.5
- **Decision:** Add `printed_expiry_on`; `expires_on` is recomputed on save. "Expiring soon" = within 14 days (PRD).
- **Reason:** Unopened products still expire.
- **Rejected:** PAO only.

### D-017 · Exactly one AM and one PM routine (Q8)
- **Date:** 2026-11-16 · **Source:** DATABASE §3.5
- **Decision:** Both are created on registration. Per-step schedules handle "retinol nights".
- **Reason:** Simpler UI and Today view.
- **Rejected:** Several named routines per period.

### D-018 · User-defined ingredient tags; starter tags but no starter rules (Q9)
- **Date:** 2026-11-16 · **Source:** DATABASE §3.1, §3.5
- **Decision:** Tags group keywords (AHA = glycolic, lactic…). Editable starter tags are created on registration. Users write all conflict rules themselves.
- **Reason:** Rules can say "AHA" instead of listing acids, and the app gives no skincare advice (PRD §6).
- **Rejected:** A built-in ingredient database; pre-made conflict rules.

### D-019 · Soft-delete routine steps and vitamin doses (Q10)
- **Date:** 2026-11-16 · **Source:** DATABASE §1.4
- **Decision:** Removed steps and doses keep their rules and logs.
- **Reason:** For future routine-consistency charts.
- **Rejected:** Hard delete.

### D-020 · Label images are never stored (Q11)
- **Date:** 2026-11-16 · **Source:** DATABASE §3.5
- **Decision:** Images are sent to the AI once and discarded; only the reviewed text is saved.
- **Reason:** Privacy and storage.
- **Rejected:** Keeping images for re-scanning.

## Architecture

### D-021 · Business logic in Actions and Services
- **Date:** 2026-11-16 · **Source:** ARCHITECTURE §2–3
- **Decision:** Livewire components only validate, call, and render. Actions take plain inputs (a `User` + data) and wrap multi-row writes in `DB::transaction()`.
- **Reason:** A future mobile API can reuse the same logic.
- **Rejected:** Logic in Livewire components or models.

### D-022 · Livewire component style
- **Date:** 2026-11-16 · **Source:** ARCHITECTURE (resolved points)
- **Decision:** Use whatever the starter kit generates (class-based or single-file), and keep components thin.
- **Reason:** Don't fight the framework's defaults.
- **Rejected:** Forcing one style before seeing the kit.

### D-023 · Four-layer data isolation
- **Date:** 2026-11-16 · **Source:** ARCHITECTURE §4
- **Decision:** Global scope + route model binding (another user's record → 404) + Policies + validation scoped by `user_id`. Livewire ID properties are `#[Locked]`. Every module has isolation tests.
- **Reason:** One mistake shouldn't leak data, and users can't even tell other records exist (PRD §5).
- **Rejected:** Policies only; returning 403.

### D-024 · Global scope fails closed without a logged-in user
- **Date:** 2026-11-16 · **Source:** ARCHITECTURE §4
- **Decision:** No logged-in user → queries return no rows. Jobs opt out explicitly and filter by user id.
- **Reason:** A forgotten filter in a job returns nothing instead of everyone's data.
- **Rejected:** No filter when logged out (fails open).

### D-025 · Money value object and string parsing
- **Date:** 2026-11-16 · **Source:** ARCHITECTURE §6
- **Decision:** `Money` (immutable int centavos) + `MoneyCast`. Input is parsed from strings with integer maths. Livewire form fields hold strings.
- **Reason:** Floats never appear anywhere in the money path.
- **Rejected:** Bare integers everywhere; `(float)` parsing.

## AI

### D-026 · Free-tier Gemini behind an interface
- **Date:** 2026-11-16 · **Source:** PRD (resolved), ARCHITECTURE §7
- **Decision:** `AiClient` interface with a `GeminiClient` implementation, called server-side only, key in `.env`.
- **Reason:** Free to start; changing providers later means changing one binding.
- **Rejected:** Paid provider now; calling the AI from the browser.

### D-027 · PHP computes money; AI only explains
- **Date:** 2026-11-16 · **Source:** PRD §2.5, ARCHITECTURE §7
- **Decision:** The AI gets finished figures and ingredient flags and returns a verdict + 2–3 reasons. The reply is validated; anything invalid falls back to the rules-based verdict.
- **Reason:** LLMs make arithmetic mistakes; money figures must be exact and testable.
- **Rejected:** Letting the AI calculate.

### D-028 · AI limit and cache
- **Date:** 2026-11-16 · **Source:** PRD (resolved), DATABASE §3.7
- **Decision:** 10 real calls per user per local day, counted in `ai_requests` (no content stored). Verdicts are cached in `want_analyses` by `inputs_hash`; cache hits are free.
- **Reason:** Protects the shared free quota.
- **Rejected:** No limit; a global limit only.

### D-029 · Minimal data to the AI
- **Date:** 2026-11-16 · **Source:** PRD §5
- **Decision:** Send only product info, ingredients and computed figures; never names, contacts or account details. Users see a notice when AI is used.
- **Reason:** Free tiers may use requests for training.
- **Rejected:** Sending full context.

## PWA

### D-030 · PWA with an offline fallback page only; web push reminders
- **Date:** 2026-11-16 · **Source:** PRD (resolved), ARCHITECTURE §8
- **Decision:** Manifest + service worker caching static assets + offline page. The morning summary is sent by web push at each user's local time.
- **Reason:** Installable with no app store; offline sync is out of scope.
- **Rejected:** Native apps; offline editing; email-only reminders.

### D-031 · Actions for every write, including tasks
- **Date:** 2026-11-16 · **Source:** Owner review of BUILD-PROMPTS Prompt 3.1 (confirms D-021)
- **Decision:** One rule for the whole app: anything that changes data is an Action (`CreateTask`, `CompleteTask`, `RecordDebtPayment`…); anything that answers a question or talks to an outside system is a Service (`ScheduleEngine`, `SafeToSpend`, `AiClient`). Tasks get Actions, not a `TaskService`. When the playbook names a `*Service` for writes (e.g. Prompt 3.1 `TaskService`, Prompt 4.1 `FinanceService`), build Actions instead. A Service is still used for read-only calculations such as balances.
- **Reason:** A single rule with no exceptions; small one-purpose classes that are easy to test; module services (e.g. `FinanceService`) would grow into very large files.
- **Rejected:** `TaskService` for tasks only (makes tasks a one-off exception); one Service per module (large files that mix reads and writes).

### D-032 · Extend starter-kit tables with new migrations; use the starter kit's tooling
- **Date:** 2026-11-18 · **Source:** Scaffold (Laravel 13, Livewire starter kit)
- **Decision:** Columns added to starter-kit tables (first: `users.timezone`, `users.currency`) go in new migrations; the starter kit's own migrations are never edited. `config/app.php` reads `APP_TIMEZONE` (default `UTC`). Formatting uses the kit's `composer lint` script and its `pint.json` (`laravel` preset) instead of a custom setup.
- **Reason:** Starter-kit files stay comparable with upstream, and each schema change is a separate, reversible step.
- **Rejected:** Editing `create_users_table`; a separate `composer format` script that duplicates `composer lint`.

### D-033 · BelongsToUser and Money details
- **Date:** 2026-11-18 · **Source:** BUILD-PROMPTS Phase 1 (BelongsToUser, Money cast, isolation helper)
- **Decision:** Creating a user-owned record with no one logged in and no `user_id` throws a `LogicException`. Jobs either set `user_id` themselves or read through `Model::forUser($user)`, the only opt-out of the owner scope. `MoneyCast` accepts only `Money`, integers or null and rejects strings and floats, so input must go through `Money::parse()`. The trait is tested with a test-only `Tests\Support\OwnedThing` model and table until real models exist; `tests/Support/OwnedThing.php` is in PHPStan's paths so the trait is analysed. `composer types:check` runs PHPStan with `--memory-limit=1G` (it ran out of memory at PHP's default 128M).
- **Reason:** Fail loudly instead of saving an orphan row; one obvious, searchable way to bypass the scope; floats can't slip into money columns.
- **Rejected:** Silently skipping the scope in console; a `withoutOwnerScope()` with no user filter; a real migration just for tests.

### D-034 · Review fixes before Phase 2
- **Date:** 2026-11-18 · **Source:** Owner review of Phase 1 work
- **Decision:**
  - `BelongsToUser` also blocks creating a record for another user while logged in, and blocks changing `user_id` at any time. Tests create user B's data while acting as B.
  - `Money` becomes its centavos integer in JSON (`{"amount_cents": 12345}`).
  - `Money::parse` allows spaces only around the amount and after `₱`; `"12 34"` is rejected.
  - Morph map is enforced from now on (`'user' => User::class`); every polymorphic model is added to it.
  - CI starts a MySQL service, so migrations are checked on MySQL; tests still run on SQLite.
  - `.gitignore` no longer ignores `CLAUDE.md` or `.claude/` (only `.claude/settings.local.json`), so project subagents are committed.
  - The rest of Phase 1 (3 user columns + settings page) is a separate step before Phase 2.
- **Reason:** Catch our own coding mistakes early (ownership, silent data loss, typos in amounts) and keep CI green on the first push.
- **Rejected:** Formatted text in JSON (needs parsing back); spaces as thousands separators; SQLite-only CI.

### D-035 · Per-user currency is a display symbol; Preferences tab
- **Date:** 2026-11-18 · **Source:** Owner choices while finishing Phase 1
- **Decision:** Users pick one currency from PHP, USD, EUR, GBP, SGD, AUD, CAD, HKD (`App\Enums\Currency`). It only changes the symbol `money()` / `@money` show; stored amounts are never converted (₱500 → $500), and the page says so. Only 2-decimal currencies, because `Money` stores hundredths. Timezone, currency, safety buffer and payments-due window (1–30 days) live on a new Settings → Preferences tab, saved by the `UpdatePreferences` Action. `User::$attributes` repeats the column defaults so new User objects have them before being reloaded.
- **Reason:** Real choice for users abroad without the risks of conversion (exchange rates, rounding, rewriting history). A separate tab keeps the starter kit's Profile page untouched (D-032).
- **Rejected:** PHP locked; conversion now (moved to the roadmap's Later list); every ISO currency; adding fields to the Profile page.

### D-036 · Task Actions check the Policy themselves; 404 for other users' tasks
- **Date:** 2026-11-18 · **Source:** BUILD-PROMPTS Prompt 3.1 (Tasks backend)
- **Decision:** Built as five Actions (`CreateTask`, `UpdateTask`, `CompleteTask`, `ReopenTask`, `DeleteTask`) per D-031, not a `TaskService`. Each Action (except create) first calls `Gate::forUser($user)->authorize(...)`, so a future screen or API that forgets `$this->authorize()` is still safe. `TaskPolicy` answers another user's task with `Response::denyAsNotFound()` (404, rule 11); a task whose source is an activity or debt gets a normal "not allowed" on delete (D-012). `CreateTask` takes the source as a model (passed by other modules' Actions, never chosen by the user) and refuses a source owned by another user or one that already has a task. `UpdateTask` never changes the source or completion; completing twice keeps the first time.
- **Reason:** Ownership and the delete rule live in one place and hold for every caller.
- **Rejected:** Checking only in Livewire components (easy to forget in a new caller); 403 for other users' tasks (reveals the record exists).

### D-037 · Tasks page: a 5th "No date" tab; quick-add follows the open tab
- **Date:** 2026-11-18 · **Source:** Owner choices for BUILD-PROMPTS Prompt 3.2 (Tasks UI)
- **Decision:** The Tasks page has five tabs: Today, Upcoming, Overdue, **No date**, Done (adds one to the four filters in PRD §2.1), so undated tasks have a home. Quick-add takes a title only; on the Today tab the task is due today, on every other tab it has no date, and the toast says which tab it went to. Tab contents and order come from the read-only `TaskList` Service (`app/Services/TaskList.php`), with "today" in the user's timezone; the page only calls it and the Task Actions (D-031, not `TaskService`). The open tab is kept in the URL (`?filter=`). Done shows the latest 100.
- **Reason:** Every task is always visible in exactly one tab; quick-add does the obvious thing on Today without surprising dates elsewhere.
- **Rejected:** Undated tasks at the bottom of Upcoming; an "All open" tab; a date picker in the quick-add row.

### D-038 · Today at /today; ticked tasks stay until tomorrow; history later
- **Date:** 2026-11-18 · **Source:** Owner choices for BUILD-PROMPTS Prompt 3.3 (Today view)
- **Decision:** Today lives at `/today` (route `today`) and is Fortify's `home`, so login lands there; `/dashboard` redirects to it and the starter kit's placeholder dashboard was removed. The page only lays out five sections, each its own Livewire component in `resources/views/components/today/` (`tasks`, `personal-care`, `vitamins`, `classes`, `payments-due`) sharing the `<x-today.section>` card; later modules fill in their own file. The Tasks section shows tasks due today or earlier that are open or were completed today (user's timezone), so ticked tasks stay crossed out until tomorrow, with "X of Y done". Undated tasks finished today don't appear. A history / weekly summary was requested and postponed to the roadmap's Later list.
- **Reason:** The address matches the name; visible progress during the day, and a mistaken tick can be undone in place.
- **Rejected:** Keeping `/dashboard`; ticked tasks disappearing at once; building a history card or page now.

### D-039 · Accounts and transactions: balances Service, delete only at zero, required categories
- **Date:** 2026-11-18 · **Source:** BUILD-PROMPTS Prompt 4.1 (accounts and transactions)
- **Decision:** Writes are Actions in `app/Actions/Finance` (D-031). Balances come from the read-only `AccountBalances` Service (two grouped SQL queries, deleted transactions excluded, total over active accounts), not a `FinanceService`. An account can be deleted (soft) only when its balance is zero: "Move the ₱1,200.00 to another account first." Opening balance is ₱0 or more. Income and expense need a category of the matching kind; starter set on registration (expense: Food, Transport, School, Bills, Personal Care, Health, Shopping, Entertainment, Other; income: Allowance, Salary, Gift, Other), backfilled for existing users by a migration with its own frozen copy of the list. Users record only income, expense and transfer; `debt_in`/`debt_out` come from the debt Actions. Transactions with a `source` can't be edited, deleted or restored directly.
- **Reason:** The total never drops silently; reports stay clean ("Other" when unsure); a debt and its transactions can't drift apart.
- **Rejected:** One `FinanceService` for reads and writes; deleting accounts that still hold money; optional categories.

### D-040 · Debts: one bookkeeping helper, force-delete contacts with name confirmation
- **Date:** 2026-11-18 · **Source:** BUILD-PROMPTS Prompt 4.2 (debts both ways); owner choices
- **Decision:** Debt Actions in `app/Actions/Finance` run in one `DB::transaction()`; `DebtBookkeeping` is the only code that keeps a debt's principal transaction, status and linked task in step. Principal: owed_to_me → `debt_out`, i_owe → `debt_in`; payments the opposite. A payment can't exceed what remains (debt row locked while checking); remaining 0 → `settled` and task completed; deleting a payment reopens it. Task title shows what remains ("Collect ₱700.00 from Ben" / "Pay Ana ₱500.00"); no due date → no task. With payments, a debt's direction is locked and its principal can't go below what's paid. Deleting a debt hides it with its payments and their transactions (same `deleted_at`), and restore brings back exactly that set. A contact with open debts can't be deleted unless the user types the contact's exact name; then the contact and all their debts are hidden as if they never existed, and `RestoreContact` brings them back. Without open debts, only the contact is hidden and settled history stays. Installments wait for Phase 4 (`installment_cents` column exists, unused).
- **Reason:** A debt and its money can never drift apart; a deliberate full delete is possible but never by accident, and never permanent.
- **Rejected:** Blocking contact deletion outright; permanently erasing a force-deleted contact; storing `remaining_cents`.

### D-041 · Finance pages; debt tasks pay through "Record payment"; Payments Due on Today
- **Date:** 2026-11-18 · **Source:** Owner choices for BUILD-PROMPTS Prompt 4.3 (Finance UI)
- **Decision:** One "Finance" sidebar link with four tabs, each its own URL: Overview (`/finance`), Accounts, Transactions, Debts. Overview shows total balance, owed to me, I owe, and **Net = balance + owed to me − I owe**. Transactions show one month at a time (default: this month in the user's timezone), filterable by account (incl. transfers in), type and category, 50 per page; debt transactions show "From debt" and open no edit window. Debts are grouped by contact with each contact's net; settled ones behind "Show settled". One shared `finance.record-payment` component (opened with `$dispatch('record-payment', { debtId })`) is used by the Debts tab, Overview, Today and the Tasks page. Ticking a debt's task opens it instead of completing the task; `TaskPolicy` refuses to complete or reopen a debt task by hand, so a task can't disagree with its debt. Debt tasks are left out of Today's Tasks section, because Today's Payments Due lists open debts due within `payments_due_days` or overdue. Read logic lives in the `DebtSummary` and `TransactionList` Services. Restore screens for deleted records are left for later.
- **Reason:** A short sidebar (and future bottom bar); one place to pay a debt; no duplicates on Today.
- **Rejected:** Four sidebar links; ticking a debt task without paying; showing debts twice on Today.

### D-042 · Schedule Engine design (Phase 4)
- **Date:** 2026-11-18 (approved by the owner the same day) · **Source:** BUILD-PROMPTS Prompt 5.1; ARCHITECTURE §5; DATABASE §3.2; D-010, D-013, D-014, D-015
- **Decision:**
  1. **Shape.** `ScheduleRule` model (`schedule_rules`, DATABASE §3.2 unchanged): `BelongsToUser`, Policy, `schedulable(): MorphTo`, `logs(): HasMany`; casts `frequency` → `Frequency` enum (`daily`, `every_n_days`, `weekly`, `monthly`), `weekdays` → array, `starts_on`/`ends_on` → date, `paused_at` → datetime. `ScheduleLog` model (`schedule_logs`): status → `ScheduleLogStatus` enum (`done`, `skipped`). Morph aliases for schedulables as each module arrives (`task` now; `debt`; later `routine_step`, `vitamin_dose`, `class_session`). The recurrence maths is **not** on the model: `App\Services\ScheduleEngine` (pure PHP, no queries) takes a rule and **local dates as `Y-m-d` strings**, and returns `Y-m-d` strings (D-015). The playbook's `occursOn(Carbon $date)` on the model becomes `ScheduleEngine::occursOn($rule, '2026-11-20')`.
  2. **`occursOn(rule, D)`**, in order: paused → no; D < `starts_on` or D > `ends_on` → no; then
     - `daily` → yes;
     - `every_n_days` → `daysBetween(starts_on, D) % interval === 0` (so `starts_on` is always an occurrence);
     - `weekly` → ISO weekday of D (Mon = 1 … Sun = 7) is in `weekdays`;
     - `monthly` → day of D = `min(day_of_month, days in D's month)` (31 → Nov 30, Feb 28/29; D-014).
  3. **Date maths without time zones.** Dates are parsed as calendar dates (`CarbonImmutable::createFromFormat('!Y-m-d', $d, 'UTC')`) and compared as strings; day counts use these UTC-midnight dates. So a daylight-saving change in a user's timezone can never make a day count 0.96 or skip a date. "Today" is always `now($user->timezone)->toDateString()` (rule 9); nothing is converted.
  4. **`occurrencesBetween(rule, from, to)`**: clamps to the rule's own range, then checks each day; refuses ranges over 366 days (callers use one day for Today, a few weeks for "upcoming"). **`nextOnOrAfter(rule, D)`**: first occurrence ≥ D, or null after `ends_on`; for weekly/daily/every-N this is at most `interval` or 7 days away, for monthly at most 31.
  5. **Everything due on a date, for one user** (Today): (a) one query for active rules covering D (`user_id`, `paused_at IS NULL`, `starts_on <= D`, `ends_on IS NULL OR ends_on >= D`), with their `schedulable` eager-loaded per type (one query per type); (b) one query for that user's `schedule_logs` on D, keyed by `schedule_rule_id`; (c) the engine filters in memory. About 2 + (number of item types) queries regardless of how many items, using the existing indexes `(user_id, paused_at)` and `(user_id, occurs_on)`. Items whose schedulable was deleted are skipped.
  6. **Missed occurrences don't carry over** (owner's choice): Today shows only today's occurrence; a past occurrence with no log simply counts as missed in history. Exception by design: installment debts, whose next due date comes from payments made (D-010), so an unpaid installment stays due until paid.
  7. **Logs.** One row per (rule, date) (unique index). Done/skip creates or updates it, un-tick deletes it. Refused when the rule doesn't occur on that date, or the date is in the future (the user's today or earlier only). `logged_at` is the UTC instant. `user_id` copied from the rule (rule 10).
  8. **Versioning (`UpdateSchedule`, D-013).** If the current rule started before today: set its `ends_on` = yesterday and create a new rule. The new rule starts today. Rules that start today or later are edited in place. Changes that can't alter any past occurrence are also done in place: pause/unpause, and moving `ends_on` to a date ≥ yesterday. A started rule's `starts_on` can't move. *(Refined while implementing, 2026-11-18: an `every_n_days` rule keeping the same interval can only change its end date, which is always done in place, so its cycle never shifts and no special "continue the cycle" step is needed. Changing the interval starts the new cycle today.)*
  9. **Pause.** `paused_at` set → no occurrences at all while paused (any date); past logs stay, so history is kept. Unpausing resumes the same rule (no new version).
  10. **Validation** (the Action, not the engine): exactly the field for the frequency is filled; `interval` 2–365 (1 would be daily); `weekdays` non-empty, unique, 1–7; `day_of_month` 1–31; `ends_on >= starts_on`; no overlap with the item's other rules; `schedulable` must belong to the same user.
  11. **Tests first (Prompt 5.2)**: each frequency; first/last day of the range; `ends_on` inclusive; paused; day 29/30/31 in Feb (leap and non-leap), Apr, Nov; every-N counted from `starts_on` across a month and a year boundary; weekly with Sunday (7); a date in a DST-switching timezone (e.g. America/New_York, 2026-03-08 and 2026-11-01); 366-day range limit; logs on non-occurring and future dates refused; versioning keeps the every-N cycle; isolation for rules and logs.
- **Reason:** Keeps the engine tiny, deterministic and testable without a database; Today stays a fixed handful of queries; editing a schedule never rewrites history or shifts a cycle unexpectedly.
- **Rejected:** Recurrence maths on the model or in SQL; storing generated occurrences; converting dates to UTC; carrying missed occurrences over to Today; resetting every-N cycles on every edit.

### D-043 · Recurring tasks; repeating items on Today with done/skip
- **Date:** 2026-11-18 · **Source:** Owner choices for BUILD-PROMPTS Prompt 5.3
- **Decision:** A task repeats when it has a schedule rule that hasn't ended; it then ignores `due_on` (cleared on save) but keeps `due_time`. It appears on the Tasks page's **Today** tab on its scheduled days and on **Upcoming** with its next date (paused ones listed last, marked "Paused"); never on Overdue, No date or Done. The edit window's Repeat section is saved with the task by `SaveTaskRepeat` in one transaction (create / version / pause / resume / stop); "Doesn't repeat" calls `StopSchedule` (ends yesterday if started, else deleted) and the task becomes a one-off again. Debt tasks can't repeat. Ticking a repeating task marks **today's occurrence** done (`MarkOccurrence`), un-ticking removes it, and a Skip button logs `skipped` with Undo; `TaskPolicy` refuses completing a repeating task for good. Today's "X of Y done" counts one-offs and today's repeats, leaving skipped ones out. The generic `ScheduledItems` Service returns any item type's occurrences on a date in a fixed number of queries, for later modules to reuse.
- **Reason:** Repeats behave like "today's chores" without piling up, while one-off tasks keep their simple done/overdue rules.
- **Rejected:** A separate "Repeating" tab; showing repeats only on Today; completing a repeating task for good.

### D-044 · Installment debts: worked-out end date, next due from payments
- **Date:** 2026-11-18 · **Source:** Owner choices for the Phase 4 "Installment debts" step; DATABASE §3.4; D-010
- **Decision:** A debt is paid back in **one payment** (optional `due_on`) or **installments** (`installment_cents` + a schedule rule on the debt; `due_on` empty). The user enters the amount per payment, how often (monthly on a day, certain weekdays, every N days) and the first due date. The **number of payments** (principal ÷ installment, rounded up; at most 120) **and the last date are worked out** and shown live before saving; the rule ends on that last date. The read-only `InstallmentPlan` Service gives the **next due date** — the first payment date whose running total (number × installment) is more than what's been paid, counted across all schedule versions — and the **amount due** on it: what's needed to catch up to that date, capped at what remains (if every date has passed, everything left is due on the last one). Missed installments stay due (unlike chores, D-042). The debt's task follows the next due date and amount and is completed when settled; Payments Due and Overview list installment debts by their next date ("payment 2 of 6"); Record payment is pre-filled with the amount due now. Changing a plan: not started yet → schedule edited in place; running with the same pattern → only its end date moves; a new pattern → old version ends yesterday and a new one starts today (D-013), with the end chosen so the total number of dates stays right. Switching back to one payment removes the debt's schedules (`RemoveSchedules`): a debt's history is its payments, not schedule logs. Simplification: past dates are always counted at the current installment amount.
- **Reason:** Users think in "₱500 a month", not end dates; computing from payments handles partial, extra and late payments without storing a row per installment.
- **Rejected:** The user choosing the end date; one stored row per installment; letting missed installments drop off.

### D-045 · Products: expense on purchase, "was it a mistake?" on delete, spending per type
- **Date:** 2026-11-18 · **Source:** Owner choices for BUILD-PROMPTS Prompt 6.1 (product inventory)
- **Decision:** Personal Care is one sidebar link with tabs (Products, Spending; Routines and Vitamins join later), like Finance. Products are saved by `CreateProduct` / `UpdateProduct`, which recompute `expires_on` (D-016; month ends don't overflow: Jan 31 + 1 month → Feb 28). "Record as an expense" creates an expense transaction with `source = product` (account chosen, category defaulting to "Personal Care") and keeps it in step on edit; unticking removes it; Finance shows it "From product" and it changes only through the product. **Deleting a product with an expense asks the user at that moment**: "Was this entry a mistake? Should the ₱650 go back to GCash?" — yes hides the expense with the product (restore brings both back); no keeps it as a normal, editable expense no longer tied to the product. "Expiring soon" = unopened or in-use products expiring within 14 days or already expired. Monthly spending is per **type** (skincare / makeup / vitamins) for the last 6 months, following DATABASE §3.5 and the roadmap where PRD §2.3 says "category"; a product counts in the month it was bought at the price entered, which is what was paid for that entry (2 × ₱650 = ₱1,300). Vitamin stock fields are stored now; the low-stock task comes with Prompt 6.3.
- **Reason:** Deleting usually means "wrong entry", but sometimes the money really was spent; asking at the moment avoids guessing.
- **Rejected:** Always refunding or always keeping the expense on delete; price × quantity; spending per free-text category.

### D-046 · Routines and conflict rules between ingredient groups
- **Date:** 2026-11-18 · **Source:** BUILD-PROMPTS Prompt 6.2; DATABASE §3.5; D-017, D-018, D-019; owner choice
- **Decision:** Conflict rules are between **ingredient groups (tags)**, as DATABASE §3.5 and D-018 define, not between single products as the playbook prompt says: "AHA — not with Retinoid in the same routine" covers every product whose ingredients match. A product joins a group when any of the group's keywords appears in its ingredient list (ignoring case); the links are rebuilt when a product's ingredients or a group's keywords change. Every user gets one AM ("Morning") and one PM ("Night") routine and six editable starter groups (AHA, BHA, Retinoid, Vitamin C, Niacinamide, Benzoyl peroxide) on registration, backfilled for existing users; no ready-made rules. Routine steps use the user's own **skincare or makeup** products (vitamins get their own doses in 6.3); each step has its own schedule (daily by default, changed through the schedule Actions with versioning); removing a step is a soft delete that keeps its schedule and history. The read-only `ConflictChecker` reports a rule broken when, among the steps scheduled that day, one step's product has the first group and a **different** step's product the second (same routine = same AM/PM routine; same day = anywhere that day); one product containing both groups doesn't count on its own. Warnings show on **Today** (Personal Care section, now live with tick/skip) and, by the owner's choice, **right after saving a step**: the next 4 weeks are checked and the clash is listed with its weekdays and the user's note, without blocking the save.
- **Reason:** One rule per pair of ingredient groups instead of one per pair of products; rules stay the user's own notes, never advice (PRD §6).
- **Rejected:** Rules between single products; blocking a clashing save; ready-made rules; counting a single product that contains both groups.

### D-047 · Vitamin doses, stock from doses, low-stock reminder on crossing the level
- **Date:** 2026-11-18 · **Source:** BUILD-PROMPTS Prompt 6.3; DATABASE §3.2, §3.3, §3.5; D-019
- **Decision:** `vitamin_doses` as in DATABASE §3.5 (one row per dose time, units, meal note, soft deletes), each with its own schedule (daily by default; changed through the schedule Actions with versioning). `TakeVitaminDose` marks today's dose done / skipped / clears it in one transaction and keeps `units_left` in step: becoming done takes the dose's units off (never below 0), stopping being done (untick, or done → skipped) puts them back, skipping alone changes nothing, and untracked stock (`units_left` empty) is left alone. `RestockVitamin` adds tablets. The **low-stock reminder** ("Restock …", due today, `source = product`) is created — or the product's one task reopened — only when stock **crosses** from above the "remind me at" number to at/below it (from a dose, an untick, a restock or an edit of tablets left or the level); staying low creates nothing new, and going back above the level ticks an open reminder off. A vitamin added already at its level gets the reminder at once. Deleting the reminder is allowed (DATABASE §3.3); a new one only comes after a restock and a later drop. Personal Care gets a Vitamins tab; Today's Vitamins section is live (time order, tick/skip, tablets left).
- **Reason:** "A new one only after restock and a later drop" (DATABASE §3.3) falls out naturally from reacting to crossings, with the existing one-task-per-source rule.
- **Rejected:** A reminder on every dose while low; storing a "reminded" flag on the product; letting stock go negative.

### D-048 · School: active = where School opens; Today shows every non-archived semester
- **Date:** 2026-11-18 · **Source:** Owner choices for BUILD-PROMPTS Prompt 7.1; DATABASE §3.6; D-012
- **Decision:** One "School" sidebar link with tabs (Courses, Activities, Semesters) and a semester picker. Exactly one semester is **active** (the user's first becomes active by itself; setting another turns the rest off in one transaction); "active" only decides which semester School opens on. **Today → Classes shows classes from every semester that isn't archived** (owner's choice), on the days their schedule falls on, in time order, with the current class marked "Now"; archiving hides a semester's classes and turns "active" off, and an archived semester can't be made active. A class time is one session with its own weekly schedule over the semester's dates; changing its days goes through UpdateSchedule (versioned), and changing the semester's dates moves its class schedules as far as the past allows (a schedule that hasn't started moves completely; a running one only gets its new end date, not before yesterday). Each activity creates one task titled "Quiz: Chapter 3 quiz (CS 101)", due with it; edits (and course renames) update the task, the done state lives on the task, and the task can't be deleted directly (D-012) — deleting the activity removes it. Deleting a course or semester removes its class times, their schedules, activities and their tasks (polymorphic cleanup, DATABASE §1.5). No attendance in the MVP.
- **Reason:** Short summer or bridging terms run alongside the main one; archiving is the one clear way to hide a term.
- **Rejected:** Only the active semester on Today; one page per course; soft-deleting school records.

### D-049 · Wants: safe link fetch, pasted-description fallback, bought → expense
- **Date:** 2026-11-18 · **Source:** BUILD-PROMPTS Prompt 8.1; DATABASE §3.7; PRD §2.5; D-045
- **Decision:** A "Wants" sidebar link with Wanted / Bought / Dropped tabs. Pasting a link runs the read-only `LinkPreview` Service right away (so `fetch_status` is `fetched`, `failed` or `manual`; `pending` stays unused): Open Graph title / price / image (`<title>` as fallback), the shop from the domain (Shopee, Lazada… or the domain). It **never throws**: blocked pages, timeouts, odd HTML give "failed" and a friendly message, and manual entry always works. Limits: 5-second timeout, 1 MB read, at most 3 redirects followed by hand. **SSRF-safe**: only http(s) on the default ports and no user:password; the host must resolve to public addresses only (no private, loopback, link-local or reserved ranges), checked again on every redirect, and the request is pinned to the checked IP (`CURLOPT_RESOLVE`) so DNS can't change in between; DNS sits behind a `HostResolver` interface so tests fake it. Only `https` images are kept, shown with `referrerpolicy="no-referrer"`. Pasting the product description fills an empty title (first line) and price (first "₱…"/"PHP …" amount). Marking bought can record the price as an expense from a chosen account (`source = want`, "From want" in Finance, changed only through the want, kept in step on edits); putting a bought want back on the list removes that expense (the money returns); a bought want can't be dropped directly. Deleting a bought want asks the same "was it a mistake?" question as products (D-045).
- **Reason:** Shops often block scrapers, so the fetch is a convenience, never a requirement; fetching user-supplied URLs from the server is a classic SSRF risk.
- **Rejected:** Fetching in a background job; trusting DNS between the check and the request; storing image files; following redirects automatically.

### D-050 · Safe-to-spend until the next payday
- **Date:** 2026-11-18 · **Source:** Owner choice for BUILD-PROMPTS Prompt 8.2; D-008; ARCHITECTURE §7
- **Decision:** The read-only `SafeToSpend` Service (not a `FinanceService`, D-031) computes in PHP: **total balance − what I owe that falls due before the chosen date − the safety buffer** (no scheduled expenses, D-008). "Before" is strict: a debt due on the date itself doesn't count. One-off `i_owe` debts count what remains if due (or overdue) before the date; installment debts count the installments falling before it minus what's paid, capped at what remains. Debts owed to me and debts with no due date aren't subtracted. The result can be negative (you'd be short). The date defaults to the user's **next payday** (owner's choice): new `users.paydays` (JSON days of the month, up to 4, set in Settings → Preferences, e.g. "15, 30"; 29–31 fall on the last day of shorter months; "next" = after the user's today); with no paydays it's the end of the month. The Wants page shows the figure with an expandable breakdown, a date picker, and a "Fits" / "₱… over" badge on each want.
- **Reason:** Most students and young professionals here budget payday to payday.
- **Rejected:** End of month or 30 days as the default; counting debts due on the payday itself; letting the AI do any of this maths.
