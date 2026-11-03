# AGENTS.md — POS Moderno

Legacy **PHP 8.2 (procedural) + AngularJS 1.6 + MySQL/MariaDB** point-of-sale app,
ported from the ITSolution24 / Codecanyon product. Source is obfuscated in places and
ships pre-built (vendored PHP libs in `_inc/vendor/`).

**Read this first — the repo has a plan, and the plan is not "keep patching the legacy".**
This codebase is the **system being replaced**. The authoritative reference is
**`Documentacion/mapa-funcional.md`** (1550 lines, Spanish, verified against the code):

| Question | Section |
|---|---|
| What every screen does; columns, endpoints, permission slugs | §3, §4, §5 |
| Verified defects, prioritized (94: P0 data corruption → P2) | §6 |
| What to reuse / rewrite / discard | §7 |
| Decisions taken 2026-10-30 — greenfield, Venezuela-default, atomic fiscal numbering, legacy replaced (not run in parallel) | §9, §10 |
| Phase 0 plan + env prerequisites (Laravel 11 + Inertia + React + Tailwind 4 + PostgreSQL 17) | §11 |

Do not re-derive what that document already pins down; Anexo A lists the exact commands
that re-verify its inventory. No CI, no test suite, no composer.json, no PHP dependency
manager, no lint config. Git: branch `main`, remote `origin/main`.

**Fase 0 is being built in a separate repo**: `D:\htdocs\pos-moderno-v2` (Laravel 12 +
Inertia 2 + React 19 + Tailwind 4 + Vite 6, PostgreSQL 17, Pest). It has its own
`AGENTS.md`. Work on the replacement goes there, not in this legacy tree.

## Setup

1. Import `BASE DE DATOS/pos_moderno.sql` (73 tables, seeded with demo data) into
   MySQL/MariaDB. **No installer** — root `install/` does not exist.
2. `cp .env.example .env` and fill it in. `.env` is git-ignored and holds every secret.
   `config.php` **is versioned** and reads everything through `_inc/config/env.php`
   (`env_get()`, `env_bool()`, `env_int()`); its literals are only fallbacks. Without
   `DB_PASS` it dies with a Spanish message.
3. Serve over Apache/PHP. `.htaccess` sets `max_execution_time 600`, needs
   `AllowOverride`. `storage/` must be **writable by the web server**.

`.env` keys that change behaviour: `DB_*`; `APP_URL`, `APP_SUBDIRECTORY` (derived from
the `APP_URL` path if unset — a wrong value breaks every asset and ajax URL),
`FILEMANAGERURL` (defaults to `APP_URL . '/storage/products'`), `APP_DEFAULT_LANGUAGE`;
toggles `DEMO`, `USECOMPILEDASSET` (JS only), `USECOMPILEDCSS` (CSS only), `INSTALLMENT`,
`HOOK`, `LOG`, `RTL`, `SYNCHRONIZATION`, `SYNCSERVERURL`; and the sync set `SYNC_ENABLED`,
`SYNC_CLIENTS`, `SYNC_CLIENT_ID`, `SYNC_CLIENT_SECRET`, `SYNC_LEGACY_AUTH`,
`SYNC_LEGACY_USERNAME`, `SYNC_LEGACY_PASSWORD`, `SYNC_LEGACY_PREVIOUS`,
`SYNC_REQUIRE_HTTPS`, `SYNC_ALLOW_LOCALHOST`, `SYNC_ALLOWED_IPS`, `SYNC_MAX_AGE`,
`SYNC_MAX_PAYLOAD`, `SYNC_MAX_STATEMENTS`.

- **The app only boots under a web SAPI.** In CLI `_init.php` skips creating the Session
  and `Store::__construct` dereferences a null session → fatal
  (`_inc/lib/store.php:34`). So `php cron.php <ACTION>` does **not** work — use HTTP
  (`wget http://host/pos_moderno/cron.php?action=DBBACKUP`).
