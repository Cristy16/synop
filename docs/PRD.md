# Synop — Product Requirements

**Status:** Approved · **Last updated:** 2026-11-16

---

## 1. Purpose and target user

**Purpose.** Synop is one private place to run your own life: what to do, what to put on your face, what to take, where your money is, and what's due for school. It answers two questions every day: *"What do I need to do today?"* and *"Can I afford this?"*

**Target user.** Students and young professionals (roughly 17–30) in the Philippines who manage their own schedule, money and personal care. Today they juggle several separate apps, notes and spreadsheets. They mostly use the app on their phone.

**Multi-user.** Anyone can sign up. Each account is fully private. There is no sharing between users in the MVP.

---

## 2. Modules and features

### 2.1 Tasks
- To-do items with title, notes, due date (optional time), and priority (low / normal / high)
- Mark done or reopen; delete
- Filters: today, upcoming, overdue, done
- Recurring tasks (daily, weekly on chosen weekdays, every N days, monthly)
- **Tasks can come from other modules** (an education activity, low vitamin stock, a debt due). These tasks link back to where they came from.

### 2.2 Today view (home screen after login)
One screen with these sections:
| Section | Shows |
|---|---|
| Tasks | Due today plus overdue |
| Personal Care | Today's AM and PM routine steps, with conflict warnings |
| Vitamins | Today's doses, each with a done checkbox |
| Classes | Today's class sessions in time order |
| Payments Due | Debts due soon, both directions |

Each item can be marked done or skipped from this screen.

### 2.3 Personal Care
- **Inventory:** skincare, makeup and vitamins. Each product has brand, name, category, price, purchase date, opened date, period-after-opening (PAO, in months), quantity and status (unopened / in use / finished / discarded).
- **Ingredients:** each product can store its ingredient list. The user adds it by pasting text or by **uploading a screenshot or photo of the label**. The app reads the image (AI vision) and fills in the list, which the user reviews and edits before saving.
- **Expiry:** expiry date = opened date + PAO. A product appears under "expiring soon" within **14 days** of that date.
- **Routines:** AM and PM routines with ordered steps. Each step uses one product.
- **Per-step schedules:** a step can run daily, every N days, or on specific weekdays (e.g. exfoliant on Tue/Sat).
- **Conflict rules:** the user defines them, e.g. "Retinol — not with AHA on the same night." Today's routine shows a warning when a conflict applies.
- **Usage logs:** a record of each step or dose marked done or skipped on a date.
- **Spending:** monthly spending per category. Buying a product can optionally record an expense in Finance.
- **Vitamins:** a dose schedule with time of day and an optional meal note ("after breakfast"). A task is created when stock runs low.

### 2.4 Finance
- **Accounts:** cash, e-wallet (GCash, Maya…) and bank, each with an opening balance.
- **Total balance:** the sum of all accounts. Each account's balance is computed from its transactions.
- **Transactions:** income, expense or transfer, with amount, category, date and note. Transfers don't change the total.
- **Contacts:** people who are not app users (name, optional note).
- **Debts in two directions:**
  - *Owed to me:* money a contact borrowed from me
  - *I owe:* money I borrowed from a contact
  - Each debt has principal, due date, partial payments, remaining amount and status (open / settled). Every payment is recorded against one of my accounts.
  - A debt with a due date **creates a linked task**, for both directions ("Pay Ana ₱500" / "Collect ₱500 from Ben"). The task closes when the debt is settled.
- **Overview:** total balance, total owed to me, total I owe, and net position.

### 2.5 Wants list
- Paste a product link from an online shop (Shopee, Lazada, etc.).
- The app tries to fetch the title, price and image automatically. **Shops often block this**, so the user can always enter the details manually or paste the product description instead.
- Each item has priority and status (wanted / bought / dropped). Marking it bought can record an expense.
- **Safe-to-spend:** current balance − debts I owe due before a chosen date − scheduled expenses − a safety buffer the user sets. **This is computed in code, not by the AI.**
- **Ingredient check (skincare and makeup):** paste or screenshot the ingredient list, using the same scan as Personal Care. The app flags:
  - matches with the user's conflict rules ("contains AHA, and your rule says: not with retinol")
  - duplicates of products already owned ("you already own 2 niacinamide serums")
- **AI analysis:** a verdict (buy now / wait / skip) with 2–3 reasons, based on the price, safe-to-spend, this month's spending in that category, and the ingredient check. It uses a **free-tier AI provider** (e.g. Gemini). The AI only explains; PHP computes every money figure.
- **Fallback:** if the AI is unavailable or the user has hit their daily limit, the app shows a rules-based verdict instead. Results are cached per item.

### 2.6 Education
- Semesters, with one active at a time. Old semesters can be archived.
- Courses: code, title, instructor and room.
- Class schedule: recurring sessions such as MWF 8:00–10:00.
- Activities: quiz, exam, project or assignment, each with a due date. **Each activity automatically creates a linked task.**
- Archiving a semester hides its classes from Today.

### 2.7 Shared: Schedule Engine
Tasks, routine steps, vitamins and classes all repeat on schedules. One schedule engine handles all of them: daily, specific weekdays, every N days, monthly, with start and end dates and pausing. Modules don't each build their own recurrence logic.

---

## 3. User stories

### Tasks
- As a user, I want to add a task in one line so that capturing it is faster than forgetting it.
- As a user, I want to set due dates and priorities so that I know what matters first.
- As a user, I want recurring tasks so that I don't re-enter weekly chores.
- As a user, I want school deadlines and low-stock alerts to become tasks automatically so that I don't have to copy them by hand.

