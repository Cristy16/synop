# Build Prompts — Synop

A chronological prompt playbook for building the app with an AI coding agent (Claude Code or Copilot) in VS Code.
Feed **one prompt at a time**. Do not move to the next prompt until the "Done when" check passes.

**Stack:** Laravel (latest stable) · PHP 8.3+ · MySQL · Livewire starter kit · Tailwind · Pest · PWA
**Users:** Multi-user. Every user's data is private to them.

---

## How to use this file

- Save it in your repo as `docs/BUILD-PROMPTS.md` and tick the checkboxes as you go.
- **Start a fresh agent session for each stage** (`/clear` in Claude Code). The agent re-reads `CLAUDE.md` and the docs, so you never re-explain the project. That's the biggest token saver.
- Use **plan mode** (Shift+Tab in Claude Code) for any prompt marked 🧭. The agent proposes a plan, you approve it, and only then does it write code.
- Prompts marked ⛔ end with a stop gate: the agent must wait for your approval before continuing.
- After every prompt, commit: `git add . && git commit -m "<what was done>"`.

### Reusable prompts (use any time)

**Session start**
```
Read CLAUDE.md and docs/ROADMAP.md. Tell me in 3 lines: the current phase, the next unchecked item, and any open question. Do not write code yet.
```

**Session end**
```
Update docs/ROADMAP.md (tick finished items) and add any decision we made to docs/DECISIONS.md in the existing format. Keep each entry short. Show me the diff.
```

**When something breaks**
```
Here is the error: <paste only the error + the file:line>. Read only the files involved. Explain the cause in 2 sentences, then propose the smallest fix. Don't touch unrelated files.
```

---

## Stage 0 — Manual setup (no AI needed)

- [ ] Install PHP 8.3+, Composer, Node.js LTS, MySQL (or **Laravel Herd** on Windows/Mac, which bundles PHP + Composer)
- [ ] Install Git, create a GitHub repo (`synop`), clone it
- [ ] Install the VS Code extensions from the list in the chat
- [ ] Create a `docs/` folder
- [ ] Put `CLAUDE.md` in the repo root and this file in `docs/BUILD-PROMPTS.md`
- [ ] Commit: `git add . && git commit -m "docs: agent instructions and build playbook"`

---

## Stage 1 — Planning documents (before any code)

Do this stage in Claude Code, inside the repo, so the files get written directly. These files are the project's memory.

### Prompt 1.1 — Product requirements ⛔
```
Read CLAUDE.md. I'm building a multi-user personal productivity app (web first, installable as a PWA on mobile) using Laravel. Write docs/PRD.md with:

1. Purpose and target user (students/young professionals managing their own life)
2. Modules and features:
   - Tasks: to-do list, due dates, priority, recurring tasks; other modules can create tasks
   - Today view: one screen showing today's tasks, skincare/makeup steps, vitamins, classes, and payments due
   - Personal Care: product inventory (skincare, makeup, vitamins) with price, purchase date, opened date, period-after-opening expiry; AM/PM routines; products that are not used daily (e.g. every 3 days, specific weekdays); user-defined conflict rules (e.g. "not with retinol on the same night"); usage logs; spending per category
   - Finance: accounts (cash, e-wallet, bank) and current total balance; transactions; debts in two directions (money people borrowed from me / loans I owe) with partial payments; contacts who are not app users
   - Wants list: paste a product link from online shops, fetch title/price when possible with manual fallback, AI analysis of whether it's a good buy given my money
   - Education: semesters, courses, class schedule, activities/deadlines that become tasks
3. User stories per module ("As a user, I want… so that…")
4. MVP scope vs later features
5. Non-functional requirements: multi-user data isolation, Asia/Manila default timezone (per-user setting), PHP peso default currency, mobile-first UI
6. Out of scope for now

Keep it concise and scannable. Stop and wait for my review before anything else.
```
**Done when:** You've read the PRD and corrected anything wrong.