- **Timezone** lives in `stores.preference['timezone']`. Changing it in Store → Edit
  rewrites line 3 of `_init.php` **in place** (`_inc/model/store.php:43-53`), so the file
  must stay writable. The dump ships `America/Lima`; Venezuela needs `America/Caracas`.
- Seed accounts (md5 in `users.password`): `cashier@gmail.com` / `tusolutionweb`,
  `salesman@gmail.com` / `tusolutionweb`. `USUARIOS/USUARIOS.txt` also lists
  `tusolutionweb@gmail.com` — **that account does not exist** in the dump.

## Architecture

```
index.php / admin/*.php      page views (guards, layout, ng-controller)
assets/itsolution24/angular/controllers/*.js   AngularJS 1.6 controllers (86)
assets/itsolution24/angular/modals/*.js         modal factories (101)
assets/itsolution24/angular/angularApp.js       module "angularApp"
_inc/<entity>.php            JSON handler + DataTables server-side endpoint (86)
_inc/model/<entity>.php      Model<Entity> classes (40) — all DB access
_inc/helper/<entity>.php     global helper functions (46)
_inc/template/*.php          server-rendered HTML fragments for forms/modals (117)
_inc/lib/*.php               Registry, Loader, Database, User, Session, Request, Language…
_init.php                    bootstrap: defines globals, autoloads, starts session
```

Globals after `include "_init.php"`: `$db $user $session $request $language $store
$document $Hooks $registry $loader $log $active_lang $deviceType`. Entry points:
`index.php` (login) → `admin/dashboard.php`; `admin/pos.php` (POS register).

**Request dispatch — there is no router.** Two coexisting conventions, both
client-selected:

| Mechanism | URL | Selector param | Notes |
|---|---|---|---|
| Entity handlers | `POST|GET {baseUrl}/_inc/<entity>.php` | **`action_type`** | `CREATE`/`UPDATE`/`DELETE` (POST), `EDIT`/`CREATE`/`DELETE` + id (GET → HTML fragment) |
| Shared ajax | `POST|GET {baseUrl}/_inc/ajax.php` | **`type`** | `PRODUCTIMAGES`, `QUOTATIONINFO`, `STOCKITEMS`, … (read `_inc/ajax.php`) |

- `action_type` for writes comes from a hidden form field; for reads a query string. The
  ID param name is per-entity (`category_id`, `product_id`, `p_id`, `ref_no`, …).
- Success → `{"msg": …, "id": N}`. Failure → HTTP **422** + `{"errorMsg": "…"}`. Match
  this contract exactly. `baseUrl` is emitted to JS by `admin/header.php` = `root_url()`.
- ⚠️ `type` means *selector* in `ajax.php` but *product name column* inside the
  `PURCHASEITEM`/`SELLINGITEM` POST bodies.
