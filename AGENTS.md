# AGENTS.md — POS Moderno

Legacy **PHP 8.2 (procedural) + AngularJS 1.6 + MySQL/MariaDB** point-of-sale app.
Vendor: ITSolution24 (Codecanyon). Source is obfuscated in places and shipped pre-built.

Git repo (branch `main`), no CI, no test suite, no composer.json, no lint config.
Vendored PHP libs live in `_inc/vendor/`.

---

## Setup

1. Import `BASE DE DATOS/pos_moderno.sql` into a MySQL/MariaDB DB (phpMyAdmin). **There is no installer** — `install/` does not exist.
2. Copy `.env.example` to `.env` and fill it in. `.env` is **git-ignored**; it holds the secrets.
3. `config.php` is **required** and also git-ignored. It no longer hardcodes anything meaningful — it reads every value from `.env` via `_inc/config/env.php` (`env_get()`, `env_bool()`, `env_int()`), with the old literals kept only as fallbacks. Supported keys: `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_PORT`, `APP_URL`, `APP_SUBDIRECTORY`, `APP_TIMEZONE`, `APP_DEFAULT_LANGUAGE`, `FILEMANAGERURL`, `DEMO`, `USECOMPILEDASSET`, `USECOMPILEDCSS`, `INSTALLMENT`, `HOOK`, `LOG`, `SYNCHRONIZATION`, `RTL`, `SYNCSERVERURL`.
   - `SUBDIRECTORY` is derived from the path of `APP_URL` unless `APP_SUBDIRECTORY` is set explicitly.
   - `FILEMANAGERURL` defaults to `APP_URL . '/storage/products'`.
4. Serve over Apache/PHP (`.htaccess` sets `max_execution_time 600`; needs `AllowOverride`).
5. `storage/` must be **writable by the web server** (uploads, logs, backups, activity logs).

**Timezone:** the effective timezone is the **store preference** (`stores.preference['timezone']`), not the app-level one — `_init.php` prefers the store value over its own default. Set it per store (Store → Edit) or directly in the `stores` table. The SQL dump ships `America/Lima`; for a Venezuela deployment this must be `America/Caracas`.

Seed accounts from the SQL dump: `cashier@gmail.com` / `tusolutionweb` and `salesman@gmail.com` / `tusolutionweb` (passwords are stored as md5 in `users.password`).
(`USUARIOS/USUARIOS.txt` lists `tusolutionweb@gmail.com` — that account does **not** exist in the dump.)

`Documentacion/index.html` is the full Spanish manual, including install steps. `README.md` is a 9-line vendor stub.

**The app only boots under a web SAPI.** In CLI, `_init.php` skips creating the `Session`, and `Store::__construct` then dereferences a null session → fatal. That also means `php cron.php <ACTION>` does **not** work; use the HTTP form (`wget http://host/pos_moderno/cron.php?action=DBBACKUP`).

---

## Architecture

```
index.php / admin/*.php      page views (guards, layout, ng-controller)
   └─ assets/itsolution24/angular/controllers/*.js   AngularJS 1.6 controllers (86)
assets/itsolution24/angular/modals/*.js             modal factories (101)
assets/itsolution24/angular/angularApp.js           module "angularApp"
_inc/<entity>.php            JSON handler + DataTables server-side endpoint (67 files)
_inc/model/<entity>.php      Model<Entity> classes (45) — all DB access
_inc/helper/<entity>.php     global helper functions (52)
_inc/template/*.php          server-rendered HTML fragments for forms/modals (117)
_inc/lib/*.php               Registry, Loader, Database, User, Session, Request, Language…
_init.php                    bootstrap: defines globals, autoloads, starts session
```

Globals available after `include "_init.php"`: `$db $user $session $request $language $store $document $Hooks $registry $loader $log $active_lang $deviceType`.

Entry points: `index.php` (login) → `admin/dashboard.php`; `admin/pos.php` (POS register, standalone layout).

### Request dispatch — there is no router

Two coexisting conventions, both client-selected:

| Mechanism | URL | Selector param | Notes |
|---|---|---|---|
| Entity handlers | `POST|GET {baseUrl}/_inc/<entity>.php` | **`action_type`** | `CREATE`/`UPDATE`/`DELETE` (POST), `EDIT`/`CREATE`/`DELETE` + id (GET → HTML fragment) |
| Shared ajax | `POST|GET {baseUrl}/_inc/ajax.php` | **`type`** | `PRODUCTIMAGES`, `QUOTATIONINFO`, `STOCKITEMS`, … (read `_inc/ajax.php`) |