### Prompt 1.2 — Database design ⛔ 🧭
```
Read docs/PRD.md. Write docs/DATABASE.md with:

1. Conventions:
   - Every user-owned table has user_id (foreign key, indexed, cascade on delete)
   - Money stored as integers in centavos (BIGINT), never floats
   - Timestamps in UTC, displayed in the user's timezone
   - Soft deletes only where the user may want to restore data
2. A Mermaid ERD covering all modules
3. A table-by-table list: columns, types, indexes, relationships
4. Shared building blocks used across modules:
   - schedule_rules (polymorphic: attaches to routines, vitamins, class sessions, recurring tasks, debt due dates). Support: daily, every N days, specific weekdays, monthly on day X, with start/end dates
   - schedule_logs (done/skipped per date)
   - transactions (polymorphic "source", so a skincare purchase or debt payment creates a transaction)
5. Open questions you couldn't decide

Stop and wait for my approval.
```
**Done when:** The ERD renders in VS Code (Markdown Preview Mermaid Support) and every table that holds personal data has `user_id`.

### Prompt 1.3 — Architecture ⛔
```
Read docs/PRD.md and docs/DATABASE.md. Write docs/ARCHITECTURE.md covering:

1. Stack and why: Laravel, Livewire starter kit, Tailwind, MySQL, Pest, PWA
2. Folder structure: where Models, Livewire components, Services, Policies, Actions, Casts live
3. Rule: business logic goes in app/Services or app/Actions, NOT in Livewire components, so a mobile API can reuse it later
4. Multi-user security model: BelongsToUser trait + global scope, Policies for every model, route model binding protection, tests proving user A cannot read/edit user B's data
5. The Schedule Engine: how a rule is evaluated for a given date
6. Money: custom Money cast, formatting helpers
7. AI integration: server-side only, API key in .env, per-user rate limit, cache results, deterministic "safe to spend" calculation done in PHP with the AI only explaining it
8. PWA plan

Keep it under 2 pages. Stop and wait for approval.
```

### Prompt 1.4 — Roadmap, decisions log, and agent rules
```
Read CLAUDE.md and all files in docs/. Create:

1. docs/ROADMAP.md: phases as checkbox lists:
   Phase 1 Foundation (scaffold, auth, multi-user base) → Phase 2 Tasks + Today view → Phase 3 Finance → Phase 4 Schedule Engine → Phase 5 Personal Care → Phase 6 Education → Phase 7 Wants + AI → Phase 8 PWA + reminders → Phase 9 Security review

2. docs/DECISIONS.md: a decision log. Format per entry: date, decision, reason, alternatives rejected. Seed it with the decisions already made in ARCHITECTURE.md and DATABASE.md.

3. Update the existing CLAUDE.md: change every doc's status in the Docs table from "To be written" to "Done", and add any new hard rule that came out of DATABASE.md or ARCHITECTURE.md. Keep it under 80 lines. Don't remove existing rules.

Show me the two new files and the CLAUDE.md diff.
```
**Done when:** `docs/ROADMAP.md` and `docs/DECISIONS.md` exist and every doc in CLAUDE.md's table says "Done". Commit: `docs: project foundation`.

---

## Stage 2 — Phase 1: Foundation

### Prompt 2.1 — Scaffold ⛔
```
Read CLAUDE.md. Give me the exact terminal commands to create a new Laravel app in the current folder with the Livewire starter kit, MySQL, and Pest. Then, after I run them:
- set APP_TIMEZONE to UTC and add a timezone column to users (default Asia/Manila)
- add a currency column to users (default PHP)
- configure Laravel Pint
- make sure .env.example is complete and .env is gitignored
- confirm the scaffold didn't overwrite CLAUDE.md or docs/, and fill in the Commands section of CLAUDE.md
Explain each command in one line. Stop after listing the commands so I can run them myself.
```
**Done when:** You can register, log in, and `php artisan test` passes.

