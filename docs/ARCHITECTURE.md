# Synop — Architecture

**Status:** Approved · **Last updated:** 2026-11-16
**Sources:** [PRD.md](PRD.md), [DATABASE.md](DATABASE.md)

---

## 1. Stack and why

| Piece | Why |
|---|---|
| **Laravel** (latest stable, PHP 8.3+) | Auth, queues, scheduler, validation, policies and HTTP client built in. One language for the whole app. |
| **Livewire starter kit** | Login, registration and settings come ready-made. Interactive screens are written in PHP and Blade, with no separate JS framework. |
| **Tailwind CSS** | Ships with the starter kit. Utility classes make mobile-first layouts quick. |
| **MySQL** | Relational data with many foreign keys (debts → payments → transactions). JSON columns cover the few flexible fields. |
| **Pest** | Short, readable tests. Used for the "user A can't see user B" tests in every module. |
| **PWA** | Installable on Android and iPhone without app-store work (PRD §6: no native apps). |

## 2. Folder structure

```
app/
  Actions/{Module}/      One user operation per class: CreateDebt, RecordDebtPayment, MarkOccurrence…
  Services/              Reusable logic: ScheduleEngine, SafeToSpend, IngredientMatcher, Ai/…
  Models/                Eloquent models
  Models/Concerns/       BelongsToUser trait
  Policies/              One Policy per user-owned model
  Casts/                 MoneyCast
  Support/               Money value object
  Enums/                 Backed enums (Frequency, DebtDirection, TransactionType…)
  Livewire/{Module}/     Screens and forms (thin: validate → call Action → render)
  Notifications/         Morning summary (web push)
database/migrations, factories, seeders
tests/Unit/              ScheduleEngine, Money, SafeToSpend (pure PHP, no DB where possible)
tests/Feature/{Module}/  Actions, Livewire screens, isolation tests
```

> **Action vs Service** (learning note): an *Action* does one thing that changes data ("record a payment") and usually has a single `handle()` method. A *Service* answers questions or wraps an outside system ("what's due today?", "ask the AI") and is reused by many Actions.

## 3. Rule: business logic lives in Actions and Services

Livewire components only: validate input, call an Action or Service, and show the result. They never calculate balances, create linked rows or call the AI.

Actions take plain inputs (the `User` and an array or small data object), never a Livewire component or `Request`. That way a future mobile API controller can call the same `RecordDebtPayment` and get identical behaviour. Anything that writes more than one row (e.g. a payment + its transaction + settling the debt + completing its task) runs inside `DB::transaction()`.

## 4. Multi-user security model

Four layers, so one mistake doesn't leak data:

1. **`BelongsToUser` trait + global scope.** On every user-owned model it (a) adds `where user_id = <logged-in user>` to every query and (b) fills `user_id` automatically on create. When no one is logged in (queued jobs, console commands) the scope **fails closed** and returns no rows. Background jobs that work on one user's data opt out explicitly and filter by that user's id.
2. **Route model binding.** Binding queries go through the global scope, so `/debts/42` for another user's debt returns **404**, not 403. The user can't even tell the record exists (PRD §5).
3. **Policies.** Every model has one; Livewire actions call `$this->authorize(...)`. They also hold rules beyond ownership, e.g. "a task created from a debt can't be deleted" (DATABASE §3.3). Record IDs kept in Livewire public properties are marked `#[Locked]` so the browser can't change them.
4. **Cross-user links.** Form validation uses `Rule::exists(...)->where('user_id', ...)`, so a user can't attach another user's account or product to their own record.

> **Policy** (learning note): a class that answers "may this user do X to this record?" Laravel calls it via `authorize()` or `@can`.

**Tests.** Each module has a feature test that creates users A and B and proves that B cannot list, view, edit or delete A's records, or link to them, through routes, Livewire actions and validation. A shared Pest helper keeps these short.

## 5. The Schedule Engine

`app/Services/ScheduleEngine` is pure PHP and has no database access. It answers two questions: **"Does rule R occur on date D?"** and **"Which dates between D1 and D2?"**