- `action_type` for writes comes from a hidden form field; for reads it is a query string.
- ID param name is per-entity (`category_id`, `product_id`, `p_id`, `ref_no`, …).
- Success → `{"msg": …, "id": N}`. Failure → HTTP **422** + `{"errorMsg": "…"}`. Match this contract.
- `baseUrl` is emitted to JS by `admin/header.php` and equals `root_url()` (derived from `SUBDIRECTORY`).
- ⚠️ `type` means *selector* in `ajax.php` but *product name column* inside `PURCHASEITEM`/`SELLINGITEM` POST bodies.

### Adding a feature for entity `foo` — the 5-file recipe

The pattern is rigid; mirror it exactly.

1. **`_inc/model/foo.php`** — `class ModelFoo extends Model`. Methods: `addFoo` / `editFoo` / `deleteFoo` / `getFoo($id, $store_id=null)` / `getFoos($data=[], $store_id=null)`. Filter args arrive in `$data` as `filter_*`, `sort`, `order`, `start`, `limit`. Loaded via `registry()->get('loader')->model('foo')`.
2. **`_inc/helper/foo.php`** — thin global wrappers over the model (`get_the_foo($id)`, `get_the_foos()`, `get_foos_item()`…).
3. **`_inc/foo.php`** — the handler, in this exact order:
   - `ob_start(); session_start(); include "../_init.php";`
   - `is_loggedin()` guard → 422 `trans('error_login')`
   - `user_group_id() != 1 && !has_permission('access','read_foo')` → 422 `trans('error_read_permission')`
   - `POST action_type=CREATE|UPDATE|DELETE` → `has_permission('access','create_foo')` etc., local `validate_request_data()`, `$Hooks->do_action('Before_Create_Foo', $request)` → model call → `$Hooks->do_action('After_Create_Foo', $entity)` → JSON
   - `GET action_type=CREATE|EDIT|DELETE` → `include '_inc/template/foo_create_form.php'` (etc.) and `exit()`
   - **fallback (no `action_type`)** → DataTables server-side: build `$table`/`$primaryKey`/`$columns` and `echo SSP::simple($request->get, $sql_details, $table, $primaryKey, $columns)`
4. **`admin/foo.php`** — view: same two guards (redirect, not 422), `$document->setTitle(...)`, `$document->addScript('../assets/itsolution24/angular/controllers/FooController.js')`, `include header.php; include left_sidebar.php;`, body `<div class="content-wrapper" ng-controller="FooController">`, an empty `<table id="foo-foo-list">` shell, `include '../_inc/template/foo_create_form.php'`, `include footer.php`.
   - `footer.php` emits the `addScript()` tags **after** the Angular bundle.
5. **`assets/itsolution24/angular/controllers/FooController.js`** — `window.angularApp.controller("FooController", ["$scope","API_URL","window","jQuery","$compile","$uibModal","$http","$sce", function(...){...}])`. DataTables: `"ajax": API_URL + "/_inc/foo.php?from=" + $from + "&to=" + $to`, `"serverSide": true`, `aoColumns` matching the PHP `dt` names.

Permission slugs are `read_foo` / `create_foo` / `update_foo` / `delete_foo`, stored serialized in `user_group.permission`.

### AngularJS conventions

- Module is `angularApp`; `<html ng-app="angularApp">`. Constants: `API_URL` (= `window.baseUrl`), `window`, `jQuery`.
- Controller name == file name == `ng-controller` value.
- Modals are **factories returning a function**: `window.angularApp.factory("FooModal", [..., function(...){ return function(row){ $uibModal.open({...}); }; }])`. The modal body is server-rendered PHP fetched over `$http` and injected via `$sce.trustAsHtml(response.data)` into `<div bind-html-compile="rawHtml">`.
- `angularApp.run([...])` injects ~9 modal factories onto `$rootScope`.
- `assets/itsolution24/angular/controllers/*.js` are **not** in any webpack bundle — they load per page via `$document->addScript()`.
- `$http` posts use `Content-Type: application/x-www-form-urlencoded` (set in `angularApp.js` `$httpProvider`).

---

## Database