### Prompt 2.2 — Multi-user base 🧭
```
Read CLAUDE.md and the "Multi-user security model" section of docs/ARCHITECTURE.md. Implement:
1. app/Models/Concerns/BelongsToUser trait: user() relation, global scope limiting queries to auth()->id(), auto-fill user_id on create. Handle the case where there is no authenticated user (console, queued jobs) safely.
2. A Money cast (centavos int <-> display) with unit tests.
3. A reusable Pest helper/test proving that a model using BelongsToUser is invisible to another user.
Explain what a global scope and a cast are in 2 sentences each.
```
**Done when:** Isolation tests pass.

---

## Stage 3 — Phase 2: Tasks + Today view

### Prompt 3.1 — Tasks backend 🧭
```
Read CLAUDE.md and the tasks section of docs/DATABASE.md. Build the Task model, migration, factory, policy, and a TaskService (create, update, complete, reopen, delete). Tasks can optionally belong to a "source" via a polymorphic relation (so other modules can create tasks later). Write Pest tests including cross-user access attempts. No UI yet.
```

### Prompt 3.2 — Tasks UI
```
Build Livewire components for tasks: list with filters (today, upcoming, overdue, done), quick-add input, edit modal, complete checkbox. Mobile-first Tailwind layout. Use TaskService for all logic. Add the page to the app navigation.
```

### Prompt 3.3 — Today view skeleton
```
Create a Today page (make it the dashboard/home after login) with sections: Tasks, Personal Care, Vitamins, Classes, Payments Due. Only Tasks has real data now; the other sections show an empty state. Build it so each section is its own Livewire component that later modules can fill.
```
Run **Session end** prompt. ✅ Phase 2 done.

---

## Stage 4 — Phase 3: Finance

### Prompt 4.1 — Accounts and transactions 🧭
```
Read CLAUDE.md and the finance section of docs/DATABASE.md. Build accounts (name, type: cash/e-wallet/bank, opening balance) and transactions (income/expense/transfer, amount in centavos, category, date, note, polymorphic source). Account balance = opening balance + sum of transactions, computed in a FinanceService. Transfers between accounts must not change the total. Pest tests for balances and transfers.
```

### Prompt 4.2 — Debts both ways 🧭
```
Build contacts (people who may not be app users), debts (direction: owed_to_me or i_owe, principal, due date, status), and debt_payments. Recording a payment creates a transaction in the chosen account and updates the remaining balance. A debt is "settled" when remaining = 0. Tests for partial payments, overpayment rejection, and both directions.
```

### Prompt 4.3 — Finance UI
```
Build the Finance pages: overview (total balance, total owed to me, total I owe, net position), accounts list, transactions list with filters, debts list grouped by contact. Show upcoming debt due dates in the Today view's "Payments Due" section.
```
Run **Session end** prompt. ✅ Phase 3 done.

---

## Stage 5 — Phase 4: Schedule Engine (the hardest part)

### Prompt 5.1 — Design only ⛔ 🧭
```
Read the Schedule Engine sections of docs/ARCHITECTURE.md and docs/DATABASE.md. Do NOT write code. Propose:
- the ScheduleRule model and the occursOn(Carbon $date): bool logic for each rule type
- how to get all items due on a date efficiently for one user
- edge cases: month with no day 31, every-N-days counting from start date, timezone boundaries, end dates, paused rules
Add the final design to docs/DECISIONS.md. Stop for my approval.
```

### Prompt 5.2 — Implement with tests first
```
Implement the approved Schedule Engine. Write the Pest tests FIRST for every rule type and edge case from the design, then the code to make them pass. Include schedule_logs (done/skipped per date). Show me the test results.
```

### Prompt 5.3 — Plug into Today view
```
Connect the Schedule Engine to the Today view so any model with a schedule rule appears on the right days. Add support for recurring tasks using the engine. Add a "mark done / skip" action that writes to schedule_logs.
```
✅ Phase 4 done.

---

## Stage 6 — Phase 5: Personal Care

### Prompt 6.1 — Product inventory 🧭
```
Build products (category: skincare/makeup/vitamin, brand, name, price, purchase date, opened date, period-after-opening in months, quantity/status). Buying a product can optionally create an expense transaction in a chosen account (via the transactions source relation). Show "expiring soon" products and monthly spending per category.
```