- **There is no CSRF token anywhere.** The session cookie is the only credential.
- Permission slugs are `read_foo`/`create_foo`/`update_foo`/`delete_foo`, serialized in
  `user_group.permission`. **Group id 1 is a superuser and bypasses every check.** Many
  handlers render the button by permission but never re-check it server-side
  (mapa-funcional.md §5.5, §6 P1 #29-#30).

**Adding an entity `foo` — the 5-file recipe.** Mirror the existing pattern exactly;
full detail in mapa-funcional.md §2.2.

1. `_inc/model/foo.php` — `class ModelFoo extends Model` with `addFoo`/`editFoo`/
   `deleteFoo`/`getFoo($id, $store_id=null)`/`getFoos($data=[], $store_id=null)`. Filters
   arrive in `$data` as `filter_*`, `sort`, `order`, `start`, `limit`. Loaded via
   `registry()->get('loader')->model('foo')`.
2. `_inc/helper/foo.php` — thin global wrappers (`get_the_foo($id)`, `get_the_foos()`).
3. `_inc/foo.php` — handler, **in this exact order**: `ob_start(); session_start(); include
   "../_init.php";` → `is_loggedin()` guard (422 `trans('error_login')`) →
   `user_group_id() != 1 && !has_permission('access','read_foo')` (422) → POST
   `action_type=CREATE|UPDATE|DELETE` with `has_permission('access','create_foo')` etc.,
   `validate_request_data()`, `$Hooks->do_action('Before_Create_Foo', $request)` → model →
   `After_Create_Foo` → JSON → GET `action_type=CREATE|EDIT|DELETE` includes
   `_inc/template/foo_*.php` and `exit()`s → **fallback (no `action_type`)** is DataTables
   server-side: `SSP::simple($request->get, $sql_details, $table, $primaryKey, $columns)`.
4. `admin/foo.php` — view: same two guards (redirect, not 422), `$document->setTitle`,
   `$document->addScript('../assets/itsolution24/angular/controllers/FooController.js')`,
   `header.php` + `left_sidebar.php`, body `ng-controller="FooController"`, empty
   `<table id="foo-foo-list">`, the create form, `footer.php` (which emits the
   `addScript()` tags **after** the Angular bundle).
5. `assets/itsolution24/angular/controllers/FooController.js` — `window.angularApp.controller
   ("FooController", ["$scope","API_URL","window","jQuery","$compile","$uibModal","$http",
   "$sce", …])`, DataTables `serverSide: true` with `aoColumns` matching the PHP `dt`.

**AngularJS conventions.** Module `angularApp`; `<html ng-app="angularApp">`. Constants:
`API_URL` (= `window.baseUrl`), `window`, `jQuery`. Controller name == file name ==
`ng-controller`. Modals are **factories returning a function**:
`window.angularApp.factory("FooModal", […, function(…){ return function(row){
$uibModal.open({…}); }; }])`; the body is server-rendered PHP fetched over `$http`,
injected via `$sce.trustAsHtml(response.data)` into `<div bind-html-compile="rawHtml">`;
`angularApp.run([…])` puts 9 of them on `$rootScope`. `angular/controllers/*.js` are
**not** in any webpack bundle (per-page `addScript()`). `$http` posts use
`Content-Type: application/x-www-form-urlencoded` (`angularApp.js` `$httpProvider`).

## Database

- **No table prefix.** 73 tables, unprefixed. Irregular names: `categorys` (not
  `categories`), `expense_categorys`, `currency` (singular), `units`, `boxes`, `pmethods`.
- Multi-store joins: every `*_to_store` table carries `status` (1 = active) and
  `sort_order`. The master table holds identity; the join holds store-specific state
  (price, stock, balance, due). Active store = `$_SESSION['store_id']` (default 1),
  switched by `store_select.php`. **Preserve that scoping in any new query.**
- Invoice data splits three ways: `selling_info` (header) / `selling_item` (lines) /
  `selling_price` (money). Purchases mirror: `purchase_info` / `purchase_item` /
  `purchase_price`.
- **Prices and stock live in `product_to_store`, not `products`.**
- Preferences are **serialized PHP blobs in longtext columns**: `stores.preference`,
  `users.preference`, `user_group.permission`. Use `unserialize()`/`serialize()`, never
  JSON. Money is `decimal(25,4)`.
- The dump seeds 5 stores, 5 users, 3 user groups, 2,330 `language_translations` rows
  (1,214 English / 866 Spanish) and transaction history — demo data, not a schema-only dump.

**Listing filters — the pattern to copy.** One SQL string plus an `$args` array whose
order must match the `?` order (base placeholders first, then filters in append order):
append `" AND x.y LIKE ?"` to the SQL and push `$data['filter_name'] . '%'` onto `$args`,
then `$statement = $this->db->prepare($sql); $statement->execute($args);`.
**Values** → `?` placeholder. **Identifiers** (column names) → explicit allowlist
(`in_array($data['sort'], $sort_data)`); the `else` branch of an `ORDER BY` allowlist is a
hardcoded fallback that always runs, so a wrong column breaks the whole listing. The
frontend never sends `filter_*` — templates call helpers with literal values, so a broken
filter shows up as a SQL error in a dropdown, not as a wrong result.

## Assets & build

**In practice you almost never need a build.** `node_modules/` is not installed, and the
legacy toolchain is Node-8-era (`laravel-mix 2.1.14`, `webpack 3.12.0`, `node-sass 4.9.3`,
lockfile v1) while the machine runs Node 24 — `npm install` / `npm run dev` on this stack
is not expected to succeed. Verify before relying on it.

| Change | Needs a build? |
|---|---|
| `assets/itsolution24/css/*.css` | **No** — CSS is served from source by default |
| `assets/itsolution24/angular/controllers/*.js` | **No** — loaded per page via `addScript()` |
| `assets/itsolution24/js/*.js`, `angular/modals/*.js` | Yes — bundled into `jsmin/`, `angularmin/` |

**⚠️ CSS: two delivery modes, and the bundle is stale.** `mix.styles(...)` is **commented
out** in `webpack.mix.js`, so the CSS bundle is never rebuilt. `cssmin/main.css` is a
frozen snapshot that no longer matches `css/theme.css` — editing it is pointless.
`USECOMPILEDCSS` (default **false**) decides: `false` → every stylesheet from source;
`true` → only `cssmin/main.css` (413 KB, stale). So **editing `assets/itsolution24/css/
*.css` takes effect immediately, with no build**.

- `USECOMPILEDASSET` is a **separate** flag and governs only the **JS**. Do not flip it
  to "fix" the CSS: its `else` branch loads `angularmin/angular.js` (which already
  contains `angularApp.js`) *and* `angular/angularApp.js`, registering the Angular module
  twice (`admin/header.php:223-229`).
- `admin/preview_receipt.php:18` loads `cssmin/main.css` unconditionally — no fallback.
  `admin/pos.php` does **not** load `modern.css`: the POS screen is pixel-tuned for
  touchscreens and receipt printers, and `modern.css` is skipped in `@media print`.
- `webpack.mix.js` lists 70 of the 101 files in `angular/modals/`. Modals missing from the
  bundle need an explicit `<script>` tag — see how `admin/pos.php:584` loads
  `HoldingOrderModal.js`.
- `mix-manifest.json` lists only the 3 CSS files and no JS — a stale leftover. `gulpfile.js`
  only has `svgstore`/`svgmstore` (SVG sprite → `img/iconmin/`).
- `npm run lint` is **broken**: it targets `resources/assets/...`, a directory that does
  not exist, and `eslint`/`stylelint` are not even in `devDependencies`.

**The modern layer.** `assets/itsolution24/css/modern.css` is the app's own visual
refresh on top of Bootstrap 3.3.5 + AdminLTE, loaded **last** in `<head>` so it wins the
cascade without `!important`. It defines the design tokens (prefixed `--m-`: palette,
radii, shadows, focus rings) and deliberately **does not touch geometry** (widths, grid,
POS layout) — do not add layout rules there: the POS and the printed receipts are tuned to
the pixel. The 5 skins keep their identity; the active one comes from the user preference
`base_color` (`skin-black` by default).

## PHP 8.2 runtime

Migrated from 5.6 with `error_reporting(-1)` in `_init.php`, so **every** deprecation
surfaces on screen.

- **Declare every property on the class.** PHP 8.2 deprecates dynamic property creation.
  Model subclasses are exempt: base `Model` defines `__get`/`__set` that proxy to the
  registry, so `$this->db` in a model is a registry lookup, not a property — and a typo
  silently yields `null`.
- **Only `Database::prepare()` needs `#[\ReturnTypeWillChange]`.** `execute()`, `fetch()`,
  `fetchAll()`, `rowCount()` do **not** warn — don't add attributes. The attribute
  suppresses a deprecation; it is **not** a licence to change behaviour. For genuinely
  dynamic properties use `#[\AllowDynamicProperties]` (vendored code only).
- **DB columns are nullable.** `trim($row['col'])`, `strlen()`, `strtoupper()`,
  `mb_strlen()` on a nullable column raise "Passing null to parameter". Use
  `$row['col'] ?? ''`. `language_translations.lang_value` is NULL for every untranslated
  key; `customers.customer_country`/`customer_state` are nullable.
- **`fetch()` returns `false` when there is no row.** Writing `$row['x'] = …` on it raises
  "Automatic conversion of false to array". Initialise to `array()` first.
- **`FILTER_SANITIZE_STRIPPED` is deprecated** (PHP 8.1). `validateString()` re-implements
  it as `strip_tags` + quote escaping to avoid double-encoding entities `Request` already
  escaped. Do not "simplify" it to `htmlspecialchars()`.
- Dead files still carry dynamic properties but are **never loaded**: `_inc/lib/xuser.php`
  (duplicate `User` class — would fatal on redeclare), `_inc/lib/xparser.php`,
  `assets/edit-area/edit_area_compressor.php` (orphaned; the app uses TinyMCE).

## Landmines

1. **`_init.php` is obfuscated** — line 7 is a ~26 KB single statement of hex/octal
   escapes and `goto` labels. Do not read or rewrite it. Extend via `_inc/helper/*` or
   `_inc/lib/*`. It defines `ROOT`, `DIR_*`, `ROOT_URL`, `PROTOCOL`, `ENVIRONMENT`,
   `APPNAME`, `APPID` and the `db()`/`registry()` accessors, then builds the registry.
2. **`Database::prepare()` returns the `Database` object, not a `PDOStatement`**
   (`_inc/lib/database.php`). Only **one statement can be live at a time** — a second
   `prepare()` before `execute()`/`fetch()` clobbers the first. `execute()` returns
   **null**, not a row count. Canonical form:
   `$statement = db()->prepare($sql); $statement->execute($args); $row = $statement->fetch(PDO::FETCH_ASSOC);`
   The statement is **not** executed until you call `execute()`; `db()->prepare($sql)->
   fetchAll(...)` returns an empty set.
3. **`Loader::library()` contains `print_r($file);die;`** — never call it. Use
   `registry()->get('loader')->model(...)` / `->helper(...)`.
4. **`trans('missing_key')` writes to the database.** `Language::get()` INSERTs the key
   into `language_translations` with a NULL value, then returns a title-cased version of
   the key. Missing keys render as text, never as empty — check the table, not the code.
5. **`set_preference()` does not exist.** Use `ModelStore::editPreference($store_id,
   $preference)` — but it **replaces the whole preference blob**, so any key not present
   in the form is lost.
6. **`language/` and `DIR_LANGUAGE` are dead** — translations live in the `languages` /
   `language_translations` tables only. `INSTALLED` in `config.php` is never read by any
   code; it is not an install guard.
7. **`update.php`, `refresh.php` and `revalidate.php` were removed** — all three included
   `install/_init.php`, and `install/` does not exist (fatal on load). `refresh.php` also
   overwrote `_init.php`, `network.php` and `ecnesil.php` with content downloaded from the
   vendor server (supply-chain backdoor). The license lives in `_inc/config/purchase.php`.
8. **`Request` HTML-escapes every input** (`htmlspecialchars(..., ENT_COMPAT, 'UTF-8')`)
   on both keys and values. Re-escaping double-escapes. Store `&amp;` in the DB, not `&`.
9. **Every handler in `_inc/` must start with an `is_loggedin()` guard** returning
   `422 {"errorMsg": trans('error_login')}`. `_inc/ajax.php` was the only one missing it
   and it leaked the whole product catalogue with costs and margins.
10. **POS checkout is not transactional.** `_inc/place_order.php` →
    `ModelInvoice::createInvoice()` (`_inc/model/invoice.php`) writes `purchase_item`
    (FIFO stock decrement), `selling_item`, `product_to_store`, `selling_info`,
    `selling_price`, `payments`, `sell_logs` and optional `customer_transactions` /
    `installment_*` — with **no `BEGIN`/`COMMIT`/rollback**. A mid-way failure leaves
    partial writes. This is the most severe structural defect in the system.
11. **`storage/qrcode.png` is a single shared file** overwritten on every render —
    concurrent receipt/quotation rendering races. `admin/error_log` and
    `storage/logs/*.txt` are runtime logs, not source.

### SQL injection — the pattern, and why it is not optional

`Request::clean()` escapes with `ENT_COMPAT`, so **a single quote is NOT escaped**: a
quote-based payload really does break out of the string. Every request-derived value
that reaches a statement must be handled this way:

- **Values** → `?` placeholder, bound in the `execute(array(...))` call, in the exact
  order the placeholders appear.
- **Identifiers** (column and table names) → never interpolated: validate against a
  **whitelist** with `safe_column($allowed, $value, $default)`
  (`_inc/helper/common.php`), then interpolate the validated result.
- **`LIMIT`/`OFFSET`** → MySQL accepts no placeholder there: cast **and** bound
  (`max(1, min((int)$x, 1000))`) before interpolating.
- **Lists of ids** (`NOT IN (...)`) → accept only positive integers; a hostile entry is
  discarded, so the statement degrades to a no-op instead of executing.

The 2026-11 sweep fixed 42 files: the POS search, the DataTables `WHERE` clauses, the
balance and stock `UPDATE`s, the report filters, `admin/reset.php` (which mass-deleted
31 of 31 rows) and the `LIMIT`s that were interpolated from `extract($data)` without a
cast. Verified against the live database: a hostile payload used to **corrupt a customer
balance silently** (697 → 1.0000) and to **mass-delete** on `reset.php`; both now leave
the data untouched.

Still to watch: the `date_range_*` helpers in `_inc/helper/common.php` rebuild their
`DAY()/MONTH()/YEAR()` values with `date()`, so they are digits-only — but they are
interpolated, and a future change there would reopen the hole.

## Synchronization (offline → online)

`sync.php` (server) + `ModelCron::PushSqlToRemoteServer()` (client) +
`_inc/helper/sync.php` (shared signing code).

- **Protocol v2 (signed)** — `POST sync.php` with `username`, `action`, `data`,
  `timestamp`, `nonce`, `signature`: `canonical = username \n action \n timestamp \n nonce
  \n sha256(data)`, `signature = hash_hmac('sha256', canonical, client_secret)`. The
  server verifies in order: known client → timestamp within `SYNC_MAX_AGE` →
  `hash_equals` signature → nonce not yet used. Nonces live in `storage/sync-nonces/`
  (one file each); `fopen(…, 'x')` makes the create atomic.
- **Protocol v1 (legacy)** — plain `username`/`password`, accepted only while
  `SYNC_LEGACY_AUTH=true`, so terminals not yet updated keep syncing.
- **Never change the v2 payload shape without updating both sides** — the helper is
  shared, so the signature is computed and verified by the same code. Decision 3 in
  mapa-funcional.md §9 freezes this protocol as a contract for the replacement system.
- **`LOG=true` is required for sync to work at all.** `Log::__construct` only opens its
  handle when `LOG` is on, so `storage/logs/sql.txt` is never written otherwise — and
  there is nothing to push. `SYNCHRONIZATION=true` alone is not enough.
- The client only ever produces INSERT / UPDATE / DELETE (that is all `Database::execute()`
  logs). The server rejects anything else and rejects stacked statements;
  `pdo_start()` sets `ATTR_EMULATE_PREPARES => false` so MySQL itself refuses
  multi-statement queries. The whole batch runs in **one transaction**.
- `sync.php` bypasses the app bootstrap (no `_init.php`, no session) and executes SQL sent
  by remote POS. It is the most dangerous endpoint in the repo. Never expose it publicly
  without the client allowlist (`SYNC_CLIENTS`) and `SYNC_REQUIRE_HTTPS`.

## i18n (translations)

- `trans('key')` → `_inc/lib/language.php` → the `language_translations` row for the
  **active language** (`lang_id`). If the row is missing or its `lang_value` is NULL, the
  key is humanized and shown as English-looking text.
- The active language comes from the **user preference** (`users.preference['language']`);
  for an anonymous visitor (login screen) it falls back to `APP_DEFAULT_LANGUAGE`.
  `Document::langTag()` maps the language code to `<html lang>`; it accepts the code
  (`es`) and the name (`spanish`).
- **Seed data is not a translation.** The vendor shipped expense categories, income
  sources, payment methods, brands, units, boxes, tax rates and currency names in
  English. Those are *rows in the database*, not keys in `language_translations`.
- Adding a new `trans()` key also means adding a row for `lang_id = 7` (es) — that is
  the `Spanish` row in the `languages` table. The Spanish translations are **UPPERCASE
  and without accents** (`update/2026-10-29-espanol.sql` states this style in its
  header). Match it.
- Migrations live in `update/` as plain SQL (`2026-10-29-banners.sql`,
  `2026-10-29-espanol.sql`, `2026-10-29-espanol-datos.sql`, plus the inert v2→v3
  `update-3.0.sql`/`x3.0.sql`), applied via phpMyAdmin or `mysql`.

## Operations

- **Cron** — `cron.php?action=<ACTION>` over HTTP only (CLI fatals). Actions:
  `CHECKFORUPDATE`, `DBBACKUP`, `SENDCUSTOMERBIRTHDAYSMS`, `PUSHSQLTOREMOTESERVER`,
  `RUNALLJOBS`. Suggested schedule: `0 1 * * * wget -qO- http://<host>/<subdir>/cron.php`.
  No-op when `DEMO = true`.
- **Backups** — `ModelCron::DBBackup()` writes
  `storage/backups/mpos-backup-on-YYYY-MM-DD.txt` (excludes `users`, `user_group`,
  `user_to_store`), auto-prunes >30 days. Restore via `admin/backup_restore.php` +
  `_inc/restore.php`.
- **Maintenance mode** — create `<ROOT>/.maintenance` to trigger `maintenance.php`;
  delete it to resume.
- **Hooks** — `functions.php` is the hook registry; `hook_action_auto_callback()` writes
  to `storage/activity-logs/YYYY-MM-DD.txt`. Requires `HOOK=true` (and `LOG=true` for the
  SQL log). The full list of hook names is the comment catalog at the bottom of
  `functions.php`.

## Verifying a change

There is **no test suite**. Verify manually:

1. `php -l <changed file>` for every touched PHP file.
2. Log in, exercise the changed screen, check `storage/logs/log.txt` and the browser console.
3. For DB-affecting changes, confirm rows in the relevant `*_to_store` join table and
   that the serialized preference blob still round-trips through `unserialize()`.
4. For POS/stock changes, verify the `purchase_item` FIFO decrement and that
   `product_to_store.quantity_in_stock` matches the sum of active lots — no transaction
   protects this.
5. **Watch the deprecation output.** `_init.php` sets `error_reporting(-1)` in development,
   so PHP 8.2 notices appear inline. A clean dashboard is not proof the rest of the app is
   clean — each page exercises different classes. The fastest full sweep is to log in and
   hit every `admin/*.php` page while reading the PHP server's stderr.

## Repo conventions

- **Commit messages are Spanish with a `Tipo:` prefix** — `Documentación:`, `Corrección:`,
  `Seguridad:`, `Configuración:`, `Apariencia:`, `Limpieza:`, `Ajuste:`. Match that style;
  the first commit is `Primer commit: …`.
- **`.codegraph/` is indexed** — use CodeGraph (`codegraph_explore`) for structural
  questions (call paths, blast radius, where X lives) before grepping or reading files
  broadly. The index lags writes by ~1 s.
- **`.atl/skill-registry.md`** is the project skill registry (auto-generated; refresh with
  `gentle-ai skill-registry refresh --force`). Delegators read it to pick matching
  `SKILL.md` paths and pass them to subagents.
- `storage/` and `.env` are git-ignored; `config.php` and `update/*.sql` are versioned.
