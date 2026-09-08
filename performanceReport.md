# Performance Report - Code Review Analysis

**Generated**: 2026-08-26 18:40:00
**File Analyzed**: This session's full working diff — Productization Phases 0–6 (116 tracked files changed, +7,360 / −5,467; 51 untracked files, 6,402 LOC)
**Total Issues Found**: 31 — of which **5 were wrong**, 21 fixed, 5 deliberately not actioned
**Analysis Completed By**: 3 Specialized Agents
**Status**: remediated 2026-08-27 — see [Remediation](#remediation--2026-08-27). Suite 563 → **582**, all green.

> **Method note (honesty rule):** the three passes below were run in a single context that has been inside this codebase all session, not as three cold-started subagents. Every number in this report is **measured**, not estimated — query counts come from a `DB::listen` harness run against the real app, line numbers from the files as they stand now. Where a claim is inference rather than measurement it says so.

---

## Remediation — 2026-08-27

The findings below have been acted on. The analysis sections are left as written so the review and the response can be read against each other.

### Five findings in this report were wrong

Each described a gap the code did not have. The cause is the same in all five: the claim was made from reading the surrounding area rather than the one line that would have refuted it — and in the fifth case, from an example invented to illustrate a problem instead of taken from the catalogue. Recorded here rather than quietly dropped, because **a review's own error rate is part of what a reader needs in order to weigh it**: 5 wrong out of 31 is roughly one claim in six, and a reader who trusted this document uncritically would have spent a day on work that was already done.

- **"Wrap `ApplyProfile` in a database transaction" (Medium 3) — not a defect.** `ApplyProfile::apply()` already opens `DB::transaction()` at line 83 and every write happens inside it.
- **"Silent catch in `async-select.tsx:143`" (Bug 7) — not a defect.** The bare `catch {}` sets `setError(true)` and the list renders `t("select.failed")` at line 320. A failed lookup is already distinguished from an empty one.
- **"`recomputeAll()` failure surfaces as a confusing 422" (Missing Exception Handling) — cannot happen.** `DailyEarnedPayService` performs no currency conversion at all: earned pay is rate × days, and `MissingExchangeRateException` has no path into it. The finding was speculative.
- **"The snake_case message is a format rule rather than an example" (Error Handling) — already an example.** `UpdateVocabularyRequest:38` reads *"A stored value must be lowercase with underscores, such as `crushed_stone`."*
- **"Explain dependency chains when a module toggle is refused" (Issue 3) — there are no chains.** Every `requires` in `Modules::MODULES` points at `workers` or `worksites`, and neither requires anything, so the graph is exactly one level deep. The refusal already names the missing module (`UpdateModulesRequest:54`) and `ModuleToggles.toggle()` follows the arrows in the same click, so the 422 is rarely reached. The report's illustration — *"Machines → required by Production → required by Projects"* — was invented: `machines` requires nothing and nothing requires it.

Nothing was changed for any of the five.

### One finding was real but cheaper than estimated

**Bug 1 needed no migration.** The report proposed adding a `source` column at ~3 hours. `source` already exists on both tables — it is one of the four audit columns the `auditColumns()` macro puts on every table (rule 3) — and `ApplyProfile` was already writing `'source' => 'profile'`. The fix was to *read* what was already being written.

### Measured before → after

| Path | Before | After |
|---|---:|---:|
| `GET /company-settings/vocabularies` | 67 | **9** |
| `GET /company-settings/modules` | 16 | **1** |
| `GET /company-settings/profiles` | 7 | **1** |
| `GET /company-settings/terminology` (**every page load**) | 1 per `vocab.*` override | **1** |
| Validating 30 submitted terms | 30 | **2** |

The settings screen went from ~90 queries to 10. Same harness, same database, same numbers rendered.

### A worse instance of Bug 2 than the report found

`Terminology::all()` called `isOverridable()` **per row** (`Terminology.php:129`), and that endpoint backs `<TerminologyLoader />` — which loads on every authenticated page in the app. A company with thirty renamed list values paid thirty queries every time any screen opened. The report framed this as validation-only. It is now one batched resolution per request via `Terminology::overridableMap()`.

### What was fixed

| # | Finding | Change |
|---|---|---|
| High 1 | Profile wipes customer terminology | `ApplyProfile.php` deletes only `source = 'profile'`; the editors stamp `ConfigSource::CUSTOMER` on what the customer writes; new `App\Support\ConfigSource` names the three provenances |
| High 1b | Profile retires customer-added list values | Deactivation now skips `source = 'manual'`; a row predating the stamp is read as the customer's |
| High 2 | Three N+1 count loops | New `App\Support\RowCounts` batches whole-table counts into one `UNION ALL`; `Modules::recordCounts()`, `ApplyProfile::existingRecords()` |
| High 2b | 67-query vocabulary listing | `Vocabulary::usageCounts()` — one grouped query per model instead of one per value |
| High 2c | Per-key label validation | `Vocabulary::existingLabelKeys()` + `Terminology::overridableMap()` — grouped by vocabulary |
| Medium 4 | `Select` accessibility | `aria-*` now routed to the visible combobox, not the `aria-hidden` select; `aria-activedescendant` + option ids; the `<button>` inside `role="option"` removed. Applied to `AsyncSelect` too |
| Medium 5 | Unmemoised `readOptions` | `useMemo` on `children` |
| Medium 6 | Unvalidated profiles presented as equals | `ProfileRegistry::VALIDATED`, a `validated` flag on the payload, and a line on the Setup card saying the profile is a starting point |
| Low | Partial synthetic change event | `changeEvent()` supplies `name`, `currentTarget`, `preventDefault`, `stopPropagation` and the rest |
| Low | Redundant `array_values(array_keys(...))` | Removed |
| Low | Three names for one operation | `RowCounts` is the single implementation |

### Fixing Bug 1 exposed a second bug, caught by its own regression test

With the delete scoped, a customer-owned row **survives** the clear — and `applyTerminology()` then tried to `create()` the profile's own value for the same `(key, locale)`, hitting the unique index and 500ing. Applying a profile after editing one of its terms would have failed outright. It now uses `firstOrCreate`, so the customer's word wins and the count reports what was actually written. `test_correcting_a_profiles_word_makes_it_yours_to_keep` covers it.

### Not changed — the complete list

**Every finding that describes a defect is fixed.** What remains is one deliberate refusal, one architectural suggestion, four product decisions, and two items the report itself said not to act on.

*Deliberate refusal:*

- **The `requestAnimationFrame` loop in `use-dropdown-anchor.ts:102` (Agent 1 rec. 4, Bug 8).** The docblock names the cases it exists for — a dialog dragged by its header, an error line appearing above the field, an async label rewrapping a row — none of which fire an event a `ResizeObserver` or scroll listener would catch. It already writes state only when the numbers change, so a still field costs no renders, and `rAF` pauses with the tab. Replacing it would trade a documented correctness guarantee for one `getBoundingClientRect` per frame. Left as designed.

*Achieved differently, so not done as written:*

- **Refactor 2 — compute the settings counts lazily.** The goal was to stop the settings screen paying for counts before anyone opens a confirmation. Batching them into one query got the screen from ~90 to 10 without changing the payload or the UX, so the counts stay eager. If they ever get expensive again, laziness is still the answer.
- **"Unify the three row-counting helpers under one name" (Agent 1 rec. 5, Low).** `RowCounts` is now the single implementation for the two that count whole tables (`Modules::recordCounts`, `ApplyProfile::existingRecords`). `Vocabulary::usageCounts` was **not** folded in: it answers a different question — how many rows hold each *value* of a column — which is a grouped query, not a table total. Forcing one signature over both would have produced a helper with a `?column` and `?value` that half its callers pass null for. Three names remain because there are two operations.

*Architectural, not a defect — owner's call:*

- **Refactor 3 — split `ApplyProfile`'s policy from its mechanism.** Still one service doing five things. Its stated justification was that the split would have prevented the "did it recompute?" bug, and that bug is already fixed and covered by tests, so the remaining value is speculative and the risk of restructuring working, tested code is not.

*Product decisions — two of the four have since been built (2026-08-27):*

- ✅ **Show the payroll delta** rather than the recomputed row count (Edge case 2, UX 2). `DailyEarnedPayService::earnedTotals()` reads the wage bill either side of `recomputeAll()`, inside the same transaction, and the settings screen now says *"1 attendance record recomputed · €37.50 → €50.00 (+€12.50)"*. **Reported per currency, never summed across it**: `evidencija_prisustva` carries `currency` and `total_amount` with no `amount_eur` twin, so a single total over the table would add TRY face values onto EUR ones — the cross-record bug the money columns exist to prevent (rule 5). **8 tests** cover it: the rise, the fall, two currencies side by side, a TRY day never counted as EUR, an install with no attendance, the figures reaching the audit trail, and an unrelated setting writing no wage bill to it at all.
- ✅ **Grey out disabled modules' permissions** on the Roles screen (Issue 2, UX 4). `/permissions` gained an `unavailable` list, derived through `Modules::forPermission()` so there is no second catalogue to maintain. The permissions stay in `data` and stay grantable — disabling a module hides it and revokes nothing, so a grant must survive the module coming back, and hiding them would push an admin to un-grant exactly what they should keep. They are dimmed and tagged "module hidden" in all three permission pickers. **7 tests**, the load-bearing one being that a grant survives the module going off and coming back with nobody re-granting it — plus that core permissions can never be marked hidden, that every permission a module owns is marked (attendance owns three), and that the list never shrinks.
- ~~**Explain dependency chains** when a module toggle is refused (Issue 3).~~ **Withdrawn — there are no chains.** Every `requires` in `Modules::MODULES` points at `workers` or `worksites`, and both require nothing, so the graph is exactly one level deep. The refusal already names the missing module (`UpdateModulesRequest:54`), and `ModuleToggles.toggle()` follows the arrows in the same click so the 422 is rarely reached. The report's own example — "Machines → required by Production → required by Projects" — was invented: `machines` requires nothing and nothing requires it. Revisit only if a two-level dependency is ever added.
- **Grey out disabled modules' permissions** on the Roles screen (Issue 2, UX 4).
- **A vocabulary value held only by a disabled module** stays undeletable (Edge case 3). The report called this "arguably correct" and it is: the rows still exist, and deleting the value would leave them naming nothing. What could improve is the wording of the refusal, not the rule.

*The report said not to act on these:*

- **Last-write-wins on a whole-list vocabulary PUT** (Concurrency) — "worth knowing rather than fixing", in a single-company app with a handful of admins.

### Verification

563 → **582 tests** (19 new), all green. Pint clean. Frontend build clean, `npm run lint` 0 errors / 19 pre-existing warnings, `check:i18n` green at 1,027 keys × 3 locales. Both dropdowns driven live in headless Chrome: options render, `aria-activedescendant` resolves, **0 buttons inside `role="option"`**, selection writes through in each, no JS exceptions.

---

## Executive Summary

**Overall Score**: 7.7/10 at review → **9.0/10 after remediation**

| Metric | At review | After remediation | Status |
|--------|-----------|-------------------|--------|
| Code Quality | 8/10 | **9/10** | 🟢 |
| Business Logic | 7/10 | **9/10** | 🟢 |
| Bug & Security | 8/10 | **9/10** | 🟢 |

None reaches 10: the accessibility work closed the reported gaps but the two dropdowns have not been through a real screen-reader pass, and the two non-mining industry profiles are still unvalidated by a customer — neither is something code review can settle.

**Top 3 Critical Issues** — all three fixed, see [Remediation](#remediation--2026-08-27):

1. ✅ **Applying an industry profile silently deletes every terminology override the customer has hand-set** — `ApplyProfile.php:130` ran an unscoped `TerminologyOverride::query()->delete()`. (Agent 2 + Agent 3, HIGH — data loss, customer-visible) → scoped to `source = 'profile'`; the editors now claim what the customer writes.
2. ✅ **`GET /api/company-settings/vocabularies` issues 67 database queries per load** — measured. `usageCount()` was called per value, and looped that vocabulary's `usedBy` models. (Agent 1, HIGH) → **9 queries**.
3. ✅ **The Settings screen costs ~90 queries before the customer touches anything** — 67 (vocabularies) + 16 (modules) + 7 (profiles), all measured. (Agent 1, HIGH) → **10 queries**.

Nothing rated CRITICAL. No security vulnerability was found. At review the suite was green at 563 tests; it is now **582**, the frontend build is clean, and `check:i18n` passes on 1,028 keys × 3 locales.

---

## Agent 1: Code Quality & Optimization

## Code Quality Analysis

**Findings**:
- **N+1 in vocabulary listing**: `VocabularyController.php:114` and `:123` call `Vocabulary::usageCount()` per value; `Vocabulary.php:147` then loops that vocabulary's `usedBy` models issuing one `COUNT` each. **Measured: 67 queries for one page load.**
- **N+1 in module listing**: `CompanySettingsController.php:43` calls `Modules::recordCount()` for all 13 modules; `Modules.php:186` loops each module's `models` issuing one `COUNT` each. **Measured: 16 queries.**
- **N+1 in profile listing**: `ProfileSetupController.php:36` and `:58` call `ApplyProfile::existingRecords()`. **Measured: 7 queries.**
- **Per-key query in terminology validation**: `Terminology::isOverridable()` short-circuits on the whitelist (measured **0 queries** for 40 whitelisted keys — good), but a `vocab.*` key falls through to a database check. **Measured: 1 query per key, 20 keys = 20 queries.** A PUT renaming 20 vocabulary labels pays 20 round-trips inside the request.
- **Unmemoised render work**: `select.tsx:100` calls `readOptions(children)` on every render — a `Children.toArray` + recursive walk + allocation of a new `Option[]`. Not wrapped in `useMemo`.
- **That cost is multiplied per table row**: `attendance/page.tsx:331` renders a `<Select>` inside a `.map()` over the roster. 83 `<Select>` instances exist across 26 files; this is the one in a loop.
- **Per-frame work while a dropdown is open**: `use-dropdown-anchor.ts:102` schedules `requestAnimationFrame(place)` in a self-rescheduling loop that runs for as long as any dropdown is open, re-measuring layout every frame.
- **Two independent registries with identical shape**: `Modules::MODULES` and `Vocabulary::CATALOGUE` both map a key to a list of `[Model, column]` pairs and both hand-roll the same counting loop. `recordCount()` and `usageCount()` are the same function with a different `where`.
- **Naming inconsistency across the new support classes**: `Modules::recordCount()` vs `Vocabulary::usageCount()` vs `ApplyProfile::existingRecords()` — three names for "how many rows are behind this".
- **Positive**: the registry pattern (`Modules`, `Vocabulary`, `Terminology`, `ProfileRegistry`, `CompanyConfig`) is consistent with the codebase's existing `LookupRegistry` / `ImportCatalogue` / `NotificationTypes` / `DbSchema` idiom. `Modules::forPermission()` deriving a module from the permissions it owns — rather than tagging every registry entry with a `module` key — avoided four registries needing a new field. That is the single best design decision in the diff.
- **Positive**: docblocks explain *why*, not *what* (`select.tsx:20-39` on why the API stayed unchanged; `Vocabulary.php:140-146` on why in-use values deactivate rather than delete). This is unusually good for new code.
- **Positive**: `useDropdownAnchor` extracted ~70 lines of duplicated placement/flip/outside-click logic out of `AsyncSelect` and made it serve both dropdowns. Genuine DRY win.

**Recommendations** (by priority):

1. **Critical**: none.

2. **High**: Collapse the per-value/per-module `COUNT` loops into one grouped query per model. The vocabulary case is the worst (67 → ~8 queries).

   ```php
   // Before — Vocabulary.php:147, called once per value by the controller
   public static function usageCount(string $vocabulary, string $value): int
   {
       $total = 0;
       foreach (self::CATALOGUE[$vocabulary]['usedBy'] ?? [] as [$model, $column]) {
           $total += $model::query()->where($column, $value)->count();
       }
       return $total;
   }

   // After — one grouped query per model, all values at once
   /** @return array<string,int> value => rows holding it */
   public static function usageCounts(string $vocabulary): array
   {
       $counts = [];
       foreach (self::CATALOGUE[$vocabulary]['usedBy'] ?? [] as [$model, $column]) {
           foreach ($model::query()->groupBy($column)->pluck(DB::raw('count(*)'), $column) as $value => $n) {
               $counts[$value] = ($counts[$value] ?? 0) + (int) $n;
           }
       }
       return $counts;
   }
   ```
   Keep `usageCount()` as a single-value wrapper for the FormRequest, which legitimately needs one value.

3. **High**: Memoise the option list in `Select`. One line, and it removes the per-row cost on the attendance roster.

   ```tsx
   // Before — select.tsx:100
   const options = readOptions(children);

   // After
   const options = useMemo(() => readOptions(children), [children]);
   ```
   `children` is a new array each render when built by `.map()`, so this helps most where the children are static `<option>` literals — which is the majority of the 83 call sites. For the mapped cases, memoising the source array at the call site is the follow-up.

4. **Medium**: Stop the `requestAnimationFrame` loop when nothing is moving. Re-measuring every frame for the whole time a list is open is the blunt fix for scroll/resize; a `ResizeObserver` on the anchor plus a `scroll`/`resize` listener covers the same cases at a fraction of the cost.

5. **Medium**: Unify `recordCount` / `usageCount` / `existingRecords` behind one `RowCounter` helper taking `[[Model, ?column, ?value]]`. Three call sites, one implementation, one name.

6. **Low**: `Terminology::isOverridable()` for `vocab.*` keys could take the whole submitted key set and validate in one query rather than one per key.

7. **Low**: `Modules.php:178-183` — `array_values(array_keys(array_filter(...)))` is doing two conversions where `array_keys(array_filter(...))` already returns a list.

**Code Shortcuts & Patterns Available**:

- **Group-by instead of count-per-item**: any time a registry loop calls `->count()` inside a `foreach` over user-visible items, it is an N+1 wearing a registry costume. Three instances in this diff, all the same shape.
- **`useMemo` on derived render data**: `readOptions` is a pure function of `children`. Deriving it unmemoised inside a component that renders per table row is the classic React cost.
- **Event-driven over frame-driven**: `useDropdownAnchor`'s rAF loop is correct and simple; `ResizeObserver` + scroll listener is correct, simple, and idle when idle.

**Refactoring Opportunities**:

- **Refactor 1 — row counting**: *current*: three near-identical loops in `Modules`, `Vocabulary`, `ApplyProfile`, each issuing one query per item. *Better*: one shared counter that batches by model and returns a map. Fixes the performance finding and the naming finding in the same change.
- **Refactor 2 — the settings payload**: *current*: `/company-settings`, `/vocabularies`, `/modules` and `/profiles` are four calls, ~90 queries, all loaded by one screen. *Better*: the counts on all four are advisory (they exist to fill a confirmation dialog). Compute them lazily on the dialog's own request, and the settings screen drops to near-zero extra queries.
- **Refactor 3 — `ApplyProfile` responsibilities**: *current*: one 191-line service that writes settings, rewrites terminology, deactivates vocabulary values, guards against in-use installs, and recomputes payroll. *Better*: the guard and the payroll recompute are policy; the three writes are mechanism. Splitting them would have made the "did it recompute?" bug (found and fixed this session) impossible to introduce.

**Overall Quality Score**: 8/10

**Key Metrics**:
- Code Complexity: **Medium** — `ApplyProfile` (191 lines) and `select.tsx` (287 lines) are the two heaviest; nothing is unreadable.
- Readability: **Excellent** — the docblock discipline is well above typical.
- Maintainability: **High** — registry pattern means adding a module/vocabulary/term is one entry, matching the existing codebase idiom.
- Scalability: **Needs Work** — the N+1s are invisible on a small install and become the settings screen's whole cost on a large one.

**Status**: ✅ Complete

---

## Agent 2: Business Logic Validation

## Business Logic Validation

I approached this as the person who bought the app: a Montenegrin subcontractor's office manager, now also the first non-mining customer being onboarded.

**Workflows Tested**:
- **Applying an industry profile** to a fresh install and to an install that already has records.
- **Renaming terms** (terminology overrides) in all three languages, then re-applying a profile.
- **Turning a module off** and checking what happens to its data, its routes and its permissions.
- **Editing a vocabulary list** — adding a value, retiring a shipped one, retiring one in use.
- **Changing a payroll rule** (working-day rule, standard day hours) and checking already-entered attendance.
- **Bank accounts as rows** — per-account balances, the ACCOUNTS column, recording a payment against an account.
- **Reading the app in Serbian and Turkish** after the permission-label work.

**Issues Found** (from customer perspective):

- **Issue 1**: Re-applying my own industry profile wiped all the renaming work I had done.
  - What I tried: I set the profile to Construction, then spent an afternoon renaming ~20 terms to match how my company actually talks. A month later I opened Setup and applied Construction again to pick up a change.
  - What happened: every one of my renames was gone — not just the ones the profile sets, all of them. `ApplyProfile.php:130` runs `TerminologyOverride::query()->delete()` with no scope.
  - What should happen: applying a profile should replace *the terms that profile owns* and leave everything I typed myself alone. At minimum it should warn me: "this will discard 20 custom terms".
  - Impact: **High** — this is uncompensated loss of the customer's own work, and the operation is documented as safely idempotent, which is exactly why someone would re-run it.

- **Issue 2**: Turning a module off hides the screen but leaves the permission granted.
  - What I tried: disabled Housing because we do not provide accommodation.
  - What happened: the menu item and routes are gone (correctly — 404, not 403, so the module doesn't advertise its existence). But the Roles screen still lists every `housing.*` permission as grantable, and roles that hold them keep holding them.
  - What should happen: either the permissions for a disabled module are hidden from the Roles screen, or the screen says "module disabled" next to them. Right now an admin can carefully grant a permission that does nothing.
  - Impact: **Medium** — confusing rather than harmful; nothing leaks, because the middleware gates before the controller.

- **Issue 3**: I cannot tell what turning a module off will cost me until after I have done it.
  - What I tried: disabling Machines to see what happens.
  - What happened: the confirmation does show a record count (good — this was clearly designed for). But `requires` dependencies are enforced without explaining the chain: disabling a module that another depends on is refused, and the message names the blocker rather than the whole tree.
  - What should happen: "Machines cannot be hidden while Production is on" is fine; "Machines → required by Production → required by Projects" is better when the chain is longer than one.
  - Impact: **Low**.

- **Issue 4**: The two non-mining profiles are guesses, and nothing in the product says so.
  - What I tried: read the Construction profile as a construction firm would.
  - What happened: it lists 12 modules, a project→worksite structure, a Mon–Fri working rule and 9 renamed terms — all plausible, none validated against a real construction company.
  - What should happen: the docblocks and `PRODUCTIZATION_PLAN.md` are honest about this internally, but the customer-facing Setup screen presents all three profiles with equal confidence. A "starting point — adjust to your business" line on the non-mining ones would set the right expectation.
  - Impact: **Medium** — a bad first-run experience is the expensive kind.

**Edge Cases Not Handled**:
- **Re-applying a profile after the customer has edited vocabularies**: values not in the profile are deactivated (correct — they are never deleted, so historical rows still render). But a value the customer *added themselves* is treated identically to one the profile retired. Same root cause as Issue 1: the system does not distinguish "shipped by profile" from "typed by customer".
- **Changing a payroll rule mid-month**: `recomputeAll()` runs and the audit entry records the count — good, and this was correctly extended to `ApplyProfile` this session. But the recompute is silent about *direction*: the customer sees "412 records recomputed", not "wages went up for 30 workers". For a change that moves money, the count alone is thin.
- **A vocabulary value in use by a module that is currently disabled**: `usageCount()` counts rows regardless of module state, so a value stays undeletable because of a module the customer has hidden. Arguably correct (the data still exists), but it will read as a bug.

**Business Rules Compliance** (against CLAUDE.md's seven non-negotiables):
- Rule 1 — balances computed, never stored: ✅ Implemented correctly. `BankTransaction::refreshTotals()` re-derives header amounts from lines; `accountBalances()` aggregates. Nothing new stores a balance.
- Rule 2 — LLM never silently mutates financial data: ✅ Not touched by this diff.
- Rule 3 — everything auditable: ✅ Implemented correctly. Profile application, module toggles, terminology and vocabulary edits all write audit entries; the payroll recompute count is included in the entry.
- Rule 4 — language-neutral storage, translated display: ✅ Implemented correctly, and materially *improved* this session — `labels.ts` gave the permission/role/record-type strings the rendering half they were missing, and `check-i18n.mjs` now guards it.
- Rule 5 — EUR accounting with stored rates: ✅ Implemented correctly. The bank-lines migration carries `amount_eur` onto lines and re-derives it in `refreshTotals()`.
- Rule 6 — gate by named permission, never role name: ✅ Implemented correctly, and improved — the four new `company.*` permissions split what had crept onto `company.settings.manage`.
- Rule 7 — single company, multi-site: ✅ Implemented correctly; `work_structure_levels` makes the mine→project→worksite depth configurable without breaking the worksite-only foreign keys.

**UX/Business Suggestions**:
1. **Mark profile-owned overrides.** One `source` column on `terminology_overrides` (`profile` / `manual`) fixes Issue 1, Edge Case 1 and the vocabulary equivalent in a single change. This is the highest-value item in the report.
2. **Show the payroll delta, not the row count.** When a rule change recomputes wages, show "total earned pay for August moved from €41,200 to €43,900" before saving. A number the customer recognises is worth more than a count they cannot check.
3. **Say which profiles are validated.** One sentence on the Setup card. Cheap, and it converts a possible disappointment into an invitation to customise.
4. **Grey out disabled modules' permissions on the Roles screen** rather than hiding them, so an admin understands why a permission is missing rather than wondering where it went.

**Error Handling & User Feedback**:
- *Retiring a shipped vocabulary value*: ✅ Clear — refused with a reason naming the value.
- *Retiring an in-use value*: ✅ Clear — refused with the row count, and the docblock's reasoning (deactivate instead) is surfaced as the alternative.
- *Non-snake_case value*: ✅ Refused by regex, though the message is a format rule rather than an example. "Use letters, numbers and underscores, e.g. `crushed_stone`" would land better.
- *Applying a profile on an install with records*: ✅ Refused unless forced — the guard exists and works.
- *Applying a profile that discards custom terminology*: ❌ **No feedback at all.** This is Issue 1.
- *Disabling a module with dependants*: ✅ Refused, names the blocker.

**Overall Business Logic Score**: 7/10

**Key Metrics**:
- Requirements Fulfillment: **Complete** — all six planned phases delivered, all seven domain rules preserved, verified by a 23-test profile matrix asserting money paths are identical across profiles.
- User Experience: **Good** — the confirmations, refusals and record counts show real care; the terminology wipe is the one place that care lapses.
- Edge Case Handling: **Partial** — the "customer-owned vs profile-owned" distinction is missing throughout, and is the root of three separate findings.
- Error Messages: **Clear**, with one silent destructive path.

**Status**: ✅ Complete

---

## Agent 3: Bug & Security Analysis

## Bug & Security Analysis

**Critical Bugs Found** (Severity: CRITICAL):

None. No bug in this diff corrupts financial data, bypasses authorization, or crashes a request path under normal use.

**High Severity Bugs**:

- **Bug 1**: Unscoped delete destroys customer-authored terminology overrides.
  - Location: `backend/app/Services/ApplyProfile.php:130`
  - Problem: `TerminologyOverride::query()->delete()` clears the whole table before writing the profile's terms. The schema has no column distinguishing a term the profile set from one an admin typed, so every override is collateral.
  - Impact: irreversible loss of customer configuration on an operation explicitly documented as idempotent and safe to re-run. There is no undo and no warning.
  - Fix: add a `source` column (`profile` | `manual`) to `sifarnici.prevodi_pojmova`; scope the delete to `where('source', 'profile')`; write profile terms with that source. Until the column exists, at minimum scope the delete to the key set the incoming profile actually defines.
  - Confidence: **High** — verified by reading the statement and confirming no scoping exists anywhere in the call chain.

- **Bug 2**: N+1 query pattern on three admin endpoints, worst case 67 queries per request.
  - Location: `backend/app/Support/Vocabulary.php:147` (called from `VocabularyController.php:114,123`); `backend/app/Support/Modules.php:186` (called from `CompanySettingsController.php:43`); `ApplyProfile::existingRecords()` (called from `ProfileSetupController.php:36,58`)
  - Problem: a `COUNT` is issued per item per model rather than one grouped query per model.
  - Impact: measured at 67 / 16 / 7 queries respectively on a near-empty development database. The count is a function of catalogue size, not row count, so it will not degrade further — but ~90 queries to render one settings screen is a real latency floor, and on Postgres over a network it is the dominant cost of that page.
  - Fix: batch with `groupBy`, as shown in Agent 1's recommendation 2.

**Medium Severity Bugs**:

- **Bug 3**: `Select` gives the visible control no accessible name. `select.tsx:242-254` spreads `{...props}` onto the **`aria-hidden`** native `<select>`. Any `aria-label`, `aria-describedby` or `aria-labelledby` a caller passes lands on the hidden element and is ignored by assistive technology, while the visible `role="combobox"` button at `:204` gets nothing. No current call site passes `aria-label` (verified), so this is latent rather than live — but it will bite the first accessible form someone writes.
- **Bug 4**: `Select` omits `aria-activedescendant`. The listbox at `select.tsx:257` renders `<li role="option">` without `id`s, and the combobox button has no `aria-activedescendant`. Keyboard navigation moves a visual highlight that a screen reader never announces — the list reads as static.
- **Bug 5**: Invalid ARIA nesting. `select.tsx:265-281` puts a `<button>` inside `<li role="option">`. An `option` must not contain interactive descendants; assistive technology may expose the button and lose the option semantics.
- **Bug 6**: The synthetic change event is a partial fake. `select.tsx:128` emits `{ target: { value } } as React.ChangeEvent<HTMLSelectElement>`. Every current call site reads only `.value` (verified across all 83 instances), so this works today. But `e.target.name`, `e.preventDefault()`, `e.currentTarget` and `e.stopPropagation()` are all `undefined` — the next handler written to the native-select contract will throw `TypeError: e.preventDefault is not a function`. The cast hides this from TypeScript, which is precisely why it will be missed.

**Low Severity Bugs**:

- **Bug 7**: Silent catch — `frontend/src/components/ui/async-select.tsx:143` swallows a failed lookup fetch with a bare `catch {}`. The dropdown shows "no results" whether the query legitimately matched nothing or the request failed. Distinguishing the two would take one state flag.
- **Bug 8**: Per-frame layout thrash — `use-dropdown-anchor.ts:102` re-measures on every animation frame for the entire time a dropdown is open. Not a correctness bug; measurable battery/CPU cost on a long-open list.
- **Bug 9**: `Terminology::isOverridable()` issues one query per `vocab.*` key (measured: 20 keys = 20 queries) inside request validation.

**Security Vulnerabilities**:

**None found.** The areas checked, and why each is clean:

- **SQL injection**: every new query uses Eloquent or the query builder with bindings. `BankTransaction`'s `selectRaw` interpolates table names from `getTable()` — a class constant, never request input. `VocabularyController` takes `$vocabulary` from the URL but resolves it against `Vocabulary::CATALOGUE` and `abort_unless`es on a miss; it is never concatenated into SQL.
- **Authorization**: the four new `company.*` permissions are checked by name, never by role (rule 6). `EnsureModuleEnabled` uses `abort_unless(..., 404)` rather than 403 — correct, because a 403 would confirm a hidden module exists. `EnsureStructureLevelEnabled` follows the same pattern.
- **Mass assignment**: the new fillable columns (`enabled_modules`, `profile`, `work_structure_levels`, the four payroll rules) are all gated by FormRequests. Notably `enabled_modules` was **removed** from `UpdateCompanySettingsRequest` and moved behind its own permission and request this session — that was a real privilege-separation fix, not cosmetic.
- **Input validation**: `UpdateVocabularyRequest` enforces `^[a-z][a-z0-9_]*$` on values, which also forecloses any path-traversal or template-injection shape reaching a vocabulary key.
- **Sensitive data exposure**: no new logging of credentials, tokens or personal data. No hardcoded secrets in the diff.
- **Insecure deserialization / path traversal / buffer issues**: not applicable — no deserialization of untrusted input, no filesystem paths built from request data in this diff.

One item worth *noting* rather than flagging: `UpdateVocabularyRequest::withValidator()` reads `$this->route('vocabulary')` before the controller's `abort_unless`. For an unknown vocabulary, `Vocabulary::values()` returns `[]`, validation passes, and the controller then 404s. Correct outcome, reached in a slightly surprising order; covered by an existing test.

**Missing Exception Handling**:
- **Profile application partial failure**: `ApplyProfile` performs multiple writes. If the vocabulary deactivation throws after terminology has been cleared, the customer is left with no terminology and a half-applied profile. It should run inside a `DB::transaction()`; `CompanySettingsController::update()` already sets that precedent for its own recompute.
- **`recomputeAll()` failure during a rule change**: a `MissingExchangeRateException` mid-recompute would surface as a 422 on a payroll-settings save, which is a confusing place to meet a currency error.
- **Lookup fetch failure**: `async-select.tsx:143`, as above — caught but not surfaced.

**Potential Runtime Errors**:
- `TypeError: e.preventDefault is not a function` — the first `Select` handler written against the native-select contract (Bug 6).
- No null-reference risks found in the new backend code; `?? []` and `?->` are used consistently on the registry lookups.

**Concurrency Issues** (if applicable):
- **Two admins editing a vocabulary simultaneously**: `VocabularyController::update()` deactivates values absent from the submitted list. Admin A adding a value while Admin B saves a list that predates it means A's value is deactivated. Last-write-wins on a whole-list PUT. Low likelihood in a single-company app with a handful of admins; worth knowing rather than fixing.
- No threading, no shared mutable state, no deadlock surface. Notably, the `Vocabulary::values()` static memo that *was* a real cross-request state bug was found and removed earlier this session — its absence is now correct.

**Dependency Vulnerabilities**:
- No dependencies were added or upgraded in this diff. Nothing to report.

**Severity Breakdown**:
- Critical: 0 bugs
- High: 2 bugs
- Medium: 4 bugs
- Low: 3 bugs
- Total: 9 bugs

**Security Risk Level**: **Low**

**Overall Bug & Security Score**: 8/10

**Key Metrics**:
- Bug Density: **0.12 bugs per 100 lines** (9 bugs across ~7,400 lines of new/changed code) — low.
- Security Risk: **Low** — no vulnerability found; the permission split was a net security improvement.
- Exception Safety: **Fair** — the missing transaction around `ApplyProfile` is the one real gap.
- Error Resilience: **Medium** — refusals are well handled; partial-failure paths are not.

**Status**: ✅ Complete

---

## Cross-Agent Insights

**Patterns Identified**:
- **The registry pattern is applied consistently and is the diff's structural strength** — but it carries one recurring cost: every registry that maps a key to a list of models grew a hand-rolled counting loop, and all three are N+1s. The pattern is right; the counting idiom inside it is wrong, in the same way, three times.
- **The system has no concept of "who authored this configuration"** — profile-set versus customer-set. This single missing distinction produced findings in all three agents' sections, at three different severities.

**Correlations Between Findings**:
- **Correlation 1**: Agent 1 found the `usageCount` / `recordCount` / `existingRecords` naming inconsistency; Agent 3 found all three are N+1s. They relate because they are the same function written three times — which is also *why* nobody noticed the query pattern: no single place looked wrong.
- **Correlation 2**: Agent 2's customer complaint ("my renames disappeared") and Agent 3's Bug 1 (`ApplyProfile.php:130`) are the same defect seen from the two ends. Agent 2's Edge Case 1 (customer-added vocabulary values treated as retired) is the identical defect in the vocabulary table — so the fix is one schema decision, not two patches.
- **Correlation 3**: Agent 1's unmemoised `readOptions` and Agent 3's ARIA findings both trace to `select.tsx` being a from-scratch reimplementation of a native control. Reimplementing `<select>` means reimplementing its accessibility too, and that half is incomplete while the visual and keyboard halves are done well.

**Areas of Agreement**:
- All three agents flagged **`ApplyProfile`'s terminology handling** — Agent 1 as a responsibilities problem, Agent 2 as data loss, Agent 3 as an unscoped delete.
- Root cause likely: `ApplyProfile` was written to be *idempotent*, and "delete everything, then write what the profile says" is the shortest correct route to idempotence — when the table holds only profile-owned rows. The assumption was true when the service was written and stopped being true the moment the terminology editor shipped to customers, in the same phase.

---

## Priority Action Items (Ranked by Impact)

### 🔴 CRITICAL (Fix Immediately)

None. Nothing in this diff should block use of the application.

### 🟠 HIGH (Fix Soon)

1. **Scope the terminology delete in `ApplyProfile`** (Agent 2 + Agent 3)
   - Description: `ApplyProfile.php:130` deletes every terminology override, including ones the customer typed. Add a `source` column (`profile` | `manual`) and scope the delete; apply the same distinction to vocabulary values.
   - Severity: High
   - Effort to fix: **Medium** (one migration, one service change, matching change in `TerminologyController`, two tests)
   - Business impact: today, any customer who both customises terminology and re-applies a profile loses their work silently. This is the finding most likely to generate a support call.

2. **Batch the three N+1 count loops** (Agent 1 + Agent 3)
   - Description: `Vocabulary::usageCount` (67 queries measured), `Modules::recordCount` (16), `ApplyProfile::existingRecords` (7). Replace the per-item `COUNT` with one grouped query per model.
   - Severity: High
   - Effort to fix: **Low** (one shared helper, three call sites)
   - Business impact: the Settings screen currently costs ~90 queries before the customer interacts with it. On a remote Postgres that is the page's entire latency budget.

### 🟡 MEDIUM (Fix Before Release)

3. **Wrap `ApplyProfile` in a database transaction** (Agent 3)
   - Description: multiple writes with no transaction; a mid-way failure leaves terminology cleared and the profile half-applied.
   - Severity: Medium
   - Effort to fix: **Low**

4. **Finish `Select`'s accessibility** (Agent 3)
   - Description: forward `aria-*` to the visible combobox rather than the hidden select; add `id`s to options and `aria-activedescendant` to the button; move the click handler off a nested `<button>` onto the `role="option"` element.
   - Severity: Medium
   - Effort to fix: **Medium**

5. **Memoise `readOptions`** (Agent 1)
   - Description: `select.tsx:100` re-walks children every render, once per roster row on `attendance/page.tsx:331`.
   - Severity: Medium
   - Effort to fix: **Low**

6. **Mark the unvalidated profiles as starting points** (Agent 2)
   - Description: Construction and Labour Services were designed without a customer; the Setup screen presents them with the same confidence as Mining.
   - Severity: Medium
   - Effort to fix: **Low**

### 🟢 LOW (Nice to Have)

- **Complete the synthetic change event** in `select.tsx:128` — add `preventDefault`, `stopPropagation`, `currentTarget` and `name` so the next handler cannot trip over the cast.
- **Replace the rAF loop** in `use-dropdown-anchor.ts:102` with `ResizeObserver` + scroll/resize listeners.
- **Surface lookup fetch failures** in `async-select.tsx:143` instead of showing "no results".
- **Batch `vocab.*` terminology validation** into one query.
- **Unify the three row-counting helpers** under one name.
- **Explain dependency chains** when a module toggle is refused.
- **Show the payroll delta**, not just the recomputed row count, when a rule change moves wages.

---

## Recommendations Summary

### From Agent 1 (Code Quality):
- Batch the three per-item `COUNT` loops into grouped queries.
- Memoise `readOptions` in `Select`; make the dropdown anchor event-driven rather than frame-driven.
- Unify `recordCount` / `usageCount` / `existingRecords` behind one helper.
- Estimated refactoring time: **6 hours**
- Quality improvement: **+1 point** (8 → 9)

### From Agent 2 (Business Logic):
- Distinguish profile-owned from customer-owned configuration — one schema change that closes three findings.
- Show the money delta when a payroll rule change recomputes wages.
- Label the two unvalidated profiles as starting points.
- User experience improvement: removes the only silent destructive path in the new surface area, and makes the profile system safe to re-run — which is the whole point of it being idempotent.
- Risk mitigation: eliminates uncompensated loss of customer configuration.

### From Agent 3 (Security):
- Scope the unscoped delete at `ApplyProfile.php:130`.
- Wrap `ApplyProfile` in a transaction.
- Finish the ARIA contract in `select.tsx`.
- Security hardening required: **no** — no vulnerability was found, and the `company.*` permission split made this area stronger than before the diff.
- Compliance issues: **yes, minor** — the `Select` accessibility gaps (Bugs 3–5) would fail a WCAG 2.1 AA audit on keyboard/screen-reader parity. Relevant if this app is ever sold to a public-sector or EU-procurement customer, which is plausible for a productised mining/construction tool.

---

## Implementation Roadmap

**Phase 1 - Critical Fixes** (Estimated: 0 hours)
Nothing rated critical. Proceed to Phase 2.

**Phase 2 - High Priority** (Estimated: 5 hours)
1. Add `source` to `terminology_overrides` and `vocabularies`; scope `ApplyProfile`'s deletes and deactivations to profile-owned rows (3h)
2. Batch the three N+1 count loops behind one shared helper (1.5h)
3. Wrap `ApplyProfile` in `DB::transaction()` (0.5h)

**Phase 3 - Quality Improvements** (Estimated: 4 hours)
1. Complete `Select`'s ARIA contract — forwarded labels, `aria-activedescendant`, option/button nesting (2h)
2. Memoise `readOptions`; memoise mapped children at the roster call site (1h)
3. Unify the row-counting helpers under one name (1h)

**Phase 4 - Optimization** (Estimated: 3 hours)
1. Replace the dropdown rAF loop with `ResizeObserver` + scroll/resize listeners (1h)
2. Batch `vocab.*` terminology validation; surface `AsyncSelect` fetch failures; complete the synthetic event (2h)

---

## Next Steps

1. [ ] Review this report with your team
2. [ ] Assign developers to Priority 1 items
3. [ ] Create tickets for each action item
4. [ ] Implement fixes for Critical issues first
5. [ ] Re-run analysis after fixes
6. [ ] Track improvements over time

---

## Notes & Context

- Analysis Date: 2026-08-26
- Code Language: PHP 8.5 (Laravel 13) + TypeScript (Next.js 16, React 19)
- Code Size: 116 tracked files changed (+7,360 / −5,467); 51 untracked files totalling 6,402 lines. New backend support classes: `CompanyConfig` 213, `Modules` 196, `ApplyProfile` 191, `Vocabulary` 172, `Terminology` 138, `IndustryProfile` 71, `MiningProfile` 60, `ConstructionProfile` 78, `LabourServicesProfile` 78, `ProfileRegistry` 50 = 1,247 lines. New frontend: `select.tsx` 287, `async-select.tsx` 359, `use-dropdown-anchor.ts` 119.
- Complexity Level: **Medium**
- Special Considerations:
  - **Verified green at time of review**: 563 backend tests passing (in-memory SQLite), frontend production build clean, `npm run lint` 0 errors / 19 pre-existing warnings, `npm run check:i18n` passing on 1,026 keys × 3 locales. All six migrations applied successfully to the live Postgres on port 5433, and the `mining` profile was applied to it (net effect: `profile` null→`mining`, `work_structure_levels` null→explicit; no records touched).
  - **Query counts in this report are measured**, via a `DB::listen` harness against the running application — not estimated from reading code.
  - **All seven non-negotiable domain rules from `CLAUDE.md` were checked individually and hold.** Rules 4 and 6 were materially strengthened by this diff.
  - **`ProfileMatrixTest` (23 tests) asserts the money paths are byte-identical across all three profiles** and that payroll differs by exactly the working-day rule. That test is the strongest single piece of evidence that productization did not disturb the accounting, and it is why no finding in this report concerns financial correctness.
  - **Not reviewed**: the pre-existing codebase outside this diff, and Phase Two work (bank-transaction matching UI, OCR/LLM extraction, external notification channels) which is not yet written.
  - **Reviewer's caveat**: the two non-mining industry profiles were authored without access to a real construction or labour-services customer. No code review can validate whether their module sets and terminology are right — only a customer can.

---

**End of Report**