- **No table prefix.** 73 tables, unprefixed. Watch the irregular names: `categorys` (not `categories`), `expense_categorys`, `currency` (singular), `units`, `boxes`, `pmethods`.
- Multi-store joins: every `*_to_store` table (`product_to_store`, `customer_to_store`, `user_to_store`, …) carries `status` (1 = active) and `sort_order`. Master table holds identity; the join holds store-specific state.
- Invoice data is split three ways: `selling_info` (header) / `selling_item` (lines) / `selling_price` (money). Purchases mirror: `purchase_info` / `purchase_item` / `purchase_price`.
- Preferences are **serialized PHP blobs in longtext columns**: `stores.preference`, `users.preference`, `user_group.permission`. Use `unserialize()`/`serialize()`, not JSON.
- Money is `decimal(25,4)`.
- The dump seeds 5 stores, 5 users, 3 user groups, ~2,800 `language_translations` rows, and transaction history — it is demo data, not a schema-only dump.

---

## Build & assets

`npm run dev` (watch) / `npm run production` → laravel-mix 2.x webpack bundles.

`node_modules/` is **not** checked in. Locked versions are `laravel-mix 2.1.14` + `node-sass 4.9.3` (`package-lock.json`). node-sass 4.x ships prebuilt binaries only for legacy Node ABIs — use **Node 8 or older**; on a modern Node the install or the sass build step fails. `npm install` on npm 7+ may also need `--legacy-peer-deps` (the legacy `laravel-elixir` / `natives` deps are vestigial — `webpack.mix.js` only requires `laravel-mix`).

Generated output, all checked in:

| Output | Sources |
|---|---|
| `assets/itsolution24/jsmin/{main,pos,login}.js` | `assets/itsolution24/js/**` + `angularmin/*.js` |
| `assets/itsolution24/angularmin/{angular,filemanager,modal}.js` | `angular/lib/**`, `angular/filemanager/js/**`, `angular/modals/*.js` |
| `assets/itsolution24/cssmin/{main,pos,login}.css` | **NOT BUILT — see below** |

### ⚠️ CSS: two delivery modes, and the bundle is stale

`mix.styles(...)` is commented out in `webpack.mix.js`, so **the CSS bundle is never rebuilt** — `cssmin/main.css` is a frozen snapshot from whenever the vendor last built it, and it no longer matches `css/theme.css` (it still carries AdminLTE rules the current theme dropped). Editing it is pointless.

`USECOMPILEDCSS` (in `.env`, default **false**) decides how the CSS is served:

| `USECOMPILEDCSS` | What the pages load |
|---|---|
| `false` (default) | every stylesheet **from source**: `theme.css`, `main.css`, `responsive.css`, `print.css`, `rtl.css`, the 5 `skins/skin-*.css`, `pos/*`, plus the vendor sheets individually |
| `true` | only `cssmin/main.css` (413 KB, stale) |

So **editing `assets/itsolution24/css/*.css` takes effect immediately, with no build**. The flag exists because the alternative is the stale bundle, not because the source needs compiling.

- `USECOMPILEDASSET` is a **separate** flag and governs only the **JS**. Do not flip it to "fix" the CSS: its `else` branch loads `angularmin/angular.js` (which already contains `angularApp.js`) *and* `angular/angularApp.js`, registering the Angular module twice.
- `admin/preview_receipt.php` loads `cssmin/main.css` unconditionally and has no individual-file fallback — it keeps the stale CSS by design (it is the print preview).
- `admin/pos.php` does **not** load `modern.css`: the POS screen is pixel-tuned for touchscreens and receipt printers. `modern.css` is also skipped in `@media print`.
- The POS screen (`css/pos/*`) is pixel-tuned for touchscreens and receipt printers, and `css/print.css` drives the printed receipt. Changes there need care.
- `webpack.mix.js` lists 70 of the 101 files in `angular/modals/`. Modals missing from the bundle must be loaded with an explicit `<script>` tag — see how `admin/pos.php` loads `HoldingOrderModal.js` etc. **Editing `angular/controllers/*.js` needs no build**: they are loaded per page via `$document->addScript()`. Only `assets/itsolution24/js/*.js` and `angular/modals/*.js` are bundled.
- `mix-manifest.json` lists only the 3 CSS files and no JS — a stale CSS-era leftover; ignore it.
- `gulpfile.js` only has `svgstore` / `svgmstore` (SVG sprite → `assets/itsolution24/img/iconmin/`). Nothing else.
- `package.json`'s `lint` scripts target `resources/assets/...`, a directory that **does not exist** — and `eslint`/`stylelint` are not even in `devDependencies`. `npm run lint` is broken. There is no lint or test config in the repo.