### Prompt 6.2 — Routines and conflict rules 🧭
```
Build routines (AM/PM) with ordered steps; each step links to a product and has its own schedule rule (e.g. exfoliant Tue/Sat only). Add user-defined conflict rules between products ("don't use A and B on the same day/session"). When today's routine contains a conflict, show a warning in the Today view. Tests for conflict detection.
```

### Prompt 6.3 — Vitamins
```
Add vitamin intake schedules using the Schedule Engine (time of day, with/after meals note). Show them in the Today view's Vitamins section with a done checkbox. Add a low-stock reminder that creates a task when quantity runs low.
```
✅ Phase 5 done.

---

## Stage 7 — Phase 6: Education

### Prompt 7.1 — Semesters, courses, classes, activities
```
Build semesters (with an active flag), courses (code, title, instructor, room), class sessions using schedule rules (e.g. MWF 8–10am), and activities (title, type: quiz/exam/project/assignment, due date). Creating an activity creates a linked task automatically. Show today's classes in the Today view. Archiving a semester hides its classes.
```
✅ Phase 6 done.

---

## Stage 8 — Phase 7: Wants list + AI

### Prompt 8.1 — Wants list with link fetch 🧭
```
Build wishlist items (url, title, price, image, shop, priority, status: wanted/bought/dropped). On paste, try to fetch title/price/image via Open Graph tags with a short timeout. Online shops often block this, so ALWAYS allow manual entry and a "paste product description" fallback. Never crash on a failed fetch. Marking an item "bought" can create an expense transaction.
```

### Prompt 8.2 — Safe-to-spend calculator (no AI yet)
```
In FinanceService, implement safeToSpend(user, date): current total balance minus debts I owe due before a chosen date, minus scheduled expenses, minus a user-set safety buffer. Pure PHP, fully tested. Show it on the wants page.
```

### Prompt 8.3 — AI analysis 🧭
```
Add an AiAdvisor service that sends the AI: the product info, price, safeToSpend breakdown, and the user's spending in that category this month. It returns a short verdict (buy now / wait / skip) plus 2–3 reasons. Rules: API key only in .env, called server-side, rate limit per user (e.g. 10/day), cache the result per item, handle API failures gracefully. The AI must never compute the money figures; PHP does. Mock the API in tests.
```
✅ Phase 7 done.

---

## Stage 9 — Phase 8: PWA + reminders

### Prompt 9.1 — Installable PWA
```
Make the app an installable PWA: web manifest, icons, service worker caching the app shell, offline fallback page. Explain how to test installation on Android and iPhone.
```

### Prompt 9.2 — Reminders
```
Using Laravel's scheduler, send each user a morning summary (email or web push) of their Today view, respecting their timezone. Add per-user notification settings.
```

---

## Stage 10 — Phase 9: Security review

### Prompt 10.1 — Security audit ⛔
```
Do a security review of the whole app, focused on: cross-user data access (every model, every route, every Livewire action), mass assignment, validation, XSS in Blade, CSRF, rate limiting on auth and AI routes, secrets handling, and SSRF risk in the link fetcher. List findings by severity with file:line. Do not fix anything yet. Wait for my approval.
```

---

## Stage 11 — Subagents (once the codebase is established)

### Prompt 11.1 — Create project subagents
```
Create Claude Code subagents in .claude/agents/ for this project:
1. code-reviewer: reviews a diff against CLAUDE.md rules (user_id + policy, centavos, logic in Services, tests present). Read-only.
2. test-writer: writes Pest tests for a given Service or feature, including cross-user isolation tests.
3. security-auditor: runs the Stage 10 checklist on changed files only. Read-only.
4. docs-keeper: updates ROADMAP.md, DECISIONS.md, and DATABASE.md after a feature is finished.
Keep each agent's instructions short and give each only the tools it needs.
```

**Using them afterwards:**
```
Use the test-writer subagent to cover app/Services/FinanceService.php, then the code-reviewer subagent to review the changes. Summarize both results in 5 lines.