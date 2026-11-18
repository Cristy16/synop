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