### The modern layer

`assets/itsolution24/css/modern.css` is the app's own visual refresh on top of Bootstrap 3.3.5 + AdminLTE. It is loaded **last** in the `<head>` so it wins the cascade without `!important`.

- It defines the design tokens (prefixed `--m-`): palette, radii, shadows, focus rings. `theme.css` declares only three custom properties, so the app had no token layer before.
- It deliberately **does not touch geometry** (widths, grid, the POS layout). Do not add layout rules there: the POS and the printed receipts are tuned to the pixel.
- The 5 skins keep their identity but with refined tones; the active one comes from the user preference `base_color` (`skin-black` by default).




---

## PHP 8.2 runtime

The app runs on **PHP 8.2** (dev). It was migrated from 5.6 with `error_reporting(-1)` in `_init.php`, so **every** deprecation surfaces on screen. Rules that keep it clean:

- **Declare every property on the class.** PHP 8.2 deprecates dynamic property creation (`$this->foo = …` where `foo` is not declared). Model subclasses are exempt: the base `Model` defines `__get`/`__set` that proxy to the registry, so `$this->db` in a model is a registry lookup, not a property.
- **Only `Database::prepare()` needs `#[\ReturnTypeWillChange]`.** Its signature is intentionally incompatible with `PDO::prepare()` (it returns the wrapper — see Landmines #2). `Database::lastInsertId()` is properly typed as `lastInsertId(?string $seqname = null): string|false`. `execute()`, `fetch()`, `fetchAll()` and `rowCount()` do **not** warn — don't add attributes to them. The vendored `SpreadsheetReader` needs the same attribute on its `Iterator`/`Countable`/`SeekableIterator` methods.
- `#[\ReturnTypeWillChange]` suppresses a deprecation; it is **not** a licence to change behaviour. For a class with genuinely dynamic properties, `#[\AllowDynamicProperties]` is the correct escape hatch (used only in vendored code).
- **DB columns are nullable.** `trim($row['col'])`, `strlen($row['col'])`, `strtoupper($col)`, `mb_strlen($col)` on a nullable column raise "Passing null to parameter". Use `$row['col'] ?? ''` or cast to `(string)`. `language_translations.lang_value` is NULL for every untranslated key, and `customers.customer_country` / `customer_state` are nullable — these are the most common sources.
- **`fetch()` returns `false` when there is no row.** Writing `$row['x'] = …` on it raises "Automatic conversion of false to array". Initialise to `array()` first, preserving the original result shape.
- **`FILTER_SANITIZE_STRIPPED` is deprecated** (PHP 8.1). `validateString()` re-implements it as `strip_tags` + quote escaping, which avoids double-encoding entities that `Request` already escaped. Do not "simplify" it to `htmlspecialchars()` — that double-escapes `&amp;`.
- Dead files still carry dynamic properties but are **never loaded** by the app: `_inc/lib/xuser.php` (a duplicate `User` class — would fatal on redeclare), `_inc/lib/xparser.php`, `assets/edit-area/edit_area_compressor.php` (orphaned; the app uses TinyMCE). Leave them alone unless you are deliberately reviving them.

---

## Landmines

1. **`_init.php` is obfuscated** — line 7 is a ~26 KB single statement of hex/octal escapes and `goto` labels. Do not try to read or rewrite it. Extend via `_inc/helper/*` or `_inc/lib/*`. It defines `ROOT`, `DIR_*`, `ROOT_URL`, `PROTOCOL`, `ENVIRONMENT`, `APPNAME`, `APPID`, and the `db()` / `registry()` accessors, then builds the registry. Its built-in default timezone is `America/New_York` and is overridden by the store preference.
2. **`Database::prepare()` returns the `Database` object, not a `PDOStatement`** (`_inc/lib/database.php`). Only **one statement can be live at a time** — a second `prepare()` before `execute()`/`fetch()` clobbers the first. `execute()` returns **null**, not a row count. Canonical form:
   ```php
   $statement = db()->prepare("SELECT … WHERE id = ?");
   $statement->execute(array($id));
   $row = $statement->fetch(PDO::FETCH_ASSOC);
   ```
   The statement is **not** executed until you call `execute()`. `db()->prepare($sql)->fetchAll(...)` returns an empty set.
3. **`trans('missing_key')` writes to the database.** `Language::get()` INSERTs the key into `language_translations` with a NULL value, then returns a title-cased version of the key. Missing keys render as text, never as empty — check the `language_translations` table, not the code.
4. **`set_preference()` does not exist.** Use `ModelStore::editPreference($store_id, $preference)` (serialized array).
5. **`language/` and `DIR_LANGUAGE` are dead.** Translations live in the `languages` / `language_translations` tables only.
6. **`INSTALLED` in `config.php` is never read** by any code. It is not an install guard.
7. **`update.php`, `refresh.php` and `revalidate.php` were removed** — all three `include "install/_init.php"`, and `install/` does not exist (fatal on load). `refresh.php` also overwrote `_init.php`, `network.php`, `ecnesil.php` with content downloaded from the vendor server (supply-chain backdoor). `update/*.sql` (the v2→v3 migration) is kept as inert history. The license itself lives in `_inc/config/purchase.php`; the "Invalid Purchase Code" page now points there.
8. **`sync.php` is the offline→online sync server** — it bypasses the app bootstrap (no `_init.php`, no session, no auth) and executes SQL sent by remote POS. It is **kept on purpose** for the Venezuela deployment. It now has a real auth redesign (see "Synchronization" below), but it remains the most dangerous endpoint in the repo: it executes SQL. Never expose it publicly without the client allowlist.
8. **`Request` HTML-escapes every input** (`htmlspecialchars(..., ENT_COMPAT, 'UTF-8')`) on both keys and values. Re-escaping double-escapes. Store `&amp;` in the DB, not `&`.
9. **Never concatenate a request-derived value into SQL.** The listing filters (`filter_name`, `filter_search_key`, `exclude`, `only`, …) across `_inc/model/*.php` and the POS autocomplete in `_inc/ajax.php` were a real SQL injection — a payload returned every row instead of the filtered ones. They now use `?` placeholders with an `$args` array. Two rules for anything new:
   - **Values** → `?` placeholder, pushed onto `$args` in the same order the placeholder appears in the SQL. `LIKE 'x%'` becomes `LIKE ?` with `$args[] = $data['k'] . '%'`.
   - **Identifiers** (column names) → an explicit allowlist. `_inc/ajax.php` accepts only `p_id`, `p_name`, `p_code`; `ORDER BY` uses `in_array($data['sort'], $sort_data)`.
   The `filter_*` keys are only ever filled with literal values by the templates today, so the injection was latent in the models — but `_inc/ajax.php` was **live** (any cashier could hit it from the POS search).
10. **`Loader::library()` contains `print_r($file);die;`** — never call it. Use `registry()->get('loader')->model(...)` / `->helper(...)`.
11. **POS checkout is not transactional.** `_inc/place_order.php` → `ModelInvoice::createInvoice()` (`_inc/model/invoice.php`) writes `purchase_item` (FIFO stock decrement), `selling_item`, `product_to_store`, `selling_info`, `selling_price`, `payments`, `sell_logs`, and optional `customer_transactions` / `installment_*` — with **no `BEGIN`/`COMMIT`/rollback**. A mid-way failure leaves partial writes.
12. **`storage/qrcode.png` is a single shared file** overwritten on every render — concurrent receipt/quotation rendering races.
13. `admin/error_log`, `storage/logs/*.txt` — runtime log files, not source.
14. **Every handler in `_inc/` must start with an `is_loggedin()` guard** returning `422 {"errorMsg": trans('error_login')}`. `_inc/ajax.php` was the only one missing it and it leaked the whole product catalogue with costs and margins.

---

## Synchronization (offline → online)

`sync.php` (server) + `ModelCron::PushSqlToRemoteServer()` (client) + `_inc/helper/sync.php` (shared signing code).

**Protocol v2 (signed)** — `POST sync.php` with `username`, `action`, `data`, `timestamp`, `nonce`, `signature`:

```
canonical = username \n action \n timestamp \n nonce \n sha256(data)
signature = hash_hmac('sha256', canonical, client_secret)
```

The server verifies in this order: known client → timestamp within `SYNC_MAX_AGE` → `hash_equals` signature → nonce not yet used. The nonce is consumed **after** the signature check, so an unauthenticated request cannot burn another client's nonces. Nonces live in `storage/sync-nonces/` (one file each, pruned by age); `fopen(..., 'x')` makes the create atomic.

**Protocol v1 (legacy)** — plain `username`/`password`, accepted **only** while `SYNC_LEGACY_AUTH=true`. This exists so POS terminals that have not been updated yet keep syncing.

**Operational requirements most people miss:**

- **`LOG=true` is required for sync to work at all.** `Log::__construct` only opens its handle when `LOG` is on, so `storage/logs/sql.txt` is never written otherwise — and there is nothing to push. `SYNCHRONIZATION=true` alone is not enough.
- The client only ever produces **INSERT / UPDATE / DELETE** (that is all `Database::execute()` logs). The server therefore rejects anything else, and rejects stacked statements (`...; DROP TABLE ...`). `pdo_start()` sets `ATTR_EMULATE_PREPARES => false` to match the main `Database` class, so MySQL itself refuses multi-statement queries.
- The whole batch runs in **one transaction**: a failure mid-batch rolls back instead of leaving partial writes.
- Auth failures and SQL errors are logged to `storage/logs/sync.log`; the HTTP response never leaks DB details.

**Rollout (do it in this order):**

1. Master: set `SYNC_CLIENTS="pos-01:<secret>,pos-02:<secret>"` — one secret per terminal, so one compromised store cannot impersonate the others.
2. Each terminal: set `SYNC_CLIENT_ID` and `SYNC_CLIENT_SECRET` to match its entry. From that moment it uses the signed path automatically.
3. Master: set `SYNC_LEGACY_AUTH=false` once **every** terminal is updated. Before that, leave it `true`.
4. Master: set `SYNC_REQUIRE_HTTPS=true` (the default) and `SYNC_ALLOWED_IPS` if the terminals have static IPs.

**Rotating the legacy credential without an outage.** The original credential (`itsolution24`/`1993`) is public: it is hardcoded in the source the vendor distributes to every customer. To rotate it without cutting off terminals that have not been updated yet, the master also accepts a **migration window**:

```
# master .env
SYNC_LEGACY_USERNAME=<nuevo>
SYNC_LEGACY_PASSWORD=<nuevo-secreto>
SYNC_LEGACY_PREVIOUS=<usuario-actual>:<clave-actual>   # accepted only during the window
```

`SYNC_LEGACY_PREVIOUS` holds `user:secret` pairs separated by commas. It is **master-only** — a terminal never reads it, it only sends the single `SYNC_LEGACY_USERNAME`/`SYNC_LEGACY_PASSWORD` it was given. Once the whole fleet is on the signed path, clear `SYNC_LEGACY_PREVIOUS` and set `SYNC_LEGACY_AUTH=false`.

**Never change the v2 payload shape without updating both sides** — `_inc/helper/sync.php` is shared, so the signature is computed and verified by the same code.

---

## Listing filters: how they work

Every listing method (`getProducts`, `getCustomers`, …) takes an optional `$data` array of filters and builds one SQL string with an `$args` array. The pattern to follow:

```php
$sql = "SELECT … WHERE `x`.`y` = ? AND `status` = ?";
$args = array($store_id, 1);

if (isset($data['filter_name'])) {
    $sql .= " AND `x`.`y` LIKE ?";
    $args[] = $data['filter_name'] . '%';
}

$statement = $this->db->prepare($sql);
$statement->execute($args);
```

- The `$args` order must match the order of the `?` placeholders in the final SQL. Base placeholders first, then filters in the order they are appended.
- `ORDER BY` uses an `in_array($data['sort'], $sort_data)` allowlist. The `else` branch is a **hardcoded fallback that always runs** — if its column does not exist, the whole listing breaks, not just one filter. Same for the `$sort_data` allowlist entries.
- The frontend never sends `filter_*`; the templates call these helpers with literal values (`get_customers(array('exclude' => 1))`). So a broken filter shows up as a SQL error in a dropdown, not as a wrong result.

**Schema gotchas that broke these filters (all fixed, do not reintroduce):**

| Filter | Was | Now |
|---|---|---|
| `getLoans()` | `loan_name` (does not exist; also in the `ORDER BY` fallback) | `title` |
| `getProducts()` | `p.purchase_price`, `p.sell_price`, `p.quantity_in_stock` | `p2s.*` — prices and stock live in `product_to_store` |
| `getBoxes()` | `box_id` (ambiguous between `boxes` and `box_to_store`) | `boxes.box_id` |
| `getBanners()` | `parent_id` (banners are not hierarchical; copy/paste from `category.php`) | removed |
| `getProducts()` | `filter_purchase_price` only applied when `filter_sell_price` was also set | applies on its own |
| `getBanners()`, `getLoans()`, `getProducts()` | `id` ambiguous / missing `WHERE` | qualified, `WHERE 1=1` added |

The banner tables (`banners`, `banner_to_store`, `banner_images`) were **not shipped** by the vendor — not in the SQL dump, not in the v2→v3 migrations — even though `_inc/model/banner.php` uses them. They are created by `update/2026-10-29-banners.sql`.

---

## i18n (translations)

- `trans('key')` → `_inc/lib/language.php` → the `language_translations` row for the **active language** (`lang_id`). If the row is missing or its `lang_value` is NULL, the key is humanized and shown as English-looking text.
- The active language comes from the **user preference** (`users.preference['language']`). For an anonymous visitor (the login screen) it falls back to `APP_DEFAULT_LANGUAGE` in `.env`.
- `Document::langTag()` maps the language **code** to the `<html lang>` attribute; it accepts both the code (`es`) and the name (`spanish`).
- **Seed data is not a translation.** The vendor shipped the expense categories, income sources, payment methods, brands, units, boxes, tax rates and currency names in English. Those are *rows in the database*, not keys in `language_translations` — which is why the Contabilidad dropdowns were in English even after the labels were translated. Fixed by `update/2026-10-29-espanol-datos.sql`.
- Adding a new `trans()` key also means adding a row for `lang_id = 7` (es). `Language::get()` only inserts the key with a NULL value, which renders as the humanized key.
- The Spanish translations that ship with the app are **UPPERCASE and mostly without accents** (only 15 of ~1500 use them). Match that style when adding new ones.
- Migrations live in `update/` and are plain SQL, so they can be applied with phpMyAdmin as well as with `mysql`.

---

## Operations

- **Cron** — `cron.php?action=<ACTION>` (HTTP only; CLI fatals, see Setup).
  Actions: `CHECKFORUPDATE`, `DBBACKUP`, `SENDCUSTOMERBIRTHDAYSMS`, `PUSHSQLTOREMOTESERVER`, `RUNALLJOBS`.
  Suggested schedule (from `cron.php`): `0 1 * * * wget -qO- http://<host>/<subdir>/cron.php`
  No-op when `DEMO = true`.
- **Backups** — `ModelCron::DBBackup()` writes `storage/backups/mpos-backup-on-YYYY-MM-DD.txt` (excludes `users`, `user_group`, `user_to_store`), auto-prunes >30 days. `ModelCron::TableBackup()` writes per-table dumps. Restore via `admin/backup_restore.php` + `_inc/restore.php` (reads `storage/backups/bac*.*`).
- **Maintenance mode** — create `<ROOT>/.maintenance` to trigger `maintenance.php`; delete it to resume.
- **`.env` flags** that change runtime behavior: `DEMO`, `USECOMPILEDASSET` (JS only), `USECOMPILEDCSS` (CSS only), `RTL`, `INSTALLMENT`, `HOOK` (activity logging), `LOG`, `SYNCHRONIZATION` (SQL log + remote push). They are read in `config.php` via `env_bool()`; the `.env` value wins over the literal fallback.
- **Multi-store** — the active store comes from the session (`store_id`, defaults to 1); `store_select.php` switches it. Most models take `$store_id` and scope through the `*_to_store` joins — preserve that scoping when adding queries.

---

## Verifying a change

There is **no test suite**. Verify manually:

1. `php -l <changed file>` for every touched PHP file.
2. Log in, exercise the changed screen, and check `storage/logs/log.txt` and the browser console.
3. For DB-affecting changes, confirm rows in the relevant `*_to_store` join table and that the serialized preference blob still round-trips through `unserialize()`.
4. For POS/stock changes, verify the `purchase_item` FIFO decrement and that `product_to_store.quantity_in_stock` matches the sum of active lots — no transaction protects this.
5. Rebuild assets (`npm run dev`) if you touched anything under `assets/itsolution24/angular/` or `assets/itsolution24/js/`, and remember the CSS exception above.
6. **Watch the deprecation output.** `_init.php` sets `error_reporting(-1)` in development, so PHP 8.2 notices appear inline. A clean dashboard is not proof the rest of the app is clean — each page exercises different classes. To audit statically, load the class files and watch for `E_DEPRECATED`. The fastest full sweep is to log in and hit every `admin/*.php` page while reading the PHP server's stderr.