### Today view
- As a user, I want one screen that shows everything for today so that I don't have to check five places each morning.
- As a user, I want to tick items done from that screen so that tracking takes seconds.

### Personal Care
- As a user, I want to log my products with opened dates so that I know when they expire.
- As a user, I want AM/PM routines where some steps only run on certain days so that today's list is always correct.
- As a user, I want to set my own conflict rules so that I'm warned before I mix actives that shouldn't be used together.
- As a user, I want to see how much I spend on skincare and makeup each month so that I can keep it in check.
- As a user, I want vitamin reminders and low-stock alerts so that I don't miss doses or run out.
- As a user, I want to screenshot a product's ingredient label so that I don't have to type long ingredient lists.

### Finance
- As a user, I want to add my cash, e-wallet and bank accounts so that I see my total balance in one place.
- As a user, I want to record income, expenses and transfers so that my balances stay accurate.
- As a user, I want to track money friends owe me and money I owe, including partial payments, so that nothing gets forgotten.
- As a user, I want to add people who don't use the app as contacts so that I can track debts with anyone.

### Wants list
- As a user, I want to paste a shop link and have the details filled in so that saving a want is quick.
- As a user, I want to type the details myself when the fetch fails so that I'm never blocked.
- As a user, I want to see how much is safe to spend so that a purchase doesn't leave me short for upcoming debts.
- As a user, I want an AI opinion on whether something is a good buy right now so that I make calmer spending decisions.
- As a user, I want to be warned when a product I want clashes with my conflict rules or duplicates something I own so that I don't waste money.

### Education
- As a user, I want to set up my semester and courses once so that my class schedule shows up every day.
- As a user, I want to add quizzes, exams and projects with due dates so that they become tasks I can't miss.
- As a user, I want to archive past semesters so that old classes stop showing up.

### Account
- As a user, I want my data to be private to my account so that no one else can see my money or routines.
- As a user, I want to set my timezone so that "today" means my today.

---

## 4. MVP scope vs later

### MVP (follows the phases in BUILD-PROMPTS.md)
| # | Phase | Included |
|---|---|---|
| 1 | Foundation | Auth, user settings (timezone, currency, safety buffer), data isolation |
| 2 | Tasks + Today | Tasks CRUD, filters, Today screen with all sections |
| 3 | Finance | Accounts, transactions, transfers, contacts, two-way debts with partial payments |
| 4 | Schedule Engine | Shared recurrence rules plus done/skip logs; recurring tasks |
| 5 | Personal Care | Inventory, expiry, ingredient lists (pasted), routines, per-step schedules, conflict rules, vitamins, spending |
| 6 | Education | Semesters, courses, class sessions, activities that become tasks |
| 7 | Wants + AI | Link fetch with manual fallback, safe-to-spend, ingredient screenshot scan, conflict and duplicate check, free-tier AI verdict with rules fallback |
| 8 | PWA + reminders | Installable app, offline fallback page, morning summary via **web push** |

### Later
- Budgets per category with alerts
- Recurring transactions (bills, salary, subscriptions)
- Charts and trends (spending over time, routine consistency)
- Grade tracking and GPA per course
- Price tracking for wants (alert when the price drops)
- Automatic ingredient lookup from Open Beauty Facts (free product database)
- Suggested alternatives with similar key ingredients
- Switching to a paid AI provider if the free limits are too tight
- Data export (CSV) and account deletion with full data wipe
- Product photos and barcode scan
- Dark mode, more languages (e.g. Filipino)

---

## 5. Non-functional requirements

| Area | Requirement |
|---|---|
| **Data isolation** | Every user-owned record belongs to exactly one user. A user can never read, change or even detect another user's data. Every module has automated tests that try to access another user's data and confirm it fails. |
| **Timezone** | Times are stored in UTC and shown in the user's timezone. The default is **Asia/Manila**, changeable per user. "Today" and schedules use the user's timezone. |
| **Currency** | The default is **PHP (₱)**. Money is stored as whole centavos (integers), never as decimals. |
| **Mobile-first** | Designed for phone screens first and scaled up for desktop. Tap targets are easy to hit. Common actions take one or two taps. |
| **PWA** | Can be installed on Android and iPhone home screens. Shows an offline fallback page. |
| **Security** | API keys stay on the server only. AI and link-fetch calls go through the server, with a per-user rate limit. |
| **AI privacy** | Free AI tiers may let the provider use requests for training. Send only the minimum: product info, ingredient text or images, and money figures. Never send names, contacts or account details. Tell users in the app when AI is used. |
| **Reliability** | A failed link fetch or AI call never breaks a page. The user sees a clear fallback. |
| **Performance** | The Today view loads quickly on a mid-range phone over mobile data. |

---

## 6. Out of scope (for now)

- Sharing data between users (shared debts, shared tasks, family accounts)
- Bank or e-wallet syncing (no GCash or bank API connections)
- Multiple currencies and exchange rates
- Native iOS or Android apps (PWA only)
- Offline editing and sync (offline shows a fallback page only)
- Medical or dermatological advice. The app states facts ("contains X, which matches your rule") and never says "safe for your skin." Users write their own conflict rules.
- AI that moves money or makes purchases. The AI only advises.
- Admin dashboard, teams or paid plans

---

## Resolved decisions
- Morning summary is sent by **web push** (MVP).
- Debts with a due date **create a linked task**.
- "Expiring soon" = **14 days** before expiry.
- AI uses a **free-tier provider** with a rules-based fallback.
- Ingredient lists can be **scanned from a screenshot**.
- AI limit: **10 requests per user per day** (scans and verdicts combined; cached results don't count). This protects the shared free quota.