`occursOn(rule, D)`, in this order:
1. Paused (`paused_at` set) → **no**.
2. D before `starts_on`, or after `ends_on` → **no**.
3. By frequency:
   - `daily` → **yes**
   - `every_n_days` → yes if the number of days from `starts_on` to D is divisible by `interval`
   - `weekly` → yes if D's ISO weekday (Mon = 1) is in `weekdays`
   - `monthly` → yes if D's day = `min(day_of_month, days in D's month)` (31 → Nov 30)

`occurrencesBetween` clamps D1–D2 to the rule's own range, then checks each date. Callers use short ranges (Today = one date, upcoming = a few weeks).

Around the engine:
- **D is always a local date:** `now($user->timezone)->toDateString()`. Dates are never converted to UTC.
- **Versioned rules:** an item can have several rules with non-overlapping ranges. The caller picks the one covering D, then asks the engine.
- **Done/skipped** status comes from `schedule_logs` (`schedule_rule_id`, `occurs_on`) and is merged in by the Today query, not by the engine.
- The Today view loads all of the user's active rules in a few queries, then evaluates them in memory (no query per item).

## 6. Money

- **`App\Support\Money`**: a small immutable value object holding an `int` of centavos, with `add`, `subtract`, `isNegative` and `format()` (→ `₱1,234.50`).
- **`App\Casts\MoneyCast`**: makes `*_cents` columns read as `Money` and write back as integers.
- **Parsing user input**: `Money::parse('1,234.5')` splits the string on the decimal point and builds centavos with integer maths. It never goes through `float`.
- **Display**: a Blade helper `@money($amount)` and a `money()` function. Negative amounts show as `−₱500.00`.
- Livewire form fields hold the amount as a **string**; the Action parses it. Sums are done in SQL (`SUM(amount_cents)`) or with `Money::add`.

> **Cast** (learning note): tells Eloquent how to convert a column when reading and writing it. Here, an integer column becomes a `Money` object in PHP.

## 7. AI integration

- **Server-side only.** The key lives in `.env` (`GEMINI_API_KEY`) and is read through `config/services.php`. The browser never talks to the AI provider.
- **Provider behind an interface.** `Services/Ai/AiClient` has a `GeminiClient` implementation, so switching to a paid provider later (PRD *Later*) means changing one binding in the service container.
- **Two uses:** ingredient label scan (image → text the user reviews) and want verdicts.
- **Per-user limit: 10 real calls per local day**, counted from `ai_requests` before each call. Over the limit → rules-based fallback (verdicts) or manual entry (scans). Cache hits don't count.
- **Caching:** verdict inputs are hashed (`inputs_hash`). If an analysis with the same hash exists, it is reused with no call.
- **Deterministic money.** `Services/SafeToSpend` computes, in PHP: total balance − `i_owe` amounts due before the chosen date (capped at remaining) − safety buffer (DATABASE §5 Q2). The AI receives these finished figures plus the ingredient-check flags, and **only returns a verdict and 2–3 reasons**. Its reply is validated: an unexpected verdict or bad JSON means we use the rules fallback.
- **Privacy:** only product info, ingredients and the computed figures are sent. Never names, contacts or account details. Label images are sent once and not stored.
- **Reliability:** short timeout, errors caught, fallback shown. An AI failure never breaks the page.

## 8. PWA plan (Phase 8)

- **Manifest** (`public/manifest.webmanifest`): name, icons (192/512 plus a maskable icon), `display: standalone`, theme colour. iOS meta tags in the layout.
- **Service worker**: caches static assets and an **offline fallback page**. Pages and data are always loaded from the network; there's no offline editing (PRD §6).
- **Install prompt**: a small "Add to home screen" hint. On iPhone it shows the Share → Add to Home Screen steps.
- **Web push morning summary**: a web-push package (VAPID keys in `.env`) plus `push_subscriptions`. A scheduled command runs every few minutes and sends a `MorningSummary` notification to users whose `morning_summary_time` has just passed in their own timezone. On iPhone, push only works once the app is installed to the home screen (iOS 16.4+).
- **Mobile-first UI**: bottom navigation, tap targets of at least 44px, Today as the start page.

---

## Resolved points (approved 2026-11-16)

1. **Livewire component style.** Use whatever the starter kit generates (class-based or single-file Volt), and keep components thin either way.
2. **Global scope fails closed when no one is logged in** (§4.1). Jobs opt out explicitly and filter by user.
3. **Gemini** is the free-tier AI provider.

*Approved by the owner on 2026-11-16.*
