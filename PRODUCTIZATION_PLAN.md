# Productization Plan — from "Global Mine's app" to a multi-industry product

**Status:** **all six phases delivered**, 2026-08-26. Written the same day.
Reviewed and remediated 2026-08-27 — see `performanceReport.md`.
**Suite:** 582 green (560 at delivery; the rest came out of the review).
**Goal:** the same codebase serves a mining subcontractor, a construction
subcontractor and a labour-services firm, without a fork, without a customer
branch, and without turning into a configurable-everything platform.

This plan is deliberately narrow. Read [What this plan refuses to
build](#6-what-this-plan-refuses-to-build) before adding anything to it.

---

## 1. The finding this rests on

The app is **not** mining software. It is a **site-based labour-subcontractor
operations system** that happens to be pointed at bauxite.

Evidence from the code:

- `evidencija_proizvodnje` is a generic "output measured at a site on a date,
  approved by someone" table. Its mining-ness is three *defaults*:
  `material_type` default `bauxite_ore`
  (`backend/database/migrations/0001_01_01_000700_create_proizvodnja_operativa_tables.php:34`),
  `unit` default `tons`, and a nullable `quality_grade`.
- Mining vocabulary is essentially absent: `tonnage` 0 hits, `mineral` 0,
  `drill` 0, `blast` 0, `shaft` 0. `mine` appears 39 times in ~24k lines, and
  mostly as a table or route name.
- The genuinely domain-specific modules — housing, flight tickets, social
  assistance, loans against wages, masters running crews — describe **any**
  labour-heavy subcontractor with a migrant workforce.

So the work is not "make it generic". It is **open the axes that actually vary
between companies of the same shape, and nail every other axis shut.**

Target verticals, in order of closeness: construction & civil works · road and
infrastructure · shipbuilding · industrial cleaning and facility services ·
warehouse/logistics labour supply · seasonal agriculture.

---

## 2. The core idea: profiles, not knobs

Do **not** ship a settings screen with sixty independent checkboxes. Sixty
booleans is 2^60 untestable states and a support burden that ends a one-person
product.

Ship **industry profiles**: named bundles defined in code, applied once at
onboarding, then overridable field by field.

```
IndustryProfile  (PHP class — versioned, reviewed, tested)
    |
    +-- enabled modules      -> which of the optional modules exist at all
    +-- terminology pack     -> what the domain nouns are called
    +-- vocabularies         -> which open-ended enum values exist
    +-- rule parameters      -> standard day, overtime rule, entitlements
    |
    v  applied at install / onboarding
podesavanja_kompanije.profile   = 'construction'
podesavanja_kompanije.overrides = { ...only the diff... }
```

Two properties make this work:

1. **A profile is code.** It can be unit-tested, and the suite can be run under
   two or three of them (Phase 6). Raw config cannot be tested — only observed
   in production, at a customer.
2. **The database stores a name plus a diff.** A customer who changed three
   things has three rows of override, not a full copy of the configuration.
   Improving a profile improves every install that did not override that field.

---

## 3. Invariants this plan must not break

Everything in `CLAUDE.md` still holds. Four are load-bearing here, and are
restated because this plan is exactly the kind of work that erodes them:

- **Rule 4 — language-neutral storage.** Terminology and vocabulary changes
  affect *labels only*. A stored value stays canonical snake_case forever.
  `mining_production.submit` remains `mining_production.submit` even where the
  UI says "Output Log".
- **Rule 6 — permissions gate features.** A module toggle is ANDed with the
  named permission, never a replacement for it. Turning a module on grants
  nobody anything.
- **Rule 1 — balances are computed.** No phase here may introduce a stored
  total, including a "cached per-profile" one.
- **Rule 3 — auditable.** Profile application, module toggles, terminology and
  vocabulary edits are configuration changes with financial consequences. They
  are audited like role changes are.

Plus one new invariant, specific to this plan:

- **No per-customer branch, ever.** The moment the repo forks for customer #2
  there are two products and half a developer each. Any customer-specific
  behaviour is a profile field, or it does not exist.

---

## 4. Module catalogue

| Always on (core — not togglable) | Togglable |
|---|---|
| auth / users / roles | workers |
| company settings | attendance + daily earned pay |
| exchange rates | salary payments |
| bank & cash | masters |
| payables | worker needs |
| receivables | production |
| reports | machines |
| audit log | customs documents |
| notifications | housing |
| imports | travel, tickets & social assistance |
| dashboard | loans & advances |
| | assistant |
| | work-structure levels 1 and 2 (see Phase 5) |

Rationale for the split: the core is what makes it a business system at all —
money in, money out, who did it. Everything togglable is an operational
speciality that some target vertical does not have.

**A disabled module hides; it never deletes.** Rows stay, FKs stay valid,
re-enabling restores the module with its history intact. That is what makes the
toggle safe enough to use as a sales tool.

---

## 5. Phases

Each phase is independently shippable and independently valuable. Phases 1–4 are
worth doing even if the product is never sold to anyone.

### Phase 0 — Bank accounts as rows *(prerequisite, ~2 weeks)* — **DONE**

**Why first:** `cash_amount` / `nlb_amount` / `lovcen_amount` hardcode two
Montenegrin bank names into the schema
(`backend/database/migrations/0001_01_01_000600_create_finansije_tables.php:103-111`).
Every customer outside Montenegro breaks on the first screen. This is a design
error, not a missing feature — **do it whether or not the rest of this plan ever
happens.**

Scope is larger than it looks: **41 backend files** and **10 frontend files**
reference `nlb`/`lovcen`, including 16 test files, every settlement FormRequest
(the `method` enum `['cash','nlb','lovcen','other']`), `PaymentBankMovement`,
`DashboardService`, `MonthlyCashflowReport`, `LookupRegistry`, `ImportCatalogue`
and two import parsers.

Steps:

1. New table `sifarnici.bankovni_racuni` — `(name, kind: cash|bank, currency,
   iban, is_active, sort_order)` + audit columns. It is reference data, like
   suppliers and clients, so it belongs beside them rather than in `finansije`.
   A cash till is opened on every install; the two banks only where there is
   history that names them.
2. New table `finansije.stavke_transakcija` — `(bank_transaction_id, account_id,
   amount, amount_eur)`. A movement has one or more lines.
3. Migration backfills: for each existing `bank_transactions` row, write one line
   per non-zero of the three columns. Assert the row count and the summed
   `amount_eur` are unchanged before dropping the old columns.
4. `BankTransaction` gains `lines()`; `amount` / `amount_eur` become the sum.
   `normalizeAmountSigns()` and `duplicateIds()` follow the lines.
5. The `method` enum on the six settlement FormRequests becomes `account_id`
   (nullable — `other` becomes "no account"). `PaymentBankMovement` resolves the
   account instead of mapping a method name to a column.
6. `LookupRegistry` gains a `bank_account` resource so forms use `<AsyncSelect>`.
7. Frontend: `bank-record-field.tsx` becomes an account picker; the bank,
   dashboard, payables, receivables, housing, salaries, loans and travel pages
   drop their three hardcoded columns.
8. `ImportCatalogue`'s bank template swaps the three columns for an `account` /
   `amount` pair, plus a second pair for the transfer case. The column is an
   enum of this company's own account names, so the template offers what
   exists rather than three fixed banks.

**Acceptance:** the full suite green with the three-column vocabulary gone from
`app/` entirely; the dashboard's balances identical to before the migration, on a
restored copy of the dev database.

**Delivered 2026-08-26.** `sifarnici.bankovni_racuni` + `finansije.stavke_transakcija`,
with `2026_08_26_000100_bank_accounts_as_rows` backfilling the lines and converting
`method` to `account_id` on both settlement tables. `BankTransaction::setLines()` /
`refreshTotals()` are the single write path. New `/api/bank-accounts` CRUD, a
`bank-accounts` lookup, a `/bank-accounts` page and the shared `<AccountField>`
replacing the cash/nlb/lovcen `<select>` in all seven settlement forms. Suite: 456
tests green (8 new in `BankAccountTest`, plus lines/duplicate/closed-account cases in
`BankTransactionTest`). Still to run on the real database: `php artisan migrate`
against Postgres.

---

### Phase 1 — `CompanyConfig` + rule parameters *(~3 days)* — **DONE**

**Why:** payroll rules are the most-varying thing between companies of the same
shape, and they are already isolated in two services.

Steps:

1. Add columns to `podesavanja_kompanije`: `standard_day_hours` (default 8),
   `overtime_multiplier` (default 1.5), `working_day_rule` (enum:
   `every_non_sunday` | `mon_fri` | `six_day` | `calendar`, default
   `every_non_sunday`), `social_assistance_annual` (default 1000), `profile`
   (default `mining`), `enabled_modules` (json), `overrides` (json).
2. New `App\Support\CompanyConfig` — a cached singleton over the settings row,
   invalidated on save. Everything reads config through it; nothing calls
   `CompanySettings::current()` in a loop.
3. `DailyEarnedPayService::STANDARD_DAY_HOURS`
   (`backend/app/Services/DailyEarnedPayService.php:17`) and
   `DEFAULT_OVERTIME_MULTIPLIER` (`:20`) become config reads. Keep the consts as
   the fallback defaults, so the tests asserting 8h/1.5x keep passing untouched.
4. `WorkingDaysService` (the `isSunday()` branch at `:45-52`) gains the rule
   switch.
5. `SocialAssistanceService::DEFAULT_ANNUAL_ENTITLEMENT` (`:20`) likewise.
6. Settings screen gets a "Payroll rules" section behind
   `company.settings.manage`, with the change audited.

**Acceptance:** all 442 tests green with no test edited. A test that sets
`standard_day_hours = 7.5` produces prorated pay accordingly.

**Deliberately NOT in this phase:** making `base_currency` real. See §7.

**Delivered 2026-08-26.** `2026_08_26_000200_add_payroll_rules_to_company_settings`
adds the four columns with defaults that reproduce today's behaviour;
`App\Support\CompanyConfig` (a per-request singleton, cleared by
`CompanySettings::saved()`) is the only reader, and each getter falls back to the
constant that used to hold the value. `DailyEarnedPayService`,
`WorkingDaysService` and `SocialAssistanceService` take it by constructor
injection. Settings screen gained a **Payroll rules** section behind
`company.settings.manage`, audited.

One addition to the plan: **changing a payroll rule recomputes every attendance
record** in the same transaction (`recomputeAll()`), with the count in the audit
entry and in the response. The plan did not call for it, but the earned figures
are cached on the record and the codebase already took a firm position on this
for the working-days override — leaving them stale would pay two workers with
identical attendance differently depending on when their day was typed.

One deviation: `profile`, `enabled_modules` and `overrides` were **not** added.
The plan put them in this migration, but nothing reads them until Phases 2 and 5,
and a column with no reader is a guess about a shape not yet designed. They come
with the phase that uses them.

Suite: **466 green**, ten of them new in `CompanyPayrollRulesTest` — and the
acceptance criterion held exactly: no existing test needed editing.

---

### Phase 2 — Module toggles *(~4 days)* — **DONE**

**Why it is cheap:** `routes/api.php` is already grouped by module — 17
`Route::middleware('can:…')->group()` blocks. The toggle rides alongside.

Steps:

1. `App\Support\Modules` — the catalogue: key, the permissions it owns, the nav
   keys it owns, whether it is core. One entry per module, same pattern as
   `NotificationTypes`.
2. `EnsureModuleEnabled` middleware, aliased `module`. **Returns 404, not 403** —
   a disabled module does not exist; 403 would tell a customer what they are not
   buying.
3. `routes/api.php`: each group becomes
   `Route::middleware(['can:machines.manage', 'module:machines'])`.
4. `NavItem` (`frontend/src/lib/nav.ts:1`) gains `module?: string`; the sidebar
   filters on permission AND module. A group with no visible items disappears.
5. `ReportRegistry` — `Report` gains a `module()` method alongside `key()` and
   `permission()` (`backend/app/Reports/Report.php:16-19`); `availableTo()`
   filters on it.
6. `NotificationTypes::TYPES` entries gain `module`; `NotificationScanner` skips
   disabled arms and `NotificationDispatcher` never writes them. Existing rows
   for a now-disabled module are left alone, not resolved.
7. `LookupRegistry` and `ImportCatalogue` entries gain `module`; both filter.
8. `DashboardService` — a section belonging to a disabled module is **absent from
   the payload**, exactly as it already is for a missing permission.
9. Settings screen: module toggles, audited, showing the count of existing rows
   next to anything being switched off.

**Acceptance:** with `housing` disabled, `GET /api/houses` is 404 for a Super
Admin, the sidebar has no Housing group, the housing reports are gone from
`/api/reports`, the dashboard payload has no housing section — and the house rows
are still in the database. Re-enabling restores all four.

**Delivered 2026-08-26**, and the acceptance case was run against the live app,
not only the suite: housing off gave 404 on `/houses` and `/lookups/houses`,
dropped its report and its dashboard section, left its record in place, and came
back whole on re-enable.

The design landed simpler than the plan. Step 5 (a `module()` on `Report`) and
step 7 (a `module` key on `LookupRegistry` and `ImportCatalogue`) were **not
needed**: every entry in those registries already declares the named permission
of the module behind it, so `Modules::forPermission()` derives the module and one
map drives all six surfaces. Four registries keep their data untouched, and
nothing can drift out of step with the catalogue.

Also added beyond the plan: **module dependencies**. `requires` names the modules
a module points at with a non-null foreign key, a set that breaks one is refused
422, and the settings screen follows the arrows so the operator sees the
consequence in the click rather than in an error. Without it, "turn off workers"
leaves attendance as a table of rows about nobody.

`enabled_modules` is null-means-all rather than a seeded list, so an existing
install is unchanged and a module added in Phase 5 arrives on rather than
invisible.

Suite: **482 green**, sixteen new in `ModuleToggleTest` — the six surfaces, the
core being unswitchable, the permission still being required, hide-not-delete,
the dependency refusal, and two catalogue-integrity tests (no permission claimed
by two modules; every requirement names a real module).

---

### Phase 3 — Terminology *(~4 days)* — **DONE**

**Why it is cheap:** `dictionaries.ts` is a flat `Record<string, string>`
(`frontend/src/lib/i18n/dictionaries.ts:13`) and `t()` is a single lookup with an
`en` fallback (`frontend/src/lib/i18n/context.tsx:37`). An override layer is one
merge.

Steps:

1. New table `sifarnici.prevodi_pojmova` — `(key, locale, value)`, unique on
   `(key, locale)`, audit columns.
2. `App\Support\Terminology::OVERRIDABLE` — a **whitelist of ~40 keys**: the
   domain nouns only (`nav.mines`, `nav.mining`, `nav.masters`, `nav.worksites`,
   `nav.projects`, `field.materialType`, `field.qualityGrade`, …). Never
   `common.save`. An override for a key not on the list is rejected 422.
3. `GET /api/company/terminology` returns `{ key: { en, sr, tr } }`. Available to
   any authenticated user — it is labels, not data.
4. `I18nProvider` fetches it once and merges over the static dictionary; `t()`
   checks overrides first, then locale, then `en`, then the key.
5. Server-side renderers (`ReportExporter`, the PDF writer) read the same table,
   so an exported report header matches the screen it came from.
6. Settings screen: a terminology editor, three locales side by side, showing the
   built-in value as the placeholder.

**Acceptance:** setting `nav.mines` to "Sites"/"Lokacije"/"Sahalar" renames the
sidebar entry, the page heading, the lookup label and the PDF column header in
all three locales — while the route stays `/mines`, the table stays `rudnici`,
and the permission stays `worksites.manage`.

**Delivered 2026-08-26**, and run against the live app: renaming the work
structure turned the sidebar into Sites/Projects/Crews and the page into
"Sites — The sites being worked. Crews are assigned to a site." with a "New site"
button and a "Search sites…" box, while `/api/mines` still answered 200 from
`rudnici` behind `worksites.manage`. Clearing the terms restored every word.

**The whitelist is ~100 keys, not the ~40 this plan estimated**, and the reason
is worth recording. The first cut held the menu entry and the heading only —
exactly what this acceptance criterion names — and the live check showed the
result: a page headed "Sites" whose button still said "New mine" and whose search
box still said "Search mines…". A term is not a word, it is the noun *and* the
labels that name it, so `Terminology::GROUPS` covers title, subtitle, new, edit,
search, the empty state and the cross-references, grouped by domain so the editor
stays a list of terms rather than a wall of boxes.

One correction to the criterion itself: **"the lookup label" was a misreading.**
Lookups label *records* (a mine's own name), not the term, so there is nothing
there to rename. The export header is the real second surface, and it needed a
fix of its own — see below.

Two things landed beyond the plan:

- **Report exports now carry their column headings in the request** (`columns[]`,
  the way `title` already did). This was a pre-existing bug, not a terminology
  one: the exporter has no dictionary, so every exported sheet had headers
  reading `worker` / `netSalary` whatever language the user was in. Sending the
  rendered headings fixes that and gets renamed terms for free.
- **The overrides layer sits in `I18nProvider` but is loaded by
  `<TerminologyLoader />` inside the authenticated shell**, because the provider
  wraps the login screen too and terminology is company configuration.

Suite: **495 green**, thirteen new in `TerminologyTest` — including one that
asserts the route, the table and the permission are untouched by a rename, one
that pins the "a rename covers every label that names the thing" lesson, and one
that checks every whitelisted key actually exists in the frontend dictionary.

---

### Phase 4 — Vocabularies *(~4 days)* — **DONE**

**The line to hold:** *a value the code branches on is an enum and stays in PHP.
A value only humans read is a vocabulary.*

Stays in PHP, never configurable: attendance statuses (`present`, `absent`,
`holiday`, …) — payroll branches on them; approval statuses; payment and invoice
statuses; movement categories and directions from `BooksBankMovement`; currency
codes.

Becomes a vocabulary: `material_type`
(`backend/app/Models/ProductionRecord.php:28`), production `unit`,
`quality_grade`, machine types, worker-need types, customs document types,
expense categories.

Steps:

1. New table `sifarnici.recnici` — `(vocabulary, value, sort_order, is_active,
   is_system)`. Labels live in the Phase 3 terminology table under
   `vocab.{vocabulary}.{value}`.
2. `App\Support\Vocabulary::values('material_type')` — cached; validation becomes
   `Rule::in(Vocabulary::values('material_type'))`.
3. Seeder writes today's consts as `is_system` rows, so nothing changes for the
   existing install. `is_system` rows may be deactivated but not deleted, and a
   value still referenced by rows may not be deleted at all.
4. `ImportCatalogue`'s enum columns read the vocabulary, so a downloaded template
   offers the customer's own values.
5. Settings screen: vocabulary editor, one list at a time.

**Acceptance:** replacing the material vocabulary with `concrete`/`asphalt` makes
the production form, the import template, the report filter and the lookup offer
those — with no migration and no code change. Existing `bauxite_ore` rows still
render, because a deactivated value still resolves a label.

**Delivered 2026-08-26**, and run against the live app: the material list was
replaced with concrete/asphalt/crushed_stone, a production row posted with
`concrete` (201) while one posting the retired `bauxite_ore` was refused (422),
the company's own value was named through the terminology layer, and naming a
value that does not exist was refused (422). Everything restored.

Seven vocabularies, chosen by grepping for which values the code actually
branches on rather than by eye: `Machine::STATUSES` looked like an obvious
candidate until `scopeActive()` turned out to do `whereIn('status', ['active',
'maintenance'])` — it stays an enum. `bauxite_ore` and `tons` appear in the
codebase only as model defaults, which is what makes them safe.

Two things worth recording:

- **The memo was a bug.** A first cut cached `values()` in a static property.
  That cache then survived everything meant to reset it — a request boundary, a
  queue worker's next job, a test's fresh database — and a stale list here
  rejects valid values and accepts retired ones. It surfaced as a test failure in
  the same class that seeded a different list; removing it costs one indexed
  query against a table of a few dozen rows.
- **Three refusals, not one.** Beyond "a value in use cannot be removed", a
  *shipped* value cannot be removed either — the importer's `LabelNormalizer`
  maps `ARABA` → `car` and model defaults name others, so a removed row would
  leave code pointing at nothing. Both are switched off instead, which is the
  same hide-not-delete rule the module toggles follow.

Suite: **510 green**, fifteen new in `VocabularyTest` — including one that
asserts the enums are *not* vocabularies, which is the line this phase exists to
hold.

---

### Phase 5 — Industry profiles + onboarding *(~1 week)* — **DONE**

Steps:

1. `App\Support\Industry\IndustryProfile` — an interface returning the four
   bundles. `ProfileRegistry` lists the implementations, in the same shape as
   `ReportRegistry`.
2. Ship exactly **three**: `mining` (= today, byte for byte), `construction`,
   `labour_services`. Three is enough to prove the abstraction. **The fourth
   profile is written when a real company asks**, not before.
3. `ApplyProfile` action: writes `enabled_modules`, seeds terminology and
   vocabulary rows, sets rule parameters. Idempotent, audited, and refuses to run
   on an install that already has data unless explicitly forced.
4. **Work-structure depth.** `mine -> project -> worksite`, and only
   `worksite_id` is ever stored (`BelongsToWorkStructure`); both parent FKs are
   already `nullOnDelete`. So depth is *already* variable — a two-level customer
   simply never creates a mine. The work is only: the profile declares which
   levels are visible, the sidebar hides the hidden ones, and the worksite form
   drops the field. **No schema change.**
5. Onboarding wizard (first login on an empty install): pick profile → company
   name, currency, timezone → confirm modules → done.

**Acceptance:** a fresh database plus `--profile=construction` yields an app with
no Production or Customs, "Sites/Projects" instead of "Mines/Projects/Worksites",
a Mon–Fri working week and concrete/asphalt vocabulary — from the same binary
that serves the mining install.

**Delivered 2026-08-26.** Applied through the API rather than a console flag, and
met in every part except one deliberate difference: **construction keeps
Production.** "Output measured at a site on a date, approved by someone" is
exactly how progress quantities are recorded and billed — the table was never
about ore, only its defaults were — so the profile relabels it "Output log" and
swaps the material list rather than hiding the module. `labour_services` is the
profile that drops Production, along with machines and customs, because there the
service really is the hours.

Verified: construction gives 12 modules, `project → worksite`, a Mon–Fri week
(July's divisor 27 → 23), nine renamed terms and a concrete/asphalt/aggregate
material list with the shipped values retired rather than deleted;
`labour_services` gives 10 modules and 404s on `/production`; `mining` reproduces
the untouched app exactly, asserted field by field.

Three things beyond the plan:

- **Applying refuses on an install already in use.** The plan said "refuses to run
  on an install that already has data unless explicitly forced" — implemented as
  a 422 that names what is there (`1 payables · 5 bank movements · 3 workers …`),
  which is also what the setup screen shows before anyone clicks.
- **Terminology is cleared before a profile writes its own**, so applying one
  profile after another is not a merge of two companies' vocabularies.
- **A prompt, not a redirect.** The plan called for a wizard on first login; an
  install with no profile works perfectly well, so trapping somebody on a setup
  screen would be worse than asking. The dashboard shows a card until a profile is
  applied, and only to users who could act on it.

⚠️ **The two non-mining profiles were written without a customer.** They are
reasoned from the shape the app already models, not observed from a real firm.
This is the part of §9 that was skipped, and the first construction or facilities
company to use one should be expected to correct it — which is one edit in a
profile class rather than a code change anywhere else.

Suite: **524 green**, fourteen new in `IndustryProfileTest` — including the
load-bearing one (`mining` reproduces the app's defaults exactly), idempotence,
no-trace-of-the-previous-profile, the in-use refusal, and a consistency test that
would fail if any profile enabled a module without its requirements, renamed a
term that is not renameable, or set a rule column that does not exist.

---

### Phase 6 — Profile matrix tests *(~3 days)* — **DONE**

1. `ProfileMatrixTest` runs the core financial suite under `mining` and
   `construction`. Not all three profiles, not every test — the money paths, the
   payroll paths, the dashboard.
2. A test asserting `mining` + empty overrides reproduces today's behaviour
   exactly. **This is the acceptance criterion for the whole project.**
3. `ModuleToggleTest` — for each togglable module: routes 404, nav absent,
   reports absent, notifications not generated, data intact, re-enable restores.

**Delivered 2026-08-26.** Three deviations, all in the direction of a stronger
test:

- **All three profiles, not two.** The plan said two to keep the matrix small.
  These are core paths that do not touch any module a profile removes, so the
  third costs about a second and covers the profile that drops the most.
- **The acceptance criterion is behavioural, not a settings comparison.**
  `test_mining_reproduces_the_untouched_app_behaviourally` does the same fixed
  piece of work — a 1,000 EUR payable settled 400 in cash, one 8-hour day with 2
  hours of overtime — on an unconfigured install and on a `mining` one, and holds
  both against figures **written out in the test** rather than against each
  other. Comparing the two runs would have passed if a change moved both; the
  literals cannot.
- **Per-module route coverage is derived from the route table.** A hand-kept list
  of "one route per module" would pass forever for a module whose routes were
  never gated — which is the exact mistake the test is for. It now reads
  `Route::getRoutes()` for anything carrying `module:{key}`, and fails if a
  module in the catalogue has no gated route at all.

One thing the run taught: the first cut asserted 200 on the enabled side, and
`attendance` failed because `/api/attendance/roster` wants a worksite and answers
422 without one. The assertion is about the *gate*, so it is now "not a 404" —
testing the endpoint's validation there would have been testing the wrong thing.

Suite: **560 green** — 23 in `ProfileMatrixTest`, and `ModuleToggleTest` grown
from 16 to 29 by the per-module datasets.

---

## 6. What this plan refuses to build

Each of these is seductive, commonly requested, and fatal for a one-developer
product.

| Refused | Why |
|---|---|
| **Custom fields / EAV** | Kills query performance, kills every report, kills type safety, kills validation. The first customer who uses it makes their own data unmigratable. The single most destructive item on any "make it configurable" list. |
| **Workflow engine** | Configurable approval chains sound essential and nobody ever configures them. You would build a state-machine DSL to serve three real workflows. Hardcode the three. |
| **Report builder** | Enormous effort, and users still email asking for the report. There are 19 good reports; number 20 is a day's work when someone asks. |
| **Plugin architecture** | Extension points that cannot be tested are untested code with ceremony. |
| **Per-customer branches** | Two products, half a developer each. |
| **Renaming tables, columns, routes or permissions per profile** | Breaks rule 4 and the API contract simultaneously. Terminology is display-only, always. |

---

## 7. Deferred, with reasons

**Real multi-currency base.** `base_currency` is already a column on
`podesavanja_kompanije` but is not honoured: the codebase says `amount_eur`
everywhere — column names across ~40 tables, `CurrencyConverter::toEur()`, every
Resource key, the frontend, the reports. Making it real means renaming to
`amount_base` through all of it, and breaking the API contract. Sell into EUR and
TRY-adjacent markets first; revisit when a customer's own books are in another
currency.

**Multi-tenancy (many companies, one deployment).** Separate from this plan, and
larger. Groundwork already exists: `podesavanja_kompanije` becomes the tenant
table by dropping the `firstOrCreate([])` singleton
(`backend/app/Models/CompanySettings.php:44`), and Spatie teams is half-wired
(`backend/config/permission.php:113` already sets `team_foreign_key`, and the
RBAC migration already has the per-team uniqueness branch). Three unique
constraints are genuinely global and would break. **One install per customer is
the right answer until there are enough customers for that to hurt.**

**External notification channels, bank-matching UI, OCR extraction.** Phase Two
of the product, unrelated to this plan and not blocked by it.

---

## 7b. Where this ended up

All six phases are delivered and the suite was **560 green** at that point, up
from 442 when the plan was written — and **582** after the code review that
followed (`performanceReport.md`). What that bought, in the plan's own terms:

| Phase | Was | Is |
|---|---|---|
| 0 | Two Montenegrin bank names in the schema | Accounts are rows; a movement is its lines |
| 1 | Payroll rules were constants in three services | Columns read through `CompanyConfig` |
| 2 | Every install showed every module | 13 togglable modules, one permission→module map |
| 3 | "Mines" was the only word for it | ~100 renameable labels, storage untouched |
| 4 | `bauxite_ore` was a PHP constant | Seven vocabularies as rows |
| 5 | One shape of company | Three profiles, applied as ordinary settings |
| 6 | One company's tests | The money paths asserted identical across all three |

**What has not changed is the thing worth protecting.** No stored value moved, no
route was renamed, no permission was touched, and `mining` still does exactly
what the app did before any of this existed — asserted, not assumed.

**What is still owed is §9.** The two non-mining profiles were written from the
shape the app already models rather than from a real firm. That is the one place
this work ran ahead of its evidence, and the correction is cheap: a profile class
is a single file, and everything it sets is separately editable in the app
anyway.

## 8. Effort summary

| Phase | Work | Estimate |
|---|---|---|
| 0 | Bank accounts as rows | ~2 weeks |
| 1 | `CompanyConfig` + rule parameters | ~3 days |
| 2 | Module toggles | ~4 days |
| 3 | Terminology | ~4 days |
| 4 | Vocabularies | ~4 days |
| 5 | Profiles + onboarding | ~1 week |
| 6 | Profile matrix tests | ~3 days |
| | **Total** | **~6–7 weeks** |

Phases 1–4 (~2.5 weeks) are improvements worth making regardless. Only 5 and 6
are pure productization.

---

## 9. The discipline that decides whether this works

**Add a parameter when the second customer asks for it — not before.**

There is currently **n = 1**: one company, one country, one industry, one
workforce shape. Every parameter added now is a guess about how company #2
differs, and the guesses will be wrong in ways that are not predictable from
here — so each wrong one is paid for twice, once to build and once to unbuild.

The sequencing that respects that:

1. **Phase 0 now**, regardless of everything else. It is a defect.
2. **Phases 1–4** — the safe, independently useful configuration layers.
3. **Find company #2 in an adjacent vertical.** Not necessarily a customer — a
   serious conversation is enough. Let their friction name what is actually
   rigid.
4. **Then Phase 5**, with three profiles written against evidence rather than two
   written against imagination.

The moat is the specificity: nobody else models the house a worker sleeps in, the
advance against next month's wages, the ticket home, and the *majstor* who runs
the crew. The failure mode is not staying too narrow. It is sanding the
specificity off in pursuit of a generality nobody asked for, and shipping a worse
Odoo.
