# POS Moderno — Mapa funcional del sistema y plan de reconstrucción

> Documento de referencia funcional. Describe **qué hace** el sistema (pantallas, acciones, columnas, endpoints, tablas, permisos), **cómo está construido** y **qué hay que decidir** para construir un sistema nuevo desde cero con base sólida y aspecto moderno.
>
> Todo lo que sigue está verificado en el código (`admin/`, `_inc/`, `assets/itsolution24/angular/`) y en el esquema real (`BASE DE DATOS/pos_moderno.sql`). Los nombres de ficheros, funciones, campos, slugs de permiso y `dt` de DataTables se citan literalmente porque son la referencia real de la implementación.

---

## 0. Cómo leer este documento

| Si quieres… | Ve a… |
|---|---|
| Saber qué pantallas existen y qué hacen | §3 Mapa funcional por módulo |
| Ver qué columnas tiene cada listado | §3 (subsección "Columnas") |
| Entender el modelo de datos | §2.3 y §7.2 |
| Entender la navegación | §4 |
| Entender los permisos | §5 |
| Saber qué está roto | §6 |
| Decidir qué se reutiliza | §7 |
| Planear el sistema nuevo | §8 |

Convenciones de lectura:

- `fichero.php:línea` → referencia exacta en el código.
- Los nombres entre backticks son literales del código (ficheros, funciones, campos, slugs).
- Los nombres de columna `dt` son los que viajan en el JSON de DataTables; las cabeceras HTML son las que ve el usuario.
- `⚠` marca un defecto verificado, no una opinión.

---

## 1. Resumen ejecutivo

POS Moderno es un **sistema de punto de venta multi-tienda** heredado de un producto comercial (ITSolution24 / Codecanyon), escrito en **PHP 8.2 procedural + AngularJS 1.6 + MySQL/MariaDB**, sin CI, sin tests, sin gestor de dependencias de PHP y sin framework. Pesa **73 tablas** y ~110 pantallas administrativas.

**Lo que hace bien (y hay que conservar):**

1. **El dominio está modelado con detalle real.** Cashback FIFO por lote (`purchase_item.status`: `stock` → `active` → `sold`), crédito de cliente, cuotas con interés, transferencias entre almacenes con doble juego de stock, tarjetas de regalo con saldo y recarga, devoluciones que reponen stock y revierten caja, y una contabilidad de caja que se alimenta desde **todos** los módulos. Eso es un dominio real, no un CRUD.
2. **El multi-store por patrón maestro/bridge** (`products` + `product_to_store`, `customers` + `customer_to_store`, …) es una decisión correcta: la identidad se hereda, el estado (precio, stock, saldo, deuda) es por tienda.
3. **La receta de 5 archivos por entidad** (`model/` → `helper/` → `_inc/<x>.php` → `admin/<x>.php` → `<X>Controller.js`) es repetible y predecible.

**Lo que está mal (y hay que rehacer):**

1. **Cero transacciones SQL.** El checkout (`createInvoice()`) escribe 8+ tablas de forma secuencial sin `BEGIN`/`COMMIT`. Un fallo a mitad deja stock decrementado sin factura, o facturas sin `selling_price`. Es el defecto estructural más grave del sistema.
2. **La contabilidad es una caja de una sola pieza, no un sistema de doble partida.** Todo el reporting contable se apoya en `bank_transaction_info` + `bank_transaction_price`, y el gasto contable real **no** sale de la tabla `expenses` sino del movimiento bancario. Un gasto creado sin `deposit_account_id` no aparece en ningún informe.
3. **Permisos por slug sueltos, sin matriz de verdad.** ~190 slugs en `_inc/template/user_group_form.php`, con typos heredados (`crate_category`, `updadte_pmethod_status`) y slugs usados en código que **no existen** en la matriz (`read_banner`, `sms_setting`, `read_holding_order`, `add_quotation`, `add_purchase`). El botón se oculta por permiso en el HTML pero **el handler no lo comprueba**: se ve y luego devuelve 422.
4. **Inyección SQL residual** en el buscador del POS (`_inc/pos.php:67`, `_inc/model/product.php:481,486`), en listados de banca (`_inc/banking.php:374`), extractos de cliente, transferencias y reset de datos. El patrón correcto (allowlist + placeholders) ya existe en `_inc/ajax.php` — sólo que no se aplicó en todas partes.
5. **Contraseñas en MD5 sin sal**, y además se guarda el **texto plano** en `users.raw_password`.
6. **La vista de usuario no es el backend.** `admin/header.php` inyecta `settings` (todo `get_all_preference()` salvo dos claves) y `store` (el blob completo de preferencias) al JavaScript del navegador.
7. **Código muerto y duplicación**: `admin/order.php` llama a un método inexistente (`ModelOrder::createOrder`), `admin/purchase_edit.php` monta el formulario de cotización, `admin/report_pos_overview.php` es un clon huérfano de P&G, `admin/purchase_list.php` es un enlace roto en el menú, `_inc/installment_payment.php` es copia literal de `_inc/installment.php`.
8. **El stack de front está congelado**: AngularJS 1.6 + jQuery + Bootstrap 3.3.5 + AdminLTE, con bundles CSS/JS que ya no se reconstruyen (`mix.styles()` está comentado). Modernizar el aspecto exigiría reconstruir el front entero.

**Conclusión operativa:** modernizar esta aplicación significa reescribir el 100% del front y reescribir el 60% del back (transacciones, permisos, seguridad, contabilidad) con el riesgo de arrastrar el dominio y sus 73 tablas. El camino barato no existe. La decisión rentable es **reutilizar el dominio y el modelo de datos, reconstruir la aplicación** — y es exactamente lo que este documento habilita.

---

## 2. Arquitectura actual

### 2.1 Stack y estructura

```
index.php / admin/*.php      vistas (guardas, layout, ng-controller)
  └─ assets/itsolution24/angular/controllers/*.js   controllers AngularJS 1.6 (86)
assets/itsolution24/angular/modals/*.js             modal factories (101)
assets/itsolution24/angular/angularApp.js           módulo "angularApp"
_inc/<entidad>.php            handler JSON + endpoint DataTables server-side (67)
_inc/model/<entidad>.php      clases Model<Entity> (45) — todo el acceso a datos
_inc/helper/<entidad>.php     funciones globales (52)
_inc/template/*.php           fragmentos HTML server-rendered (117)
_inc/lib/*.php                Registry, Loader, Database, User, Session, Request, Language…
_init.php                     bootstrap ofuscado (línea 7 = ~26 KB de escapes y goto)
```

**No hay router.** Dos convenciones coexistentes, ambas elegidas por el cliente:

| Mecanismo | URL | Selector | Notas |
|---|---|---|---|
| Handlers de entidad | `POST\|GET {baseUrl}/_inc/<entidad>.php` | `action_type` | `CREATE`/`UPDATE`/`DELETE` (POST); `EDIT`/`CREATE`/`DELETE`+id (GET → fragmento HTML) |
| Ajax compartido | `POST\|GET {baseUrl}/_inc/ajax.php` | `type` | `PRODUCTIMAGES`, `QUOTATIONINFO`, `STOCKITEMS`, `SELLINGITEM`, `PURCHASEITEM`, `UPDATEOPENINGBALANCE`, `UPDATEPOSTEMPALTECONTENT`, … |

- Contrato de respuesta: **éxito** → `{"msg": …, "id": N}`; **fallo** → HTTP **422** + `{"errorMsg": "…"}`.
- `action_type` en POST viene de un campo oculto del formulario; en GET, de la query string.
- El nombre del parámetro de id es **por entidad** (`category_id`, `product_id`, `p_id`, `ref_no`, `invoice_id`, …).
- `baseUrl` lo emite `admin/header.php` y vale `root_url()`.

### 2.2 La receta de 5 archivos (repetible, conviene conservarla como patrón)

Para añadir una entidad `foo`:

1. **`_inc/model/foo.php`** — `class ModelFoo extends Model`. Métodos `addFoo` / `editFoo` / `deleteFoo` / `getFoo($id, $store_id=null)` / `getFoos($data=[], $store_id=null)`. Los filtros llegan en `$data` como `filter_*`, `sort`, `order`, `start`, `limit`. Se carga con `registry()->get('loader')->model('foo')`.
2. **`_inc/helper/foo.php`** — wrappers globales (`get_the_foo($id)`, `get_the_foos()`).
3. **`_inc/foo.php`** — el handler, en este orden exacto:
   - `ob_start(); session_start(); include "../_init.php";`
   - guarda `is_loggedin()` → 422 `trans('error_login')`
   - `user_group_id() != 1 && !has_permission('access','read_foo')` → 422 `trans('error_read_permission')`
   - POST `action_type=CREATE|UPDATE|DELETE` → `has_permission('access','create_foo')` etc., `validate_request_data()`, `$Hooks->do_action('Before_Create_Foo', $request)` → model → `$Hooks->do_action('After_Create_Foo', $entidad)` → JSON
   - GET `action_type=CREATE|EDIT|DELETE` → `include '_inc/template/foo_create_form.php'` y `exit()`
   - **fallback (sin `action_type`)** → DataTables server-side: `$table`, `$primaryKey`, `$columns` y `SSP::simple($request->get, $sql_details, $table, $primaryKey, $columns)`
4. **`admin/foo.php`** — vista: mismas dos guardas (redirección, no 422), `$document->setTitle(...)`, `$document->addScript('../assets/itsolution24/angular/controllers/FooController.js')`, `include header.php; include left_sidebar.php;`, body con `ng-controller="FooController"`, tabla vacía, `include '../_inc/template/foo_create_form.php'`, `include footer.php`.
5. **`assets/itsolution24/angular/controllers/FooController.js`** — `window.angularApp.controller("FooController", ["$scope","API_URL","window","jQuery","$compile","$uibModal","$http","$sce", function(...){…}])`, con DataTables `serverSide: true` y `aoColumns` alineados con los `dt` del PHP.

Los modales son **factories** que devuelven una función: `window.angularApp.factory("FooModal", [… function(...){ return function(row){ $uibModal.open({…}); }; }])`; el cuerpo del modal es HTML renderizado por PHP, inyectado con `$sce.trustAsHtml(response.data)` en `<div bind-html-compile="rawHtml">`.

### 2.3 Modelo de datos — 73 tablas

**Patrón maestro/bridge.** La tabla maestra guarda la identidad; la tabla `*_to_store` guarda el estado por tienda (`status`, `sort_order`, `balance`, `due`, `quantity_in_stock`). Todos los modelos filtran por `WHERE <tabla>_to_store.store_id = store_id()`, y `store_id()` es `$_SESSION['store_id']` (por defecto 1).

#### Identidad y seguridad (6)

| Tabla | PK | Rol |
|---|---|---|
| `users` | `id` | `username`, `email`, `mobile`, `password` (MD5), `raw_password` (**texto plano**), `group_id`, `preference` (blob), `pass_reset_code`, `reset_code_time` |
| `user_group` | `group_id` | `name`, `slug`, `permission` (blob serializado: `access` + `modify`) |
| `user_to_store` | `id` | `user_id`, `store_id`, `status`, `sort_order` |
| `login_logs` | `id` | `username`, `ip`, `status`, `created_at` |
| `settings` | `id` | Ajustes globales |
| `shortcut_links` | `id` | Accesos directos del panel lateral: `type`, `href`, `icon`, `permission_slug`, `sort_order`, `status` |

#### Tiendas y bridges (13)

| Tabla | Rol |
|---|---|
| `stores` | `store_id`, `name`, `code_name`, `country`, `address`, `email`, `mobile`, `zip_code`, `vat_reg_no`, `cashier_id`, `logo`, `favicon`, `receipt_printer`, `order_printers` (JSON), `remote_printing`, `auto_print`, `char_per_line`, `deposit_account_id`, `preference` (blob serializado), `status`, `sort_order` |
| `product_to_store` | `purchase_price`, `sell_price`, `quantity_in_stock`, `alert_quantity`, `taxrate_id`, `tax_method`, `e_date`, `p_date`, `preference` (blob) |
| `customer_to_store` | `balance`, `due` |
| `supplier_to_store` | `balance` |
| `brand_to_store`, `category_to_store`, `box_to_store`, `unit_to_store`, `currency_to_store`, `pmethod_to_store`, `printer_to_store`, `pos_template_to_store`, `bank_account_to_store` | `status`, `sort_order` (+ saldo en `bank_account_to_store`: `deposit`, `withdraw`, `transfer_from_other`, `transfer_to_other`) |

#### Catálogo maestro (13)

| Tabla | PK | Rol |
|---|---|---|
| `products` | `id` | `p_name`, `p_code`, `p_type` (`standard`/`service`), `p_image`, `barcode_symbology` |
| `product_images` | `image_id` | `product_id`, `url`, `sort_order` |
| `customers` | `customer_id` | `customer_name`, `customer_mobile`, `customer_email`, `customer_address/city/state/country`, `customer_sex`, `dob`, `credit_balance`, `gtin`, `status` |
| `suppliers` | `sup_id` | `sup_name`, `code_name`, `sup_email`, `sup_mobile`, `sup_address/city/state/country`, `gtin`, `status` |
| `categorys` | `category_id` | ⚠ nombre irregular (no `categories`); `category_name`, `category_slug`, `parent_id`, `sort_order` |
| `brands` | `brand_id` | `brand_name`, `code_name`, `brand_image` |
| `boxes` | `box_id` | `box_name`, `code_name`, `box_details` (ubicaciones/almacenes internos) |
| `units` | `unit_id` | `unit_name`, `code_name`, `unit_details` |
| `taxrates` | `taxrate_id` | `taxrate_name`, `code_name`, `taxrate` |
| `currency` | `currency_id` | `title`, `code`, `symbol_left`, `symbol_right`, `decimal_place` |
| `pmethods` | `pmethod_id` | `name`, `sort_order`, `details` |
| `printers` | `printer_id` | `title`, `type` (`network`/`windows`/`linux`), `path`, `ip_address`, `port`, `char_per_line` |
| `pos_templates` | `template_id` | `template_name`, `template_content`, `template_css` |

#### Ventas (18)

| Tabla | PK | Rol |
|---|---|---|
| `selling_info` | `info_id` | **Cabecera**: `invoice_id` (lógico), `customer_id`, `store_id`, `pmethod_id`, `created_by`, `payment_status` (`paid`/`due`), `checkout_status`, `is_installment`, `total_items`, `invoice_note`, `customer_mobile`, `ref_invoice_id`, `inv_type` enum(`sell`) |
| `selling_item` | `id` | **Línea**: `invoice_id`, `item_id`, `category_id`, `brand_id`, `sup_id`, `store_id`, `purchase_invoice_id` (lote FIFO), `item_price`, `item_discount`, `item_tax`, `item_purchase_price`, `item_total`, `item_quantity`, `tax`/`gst` (**porcentaje**), `cgst`/`sgst`/`igst`, `return_quantity`, `tax_method`, `taxrate_id`, `print_counter` |
| `selling_price` | `price_id` | **Dinero** (1:1 con `selling_info`): `subtotal`, `discount_type`/`discount_amount`, `interest_amount`/`interest_percentage`, `item_tax`, `order_tax`, `cgst`/`sgst`/`igst`, `total_purchase_price`, `shipping_type`/`shipping_amount`, `others_charge`, `payable_amount`, `paid_amount`, `due`, `due_paid`, `return_amount`, `balance` (cambio), `profit`, `previous_due`, `prev_due_paid` |
| `payments` | `id` | Cobros: `invoice_id`, `pmethod_id`, `store_id`, `created_by`, `type` (`sell`/`due_paid`/`return`/`change`/`discount`), `capital`, `amount`, `total_paid`, `pos_balance` (cambio), `details` (**blob serializado**) |
| `sell_logs` | `id` | Bitácora de cobro: `type`, `pmethod_id`, `amount`, `reference_no`, `ref_invoice_id` |
| `returns` / `return_items` | `id` | Devolución de venta: `subtotal`, `total_amount`, `item_tax`, `profit`, `total_quantity`, `reference_no`, `note`, `attachment` / `item_price`, `item_quantity`, `item_purchase_price`, `item_total` |
| `customer_transactions` | `id` | Extracto de cuenta: `type` enum(`purchase`,`add_balance`,`substract_balance`,`due_paid`,`others`), `amount`, `balance`, `ref_invoice_id`, `pmethod_id` |
| `gift_cards` | `id` | `card_no`, `value`, `balance`, `customer_id`, `expiry`, `created_by` (**varchar con id de usuario**) |
| `gift_card_topups` | `id` | `card_id` (**texto → `card_no`**), `amount`, `created_by` |
| `installment_orders` | `id` | `invoice_id`, `duration`, `interval_count`, `installment_count`, `interest_percentage`, `interest_amount`, `initial_amount`, `payment_status`, `last_installment_date` |
| `installment_payments` | `id` | `invoice_id`, `payment_date`, `capital`, `interest`, `payable`, `paid`, `due`, `payment_status` enum(`paid`,`due`,`pending`,`cancel`) |
| `holding_info` / `holding_item` / `holding_price` | `info_id` / `id` / `price_id` | Pedido en espera: `ref_no` (clave lógica), `order_title`, `customer_id`, `total_items` / `item_price`, `item_quantity`, `item_total` / `subtotal`, `payable_amount` |
| `quotation_info` / `quotation_item` / `quotation_price` | `info_id` / `id` / `price_id` | Cotización **y** pedido comparten tabla; se distinguen por `quotation_info.is_order` (0 = cotización, 1 = pedido). `reference_no` (clave), `status` enum(`sent`,`pending`,`complete`,`canceled`), `invoice_id` (al vender) |

#### Compras (7)

| Tabla | PK | Rol |
|---|---|---|
| `purchase_info` | `info_id` | Cabecera: `invoice_id` (`get_invoice_prefix('purchase') . reference_no`), `sup_id`, `store_id`, `created_by`, `payment_status`, `checkout_status`, `shipping_status` enum(`received`,`pending`,`ordered`), `inv_type` enum(`purchase`,`transfer`) |
| `purchase_item` | `id` | **Línea y lote — es el soporte del FIFO**: `invoice_id`, `item_id`, `store_id`, `status` enum(`stock`,`active`,`sold`), `item_purchase_price`, `item_selling_price`, `item_quantity`, `total_sell` (consumido), `return_quantity`, `item_total`, `item_tax` |
| `purchase_price` | `price_id` | Dinero: `subtotal`, `discount_*`, `shipping_*`, `others_charge`, `item_tax`, `order_tax`, `payable_amount`, `paid_amount`, `due_paid`, `due`, `return_amount`, `balance` |
| `purchase_payments` | `id` | Pagos a proveedor: `invoice_id`, `pmethod_id`, `type` (`purchase`,`due_paid`,`transfer`), `amount`, `total_paid`, `balance` |
| `purchase_logs` | `id` | Bitácora de pago a proveedor |
| `purchase_returns` / `purchase_return_items` | `id` | Devolución de compra: `reference_no`, `note`, `subtotal`, `total_amount` / `item_price`, `item_quantity`, `item_total` |

#### Transferencias (2)

| Tabla | Rol |
|---|---|
| `transfers` | `ref_no`, `invoice_id`→`purchase_info`, `from_store_id`, `to_store_id`, `status` (`pending`/`sent`/`complete`), `total_item`, `total_quantity`, `note`, `attachment` |
| `transfer_items` | `transfer_id`, `product_id`, `store_id`, `quantity` (**no guarda precios**) |

#### Banca y contabilidad (9)

| Tabla | PK | Rol |
|---|---|---|
| `bank_accounts` | `id` | `account_name`, `account_no`, `contact_person`, `phone_number`, `url`, `initial_balance`, `total_deposit`, `total_withdraw`, `total_transfer_from_other`, `total_transfer_to_other` (**globales, no por tienda**) |
| `bank_account_to_store` | `ba2s` | `account_id`, `store_id`, `deposit`, `withdraw`, `transfer_from_other`, `transfer_to_other` (**saldo real por tienda**) |
| `bank_transaction_info` | `info_id` | Cabecera del movimiento: `transaction_type` (`deposit`/`withdraw`/`transfer`), `account_id`, `from_account_id`, `source_id`→`income_sources`, `exp_category_id`→`expense_categorys`, `title`, `ref_no`, `details`, `created_by`, `is_substract`, `is_hide` |
| `bank_transaction_price` | `price_id` | Importe del movimiento: `info_id`, `amount` decimal(25,4) |
| `expenses` | `id` | `category_id`, `reference_no`, `title`, `amount`, `returnable`, `note`, `sup_id`, `created_by` — ⚠ **no es la fuente de verdad del gasto contable** |
| `expense_categorys` | `category_id` | `category_name`, `category_slug`, `parent_id` + **flags contables**: `sell_return`, `sell_delete`, `loan_delete`, `loan_payment`, `giftcard_sell_delete`, `topup_delete`, `product_purchase`, `stock_transfer`, `due_paid`, `is_hide` |
| `income_sources` | `source_id` | `source_name`, `source_slug`, `parent_id` + **flags contables**: `type` (`credit`/`debit`), `for_sell`, `for_purchase_return`, `for_due_collection`, `for_loan`, `for_giftcard_sell`, `for_topup`, `for_stock_transfer`, `for_purchase_delete`, `for_expense_delete`, `profitable`, `show_in_income`, `is_hide` |
| `pos_register` | `id` | Caja del POS: `store_id`, `opening_balance`, `closing_balance` |
| `loans` / `loan_payments` | `loan_id` / `id` | Préstamos: `loan_from`, `ref_no`, `title`, `amount`, `interest`, `payable`, `paid`, `due`, `status` / `lloan_id` (**typo**), `paid`, `note` |

#### i18n y comunicación (5)

| Tabla | Rol |
|---|---|
| `languages` | `code`, `name`, `status` |
| `language_translations` | `lang_key`, `lang_value` (NULL si no traducida), `lang_id` |
| `sms_setting` | 5 filas (Clickatell, Twilio, Msg91, Onnorokomsms, Mimsms) con `api_id`, `auth_key`, `sender_id`, `contact`, `username`, `password`, `maskname`, `campaignname`, `unicode`, `country_code`, `url` — ⚠ **credenciales en claro** |
| `sms_schedule` | `schedule_datetime`, `campaign_name`, `people_name`, `mobile_number`, `response_text`, `process_status`, `delivery_status`, `total_try` |
| `mail_sms_tag` | Plantillas de SMS (`type=invoice`) con tags `{{…}}` |

---

## 3. Mapa funcional por módulo

### 3.1 Dashboard (`admin/dashboard.php`)

- **Guarda**: sólo `is_loggedin()` — sin permiso específico.
- **KPIs** (cajas "small-box" con enlace "detalles"): total de facturas del periodo, total de clientes, total de proveedores, total de productos (cada uno con su variante "hoy"). Orígenes: `total_invoice()` (`_inc/model/invoice.php:717`), `total_customer()` (`_inc/model/customer.php:364`), `total_supplier()` (`_inc/model/supplier.php:318`), `total_product()` (`_inc/model/product.php:568`).
- **Caja**: depósito de hoy, retiro de hoy (`get_bank_deposit_amount` / `get_bank_withdraw_amount`, `_inc/helper/banking.php:8,14`).
- **Tablas**: "Depósitos recientes" y "Retiros recientes" — columnas `label_date`, `label_description`, `label_amount` (3 últimos movimientos, `getTransactions('deposit'|'withdraw', store_id(), 3)`).
- **Botonera** (`_inc/template/partials/action_buttons.php`): `button_pos`, `button_sell_list`, `button_overview_report`, `button_sell_report`, `button_purchase_report`, `button_stock_alert`, `button_expired_alert`, `button_backup_restore`, `button_stores`.
- **Gráfico**: `ReportCollectionController.js` → `_inc/report_collection.php?from=&to=&user_id=`.
- ⚠ `admin/dashboard.php:91` — el enlace "detalles" de la caja de facturas se condiciona con `read_customer` en vez de `read_sell_list`.

### 3.2 POS (`admin/pos.php`) — el corazón del sistema

Pantalla **standalone** (sin `header.php` ni `left_sidebar.php`), con catálogo lateral, carrito central y panel de cobro.

- **Guarda**: `!is_loggedin()` → login; `user_group_id() != 1 && !has_permission('access','create_sell_invoice')` → dashboard.
- **Configuración leída del usuario y la tienda**: `pos_side_panel` (`left`/`right`, default `right`), `pos_pattern` (fondo), `change_item_price_while_billing`, `pos_product_display_limit`, `receipt_printer` + `order_printers` (JSON), `auto_print`, `remote_printing`, `after_sell_page`, `invoice_auto_sms`, `sms_alert`, `expiry_yes`, `tax`.
- **Catálogo**:
  - Búsqueda `#product-name` → `GET _inc/pos.php?action_type=PRODUCTLIST&field=p_name&query_string=&category_id=&page=`.
  - Filtro por categoría (select2, con `get_total_valid_category_item()`).
  - Grid de tarjetas con `p_id`, `p_image`, `p_name`, `p_type`; badge `Service` para servicios; overlay `label_add_to_cart`.
  - Orden por más vendidos: `SUM(selling_item.item_total) DESC`.
  - Botones: `button_add_product` (`ProductCreateModal`), `button_add_purchase` (`OpenPurchaseProductModal`), `text_gift_card` (`GiftcardCreateModal`, permiso `add_giftcard`).
- **Carrito** (array `itemArray` en `PosController.js`): cada línea con `id`, `pType`, `categoryId`, `supId`, `name`, `unitName`, `taxamount`, `price`, `quantity`, `subTotal`.
  - Cabecera: `label_quantity`, `label_product`, `label_price`, `label_subtotal`.
  - Acciones por línea: ↑ (`addItemToInvoice`), input `#item_quantity_{{id}}` editable a mano, ↓ (`DecreaseItemFromInvoice`), input `#item_price_{{id}}` (sólo si `change_item_price_while_billing`), ✕ (`removeItemFromInvoice`).
  - Validación de stock en cliente: cantidad > `quantity_in_stock` → aviso "This product is out of stock!".
- **Totales** (`_calcTotalPayable`, `PosController.js:339`):
  - `#discount-input` admite sufijo `%` → `discountType='percentage'`.
  - `#tax-amount` (1..100) = `(tax / 100) × (total − itemTax)`.
  - `#shipping-amount` admite `%`.
  - `#others-charge`.
  - Fórmula: `payable = (total + tax) + shipping + others + due − discount`; `totalPayable = payable + installmentInterestAmount`.
  - Descuento o envío ≥ payable → se anulan con aviso.
- **Cliente**: `#customer-name` (autocomplete, `action_type=CUSTOMERLIST`), `#customer-icon`, `CustomerEditModal`, `AddCustomerMobileNumberModal`, `createNewCustomer`, y enlace a `customer_profile.php?customer_id=&type=all_due`.
- **Nota**: `#invoice-note` → `addInvoiceNote()`.
- **Cobro**: `button_pay` → `payNow()` (exige ≥1 línea y cliente) → `PaymentFormModal` → `_inc/template/payment_form.php`.
  - **Métodos de pago**: `pmethods`; el primero se auto-selecciona. Campos por método: `GET _inc/payment.php?action_type=FIELD&pmethod_id=` → incluye `_inc/template/partials/pmethodfield/<nombre>_field.php` (p. ej. `card_no` para tarjeta de regalo).
  - **Pago mixto**: no existe. Sólo un `pmethod_id` por operación. El "mixto" se simula con pago parcial + deuda, o crédito contra saldo del cliente.
  - **Crédito**: exige `payable ≤ get_customer_balance(customer_id)`; descuenta `customer_to_store.balance` y registra `customer_transactions` tipo `substract_balance`.
  - **Tarjeta de regalo**: busca `gift_cards` por `customer_id` + `card_no` + `balance ≥ payable` + `expiry > NOW()`; si falla → `error_not_found_or_insufficient_balance`.
  - **Cuotas**: exige `INSTALLMENT`; valida `installment_duration`, `installment_interval_count`, `installment_count`, `installment_interest_percentage`, `installment_interest_amount`.
  - **Deuda previa**: `previous-due` se resta del payable; el importe pagado se reparte entre facturas vencidas del cliente (`duePaid()`).
  - **Cambio**: `paid > payable` → `due=0`, `balance = paid − payable`, y `paid_amount` se recorta al payable. El cambio queda en `selling_price.balance` y `payments.pos_balance`.
  - **Pedido en espera**: `button_hold` → `HoldingOrderModal()`.
- **Generación de la venta** (`POST _inc/place_order.php` → `ModelInvoice::createInvoice()`, `_inc/model/invoice.php:92`). Orden real de escritura:
  1. por cada ítem: decremento **FIFO** de `purchase_item` + `UPDATE product_to_store.quantity_in_stock`;
  2. `INSERT selling_item`;
  3. `INSERT selling_info`;
  4. `INSERT selling_price`;
  5. `INSERT payments` + `UPDATE selling_info.pmethod_id/checkout_status`;
  6. `INSERT sell_logs`;
  7. `customer_to_store.due`, `customer_transactions`, `gift_cards.balance`, `bank_transaction_info/price`;
  8. `installment_orders` + `installment_payments`;
  9. si venía de cotización (`qref`): `UPDATE quotation_info SET status='complete', invoice_id=?`.
  - ⚠ **Sin transacción SQL** en los 9 pasos.
- **FIFO** (`_inc/model/invoice.php:233-282`): consume lotes `purchase_item` con `status='active'` y `item_quantity > total_sell`; promueve a `active` el lote más antiguo (`status='stock'`, `ORDER BY id ASC LIMIT 1`); consume `min(stock, pendiente)`; marca el lote `sold` al agotarlo; guarda `purchase_invoice_id` en `selling_item`. Guardia de 1000 iteraciones → `error_stock`.
- **Impresión**: según `auto_print` + `remote_printing` → `PrintReceiptModal` → `GET _inc/print.php?data={json}`. Además `printOrder()` / `printBill()`.
- **SMS**: si `invoice_auto_sms` y hay móvil → `POST _inc/sms/index.php` `action_type=SEND`.
- **Tras la venta**: según `after_sell_page` → `receipt_in_new_window` | `receipt_in_popup` | `toastr_msg` | `sweet_alert_msg`. Luego `resetPos()`.
- **Panel lateral de reportes**: lee `shortcut_links` (`type='report' AND status=1`), filtrado por `has_permission('access', $link['permission_slug'])`.
- **Tablas**: `products`, `product_to_store`, `customers`, `customer_to_store`, `pmethods`, `gift_cards`, `printers`, `shortcut_links`, `purchase_item`, `purchase_info`, `selling_info`, `selling_item`, `selling_price`, `payments`, `sell_logs`, `customer_transactions`, `holding_info`, `holding_item`, `holding_price`, `bank_transaction_info`, `bank_transaction_price`, `bank_account_to_store`, `bank_accounts`.

### 3.3 Ventas / facturas (`admin/invoice.php`, `admin/view_invoice.php`, `admin/sell_log.php`)

**`admin/invoice.php` — listado de facturas**
- **Guarda**: `read_sell_list`.
- **Columnas (`dt`)**: `invoice_id`, `created_at`, `customer_name`, `status`, `btn_pay`, `btn_return`, `btn_view`, `btn_edit`, `btn_delete`.
  Cabeceras: `label_invoice_id`, `label_datetime`, `label_customer_name`, `label_status`, `label_pay`, `label_return`, `label_view`, `label_edit`, `label_delete`.
  Ocultables: 4=`sell_payment`, 5=`create_sell_return`, 6=`read_sell_invoice`, 7=`update_sell_invoice_info`, 8=`delete_sell_invoice`.
- **Acciones**: `btn_pay`→`PaymentOnlyModal`, `btn_return`→`SellReturnModal`, `btn_view`→`view_invoice.php?invoice_id=`, `btn_edit`→`InvoiceInfoEditModal`, `btn_delete`→`#delete-invoice`.
- **Filtros**: `button_today_invoice`, `button_all_invoice` (`type=all_invoice`), `button_due_invoice` (`type=due`), `button_all_due_invoice` (`type=all_due`), `button_paid_invoice` (`type=paid`), `button_inactive_invoice` (`type=inactive`); selector `#customer_id`.
- **Endpoints**: `_inc/invoice.php` POST `DELETE` (`delete_sell_invoice`), POST `UPDATEINVOICEINFO`; GET `INVOICEINFOEDIT|INVOICEVIEW|INVOICEDETAILS|INVOICEDUEDETAILS`.
- ⚠ `_inc/invoice.php:434` concatena `$store_id`; `_inc/invoice.php:452` tiene un `||` mal parentizado en el filtro de rango.
- ⚠ El borrado de factura ajusta `purchase_item.total_sell` y `product_to_store.quantity_in_stock` a mano, **sin transacción** (`_inc/invoice.php:73-81`).

**`admin/view_invoice.php` — recibo**
- **Guardas**: `read_sell_invoice`; sin `invoice_id` o sin datos → redirige a `invoice.php`.
- Render del recibo con la plantilla `get_preference('receipt_template')` parseada con `get_postemplate_data($invoice_id)`.
- **Acciones**: `button_print` → `window.printContent('invoice', {title:invoice_id, scrrenSize:'halfScreen'})`; `button_send_sms` (permiso `sms_sell_invoice` + pref `sms_alert`); `button_send_email` (permiso `email_sell_invoice`); `button_back_to_pos`.
- **Endpoints**: `_inc/invoice.php` GET `INVOICEVIEW`; `_inc/sms/index.php`; `_inc/send_email.php`.

**`admin/sell_log.php` — bitácora de cobros**
- **Guarda**: `read_sell_log`.
- **Columnas (`dt`)**: `id`, `created_at`, `type`, `customer_name`, `pmethod`, `created_by`, `amount`, `btn_view`. Cabeceras: `label_serial_no`, `label_created_at`, `label_type`, `label_customer`, `label_pmethod`, `label_created_by`, `label_amount`, `label_view`.
- ⚠ `admin/sell_log.php:100` — el selector de clientes imprime `$the_customer['sup_name']` (campo inexistente) → desplegable vacío.

### 3.4 Cotizaciones (`admin/quotation.php`, `admin/quotation_edit.php`)

**`admin/quotation.php`**
- **Guardas**: `read_quotation`; alta con `create_quotation`.
- **Columnas (`dt`)**: `created_at`, `reference_no`, `created_by`, `customer_name`, `payable_amount`, `status`, `action`.
  Cabeceras: `label_date`, `label_reference_no`, `label_biller`, `label_customer`, `label_total`, `label_status`, `label_action`.
  La columna de acción se oculta si falta cualquiera de `read_quotation`, `update_quotation`, `create_sell_invoice`, `delete_quotation`.
- **Acciones** (dropdown `button_action`): `button_details` (`#view-quotation-btn`), `button_edit` (`quotation_edit.php?reference_no=`), `button_create_sell` (`pos.php?qref=reference_no`), `button_delete`.
- **Filtros**: `button_all`, `button_sent` (`type=sent`), `button_pending`, `button_complete`.
- **Formulario** (`_inc/template/quotation_create_form.php`): `date`, `reference_no`, `quotation-note`, `status` (`sent|pending|complete|canceled`), `customer_id`, `sup_id`, `add_item` (autocomplete), `total-tax`, `total-amount`, `order-tax`, `shipping-amount`, `others-charge`, `discount-amount`, `payable-amount`, `products[i][…]`.
- **Endpoints**: `_inc/quotation.php` POST `CREATE|UPDATE|DELETE`; GET `VIEW` (`_inc/template/quotation_view.php`), GET `DOWNLOAD_AS_PDF` (mPDF, 175×228).

**`admin/quotation_edit.php`**
- **Guarda**: `update_quotation`; sin `reference_no` → redirige.
- ⚠ `ModelQuotation::getQuotationInfo` busca por `reference_no` **o** por `customer_id = $reference_no` (`_inc/model/quotation.php:232`) → puede devolver la cotización de otro cliente.

### 3.5 Pedidos en espera (holding)

No hay pantalla propia: se opera **desde el POS**.
- `button_hold` → `HoldingOrderModal()` → `POST _inc/holding_order.php` `action_type=HOLD`.
- Recuperar: `GET _inc/holding_order.php?action_type=HOLDINGORDERDETAILS` / `HOLDINGORDERDETAILSMODAL`; editar `EDIT`; borrar `DELETE`.
- **Tablas**: `holding_info` (`ref_no` único por tienda, `order_title`), `holding_item`, `holding_price`.
- ⚠ La entrada de menú `menu_statements` → `customer_transaction.php` está **comentada** en `left_sidebar.php:458-465`.

### 3.6 Pedidos (`admin/order.php`, `admin/order_edit.php`) — **MÓDULO ROTO**

- **Guardas**: `read_order` / `update_order`.
- **Columnas (`dt`)**: `created_at`, `reference_no`, `created_by`, `customer_name`, `payable_amount`, `status`, `action`.
- **Acciones**: `button_details`, `button_edit` (`order_edit.php?reference_no=`), `button_create_sell` (`pos.php?qref=`), `button_delete`.
- **Tablas**: `quotation_info`, `quotation_item`, `quotation_price` — las **mismas** que la cotización; se distinguen por `quotation_info.is_order = 1` (`_inc/order.php:408`).
- ⚠⚠ **No enlazado** desde `left_sidebar.php`.
- ⚠ `_inc/order.php:189` llama a `ModelOrder::createOrder()`, **que no existe** → *fatal* al crear un pedido.
- ⚠ `_inc/order.php:312,324,332` hace DELETE sobre `order_item`/`order_info`, **tablas inexistentes**.
- ⚠ `_inc/model/order.php:118-125` compone `WHERE … ORDER BY … DESC LIMIT` → SQL inválido.
- ⚠ `_inc/model/order.php:43` usa `$paid_amount` sin definir en la rama de cuotas.
- ⚠ `ModelQuotation::createQuotation` inserta `quotation_info` **sin `is_order`** → siempre 0 → nunca hay pedidos.
- ⚠ Los permisos de las acciones se evalúan con `&&`/`||` sin paréntesis (`_inc/order.php:510-517`).

**Decisión pendiente:** ¿los pedidos son un módulo real o la cotización con otro nombre? Si el negocio no los usa, se descarta el módulo entero; si los usa, hay que rehacerlo como versión de cotización con su propio estado.

### 3.7 Devoluciones de venta (`admin/sell_return.php`)

- **Guarda**: `read_sell_return`.
- **Columnas (`dt`)**: `created_at`, `reference_no`, `customer`, `amount`, `btn_view`, `btn_edit`, `btn_delete`. Cabeceras: `label_datetime`, `label_reference_no`, `label_customer`, `label_amount`, `label_view`, `label_edit`, `label_delete`.
- **Alta**: desde el POS (`SellReturnModal`) → `POST _inc/sell_return.php?action_type=RETURN` con `invoice-id`, `customer-id`, `note`, `items[i][check|item_id|item_quantity]`.
- **Flujo**: `UPDATE selling_item.return_quantity` → `UPDATE product_to_store.quantity_in_stock +=` → `INSERT return_items` → recálculo de `selling_info.payment_status` y `selling_price.due` → `INSERT returns` → `INSERT payments` (`type='return'`, importe negativo; y `type='change'` si hay cambio) → ajuste de `customer_to_store.due` → **retiro de caja** con `exp_category_id = expense_categorys.sell_return`.
- ⚠ `btn_edit`/`btn_delete` **siempre devuelven `'-'`** (`_inc/sell_return.php:443,450`) → columnas decorativas que prometen acciones inexistentes.
- ⚠ La tabla usa el id DOM `invoice-invoice-list`, **el mismo** que `admin/invoice.php`.
- ⚠ `_inc/sell_return.php:142-153` actualiza `purchase_item` con `invoice_id` = factura de venta en un UPDATE y con `purchase_invoice_id` en otro → lotes inconsistentes.
- ⚠ `_inc/sell_return.php:284` puede dejar `customer_to_store.due` **negativo**.
- ⚠ Sin transacción.

### 3.8 Cuotas (`admin/installment.php`, `admin/installment_payment.php`, `admin/installment_overview.php`)

**`admin/installment.php` — planes**
- **Guarda**: `read_installment`.
- **Columnas (`dt`)**: `created_at`, `invoice_id`, `customer_name`, `duration`, `interval_count`, `installment_count`, `btn_view`, `btn_delete`. Cabeceras: `label_created_at`, `label_invoice_id`, `label_customer`, `label_duration`, `label_interval`, `label_total_ins`, `button_view`, `button_delete`. Los formateadores anexan `" Days"`.
- **Filtros**: `button_all_installment`, `button_due_installment` (`type=due`), `button_paid_installment` (`type=paid`).
- **Endpoints**: `_inc/installment.php` POST `PAY` (permiso `installment_payment`), POST `DELETE` (`delete_installment`), GET `PAY`, GET `VIEW`.

**`admin/installment_payment.php` — cobros**
- **Guarda**: `installment_payment` (slug **sin** `read_`).
- **Columnas (`dt`)**: `payment_date`, `ref_no`, `note`, `payable`, `paid`, `payment_status`, `btn_payment`. Cabeceras: `label_payment_date`, `label_invoice_id`, `label_note`, `label_payable`, `label_paid`, `label_status`, `button_payment`.
- **Filtros**: `button_all_payment` (`type=all_payment`), `button_todays_due_payment`, `button_all_due_payment`, `button_expired_due_payment`.
- **Formulario** (`_inc/template/installment_payment_form.php`): `action_type=PAY`, `id`, `amount`, `note`.
- **Flujo de cobro**: `UPDATE installment_payments` (`paid`, `due`, `payment_status`) → `UPDATE installment_orders.last_installment_date` → cierre del plan si no quedan cuotas → `UPDATE selling_price` (`paid_amount`, `due_paid`, `due`) → `INSERT payments` (`type='due_paid'`, `pmethod_id=1` **fijo**) → **depósito en caja** (`source_id=1`).
- ⚠ `_inc/installment_payment.php` es **copia literal** de `_inc/installment.php`.
- ⚠ `UPDATE installment_orders SET last_installment_date = ?` **sin `WHERE invoice_id`** → actualiza **todas** las filas (`_inc/installment.php:94`, `_inc/installment_payment.php:94`).
- ⚠ `UPDATE selling_price … WHERE invoice_id = ?` **sin `store_id`** → toca facturas de otras tiendas (`_inc/installment.php:106`).
- ⚠ `type=all_payment` **no tiene `case`** en el switch → no filtra.
- ⚠ `todays_due_payment` compara `payment_date` con hoy, no con la fecha de vencimiento.
- ⚠ El DELETE hace **cascada manual sobre 8 tablas** sin transacción (`_inc/installment.php:199-228`).

**`admin/installment_overview.php`** — informe agregado (partial `_inc/template/partials/report_installment_overview.php`). Guarda: `installment_overview` (sin `read_`).

### 3.9 Extracto de cuenta del cliente (`admin/customer_transaction.php`)

- **Guardas**: `read_customer_transaction`; exige `customer_id` y que el cliente exista (si no → `customer.php`).
- **Columnas (`dt`)**: `id`, `created_at`, `type`, `category_name` (**el nombre del cliente**, heredado del copiado de gastos), `pmethod`, `created_by`, `amount`, `balance`, `btn_view`. Cabeceras: `label_serial_no`, `label_created_at`, `label_type`, `label_customer`, `label_pmethod`, `label_created_by`, `label_amount`, `label_balance`, `label_view`.
- **Tipos**: `purchase`, `add_balance`, `substract_balance`, `due_paid`, `others`. ⚠ El formatter pinta `purchase` como **"Sell"**.
- ⚠ `customer_id` se concatena en el SQL (`_inc/customer_transaction.php:48`) → inyección.

### 3.10 Compras (`admin/purchase.php`, `admin/purchase_log.php`)

**`admin/purchase.php` — listado y alta**
- **Guardas**: `read_purchase_list`; alta con `create_purchase_invoice`.
- **Columnas (`dt` en JS)**: `created_at`, `invoice_id`, `sup_name`, `created_by`, `invoice_amount`, `paid_amount`, `due`, `status`, `btn_pay`, `btn_return`, `btn_view`, `btn_edit`, `btn_delete`.
  Cabeceras: `label_datetime`, `label_invoice_id`, `label_supplier`, `label_creator`, `label_amount`, `label_invoice_paid`, `label_due`, `label_status`, `label_pay`, `label_return`, `label_view`, `label_edit`, `label_delete`.
- **Acciones**: `btn_pay`→`PurchasePaymentModal`, `btn_return`→`PurchaseReturnModal`, `btn_view`→`#view-invoice-btn`, `btn_edit`→`PurchaseInvoiceInfoEditModal`, `btn_delete`→`#delete-invoice`.
- **Filtros**: `button_today_invoice`, `button_all_invoice`, `button_due_invoice`, `button_all_due_invoice`, `button_paid_invoice`, `button_inactive_invoice`.
- **Formulario** (`_inc/template/purchase_create_form.php`): `action_type=CREATE`, `date`, `reference_no`, `purchase-note`, `status` (`received|pending|ordered`), `image`, `sup_id`, `add_item`, `total-tax`, `total-amount`, `order-tax`, `shipping-amount`, `others-charge`, `discount-amount`, `payable-amount`, `pmethod-id`, `paid-amount`, `due-amount`, `change-amount`.
- **Autocomplete de producto**: `POST _inc/ajax.php?type=PURCHASEITEM` con `sup_id`, `type` (allowlist `p_id|p_name|p_code`), `name_starts_with` → devuelve un string pipe-separado `p_id|p_name|p_code|category_id|quantity_in_stock|unit|purchase_price|sell_price|tax_amount|tax_method|taxrate|quantity_in_stock`.
- **Validaciones de línea**: `quantity > 0`, `purchase_price > 0`, `sell_price > 0` y **prohíbe `purchase_price > sell_price`** (`_inc/purchase.php:146-162`).
- **Entrada de stock**: `INSERT purchase_item` con `status` = `stock` (lote pendiente) o `active` (disponible) según hubiera stock antes; `UPDATE product_to_store` (`purchase_price`, `sell_price`, `quantity_in_stock += qty`).
- **Pagos**: `paid-amount` → `INSERT purchase_payments` + `checkout_status=1`; si `due > 0` → `INSERT purchase_logs(type='due')` + `supplier_to_store.balance += due`; si no → `payment_status='paid'`.
- **Banca**: si `deposit_account_id` y `paid > 0` → movimiento `withdraw`.
- **Pago posterior**: `POST _inc/purchase_payment.php?action_type=PAYMENT` (permiso `purchase_payment`) → `purchase_payments(type='due_paid')` → `purchase_price.paid_amount/due_paid/due` → `purchase_info.payment_status` → `supplier_to_store.balance -=` → `purchase_logs(type='due_paid')` → retirada bancaria.
- **Adjuntos**: `storage/purchase-invoices/<invoice_id>.<ext>`.
- ⚠ `_inc/purchase.php:166` valida `$_FILES["image"]` pero la subida real usa `$_FILES["attachment"]` (`:275`) → **la validación nunca aplica**.
- ⚠ `admin/purchase.php:161` → `$hide_colums .= "1101,"` (índice inválido).
- ⚠ `admin/purchase.php:171` reutiliza el id DOM `invoice-invoice-list`.
- ⚠ Al borrar una compra, el ajuste de stock agrupa por `status` y usa **un único `item_id`** (`:390-408`) → sólo revierte el stock de un producto.
- ⚠ `invoice_id = get_invoice_prefix('purchase') . $request->post['reference_no']` → el prefijo + referencia escrita por el usuario.

**`admin/purchase_log.php` — bitácora de pagos a proveedor**
- **Guarda**: `read_purchase_log`.
- **Columnas (`dt`)**: `id`, `created_at`, `type`, `sup_name`, `pmethod`, `created_by`, `amount`, `btn_view`.

**`admin/purchase_edit.php`** — ⚠ **huérfana**: exige `quotation_edit` y monta `_inc/template/quotation_edit_form.php` con el modelo de cotización. `purchase_edit_form.php` y `PurchaseEditController.js` existen sin usar.

### 3.11 Devoluciones de compra (`admin/purchase_return.php`)

- **Guarda**: `read_purchase_return`.
- **Columnas (`dt`)**: `created_at`, `reference_no`, `supplier`, `amount`, `btn_view`, `btn_edit`, `btn_delete`.
- **Alta**: desde la pantalla de compra → `POST _inc/purchase_return.php?action_type=RETURN` (permiso `purchase_return`) con `invoice-id`, `sup-id`, `note`, `items[i][check|item_id|item_quantity]`.
- Valida la cantidad contra `purchase_item.item_quantity` **y** contra el disponible real (`item_quantity − total_sell`). Marca `purchase_item.return_quantity`, descuenta `product_to_store.quantity_in_stock`, inserta `purchase_returns` + `purchase_return_items`, genera `purchase_payments(type='return')` y **depósito en caja** (`source_id = income_sources.for_purchase_return`).
- ⚠ Sin transacción.

### 3.12 Productos y catálogos

**`admin/product.php` — catálogo**
- **Guardas**: `read_product`; alta con `create_product`.
- **Columnas (`dt`)**: `select`, `p_image`, `p_code`, `p_name`, `supplier`, `quantity_in_stock`, `purchase_price`, `sell_price`, `view_btn`, `edit_btn`, `purchase_btn`, `barcode_btn`, `delete_btn`.
  Cabeceras: (checkbox), `label_image`, `label_pcode`, `label_name`(`text_product`), `label_supplier`, `label_stock`, `label_purchase_price`, `label_selling_price`, `label_view`, `label_edit`, `label_purchase`, `label_print_barcode`, `label_delete`.
  `data-print-columns="2,3,4,5,6,7"` (sin 6 si no `show_purchase_price`). Ocultables: 0=`product_bulk_action`, 6=`show_purchase_price`, 8=`read_product`, 9=`update_product`, 10=`create_purchase_invoice`, 11=`print_barcode`, 12=`delete_product`.
- **Acciones**: `button_trash` → `product.php?location=trash` (badge `total_trash_product()`), `button_bulk` → `button_delete_all` (`#delete-all` → `_inc/product_bulk_action.php`) / `button_restore_all`, `button_save`, `button_reset`.
- **Formulario** (`_inc/template/product_create_form.php`, pestañas General/Images): `p_image`, `p_type` (`standard|service`), `p_name`, `p_code` (botón `#random_num`), `category_id`, `sup_id`, `brand_id`, `barcode_symbology` (`code25|code39|code128|ean5|ean13|upca|upce`), `box_id`, `e_date` (sólo con `expiry_yes`), `unit_id`, `hsn_code` (sólo `invoice_view=indian_gst`), `purchase_price`, `sell_price`, `taxrate_id`, `tax_method` (`inclusive|exclusive`), `product_store[]` (multi-tienda), `alert_quantity`, `description`, `status`, `sort_order`, `image[i][url]`, `image[i][sort_order]`.
- **Endpoints**: `_inc/product.php` POST `CREATE|UPDATE|DELETE`, GET `CREATE|EDIT|DELETE|VIEW`; `_inc/product_bulk_action.php`; `_inc/ajax.php?type=PRODUCTIMAGES`.
- ⚠ El listado agrupa `GROUP BY products.p_id` → en multi-store devuelve **una sola fila por producto**.
- ⚠ `validate_existance` compara por `p_name` → no se puede duplicar nombre, pero **sí el código**.

**`admin/product_details.php` — ficha**
- **Pestañas**: `text_details` (`_inc/template/product_view_form.php`), `text_stock_register` (permiso `read_purchase_report`), `text_chart` (Chart.js: precio de venta vs de compra).
- **Columnas del registro (`dt`)**: `info_id`, `created_at`, `invoice_id`, `purchase`, `sell`, `quantity`, `sold`, `available`, `status`. Cabeceras: `label_serial_no`, `label_created_at`, `label_invoice_id`(product), `label_purchase_price`, `label_selling_price`, `label_quantity`, `label_sold`, `label_available`, `label_status`.
- **Acciones**: `button_sell_report` → `report_sell_itemwise.php?p_id=&p_name=`; `button_edit`.
- ⚠ Oculta la columna 3 con el slug `view_purchase_price`, **distinto** del `show_purchase_price` del listado.

**`admin/import_product.php` — importación XLS**
- **Guarda**: `import_product`; en POST se repite el permiso → **en modo demo siempre falla**.
- Formulario `multipart/form-data`: `filename` (sólo `.xls`), botón `button_import`. Descarga de ejemplo: `../storage/pos-products.xls`.
- **Columnas del XLS** (hoja `Product`, índice base 0): 1 ProductName, 2 ProductType, 3 Code, 4 HSN, 5 BarcodeSymbology, 6 StoreCodes (CSV), 7 CategorySlug, 8 UnitCode, 9 TaxrateCode, 10 TaxMethod, 11 SupplierCode, 12 BrandCode, 13 BoxCode, 14 AlertQuantity, 15 CostPrice, 16 SellPrice, 17 Description, 18 Status, 19 Thumbnail, 20 Images (separadas por `|`).
- **Upsert por `p_name`**: si no existe → `INSERT products` + `INSERT product_to_store` por tienda; si existe → `UPDATE products` + **DELETE+INSERT** de `product_to_store` → se pierden ajustes de stock/preferencias de esa tienda.
- Hooks `Before_Import_Product` / `After_Import_Product`.
- ⚠ La condición `!$x < 0` para `alert_quantity` es un no-op por precedencia.

**Catálogos simples** — misma receta de 5 archivos, patrón de columnas (`id`, nombre, `total_item`/`total_product`, `sort_order`, `status`, `created_at`, `btn_edit`, `btn_delete`):

| Pantalla | Tabla | Columnas (`dt`) | Campos del formulario |
|---|---|---|---|
| `admin/category.php` | `categorys` | `category_id`, `category_name`, `total_item`, `sort_order`, `status`, `created_at`, `btn_edit`, `btn_delete` | `category_name`, `category_slug`, `parent_id`, `category_details`, `category_image`, `status`, `sort_order` |
| `admin/brand.php` | `brands` | `brand_id`, `brand_name`, `total_product`, `status`, `btn_view`, `btn_edit`, `btn_delete` | `brand_name`, `code_name`, `brand_image`, `brand_store[]`, `status`, `sort_order` |
| `admin/box.php` | `boxes` | `box_id`, `box_name`, `total_product`, `box_details`, `status`, `btn_edit`, `btn_delete` | `box_name`, `code_name`, `box_details`, `status`, `sort_order` |
| `admin/unit.php` | `units` | `unit_id`, `unit_name`, `total_product`, `unit_details`, `status`, `btn_edit`, `btn_delete` | `unit_name`, `code_name`, `unit_details`, `status`, `sort_order` |
| `admin/taxrate.php` | `taxrates` | `taxrate_id`, `taxrate_name`, `code_name`, `taxrate`, `status`, `btn_edit`, `btn_delete` | `taxrate_name`, `code_name`, `taxrate`, `status`, `sort_order` |
| `admin/currency.php` | `currency` | `currency_id`, `title`, `code`, `symbol_left`, `symbol_right`, `decimal_place`, `status`, `btn_edit`, `btn_delete`, `btn_activate` | `title`, `code`, `symbol_left`, `symbol_right`, `decimal_place`, `status` |
| `admin/pmethod.php` | `pmethods` | `pmethod_id`, `name`, `sort_order`, `details`, `status`, `btn_edit`, `btn_delete` | `name`, `sort_order`, `details`, `status` |
| `admin/printer.php` | `printers` | `printer_id`, `title`, `type`, `path`, `ip_address`, `port`, `status`, `btn_edit`, `btn_delete` | `title`, `type` (`network|windows|linux`), `char_per_line`, `path`, `ip_address`, `port`, `printer_store[]`, `status`, `sort_order` |
| `admin/banner.php` | `banners` | `id`, `name`, `sort_order`, `status`, `created_at`, `btn_edit`, `btn_delete` | `name`, `sort_order`, `status` |

⚠ `admin/banner.php` — **huérfano** (no enlazado desde el menú) y sus 4 slugs (`read_banner`, …) **no existen** en la matriz de permisos → sólo accesible por `group_id=1`. Además `hide_colums` usa índices 6 y 7 cuando las columnas de acción son 5 y 6 → oculta el botón de **borrar** y nunca el de **editar**.
⚠ `admin/printer.php` — el botón "ver" es un stub (`swal('Attention!', 'this action is under development')`).

### 3.13 Transferencias entre almacenes (`admin/transfer.php`)

- **Guardas**: `read_transfer`; alta con `add_transfer`. `?type=receive` filtra las recibidas.
- **Columnas (`dt`)**: `created_at`, `ref_no`, `from_store`, `to_store`, `total_item`, `total_quantity`, `btn_view`, `btn_edit`. Cabeceras: `label_date`, `label_ref_no`, `label_from_store`, `label_to_store`, `label_total_item`, `label_total_quantity`, `label_view`, `label_edit`.
- **Acciones**: `btn_view` (`TransferDetailsViewModal`), `btn_edit` (`#transfer-edit`, sólo con `status` `pending`/`sent` → `TransferEditModal` → `_inc/template/transfer_edit_form.php`).
- **Formulario** (`_inc/template/transfer_add_form.php`): `action_type=TRANSFER`, `ref_no`, `status` (`complete|pending|sent`), `note`, `from_store_id` (oculto = tienda actual), `to_store_id`, `image`, `items[i][id|quantity]`; origen de datos: `_inc/ajax.php?type=STOCKITEMS&store_id=` y luego `type=STOCKITEM&id=&quantity=`.
- **Flujo**: por cada ítem se crea `purchase_info(inv_type='transfer')` + `purchase_price` + `purchase_item` en la tienda destino; se resta cantidad e importe del `purchase_item` origen; si `status='complete'` se mueve stock (`addProductAndQuantity`, `_inc/transfer.php:59-121`). El `UPDATE` cambia el estado y ejecuta el movimiento si pasa a `complete`.
- ⚠ `_inc/transfer.php:73` — el `INSERT` de `product_to_store` en la tienda destino **omite `brand_id`** → productos sin marca en la tienda receptora.
- ⚠ `_inc/transfer.php:169-275` — una transferencia de N ítems genera **N filas** en `transfers`/`transfer_items` y N `purchase_info` de tipo `transfer` → el listado de compras se multiplica.
- ⚠ `_inc/transfer.php:232` — `purchase_payments` se inserta con `invoice_id` = factura **origen**, no la de transferencia.
- ⚠ El stock del origen se resta **al final**, después de haber insertado el `purchase_item` destino.

### 3.14 Clientes (`admin/customer.php`, `admin/customer_profile.php`)

**`admin/customer.php` — listado**
- **Guardas**: `read_customer`; alta con `create_customer`.
- **Columnas**: `customer_id`(`label_id`), `customer_name`(`label_name`), `customer_mobile`(`label_phone`), `customer_sex`(`label_sex`), `balance`(`label_balance`), `btn_pos`(`label_sell`), `btn_profile`(`label_view`), `btn_edit`(`label_edit`), `btn_delete`(`label_delete`).
- **Acciones**: `btn_pos` → `pos.php?customer_id=` (permiso `create_sell_invoice`), `btn_profile` → `customer_profile.php?customer_id=&type=all_invoice` (`read_customer_profile`), `btn_edit`, `btn_delete` (deshabilitado para `customer_id=1`).
- **Formulario** (`_inc/template/customer_create_form.php`): `customer_name` (req), `credit_balance`, `customer_mobile`, `dob`, `customer_email` (prefill `unique_id(6)@gmail.com`), `customer_sex` (1/2/3), `customer_age`, `gtin` (sólo `invoice_view=indian_gst`), `customer_address`, `customer_city`, `customer_state` (req sólo indian_gst), `customer_country`, `customer_store[]`, `status`, `sort_order`.
- **Validaciones**: `validateString(customer_name)`, `isItValidDate(dob)`, email XOR móvil, `validateInteger(customer_sex)`, `customer_store` no vacío, unicidad de email y móvil.
- **Endpoints**: `_inc/customer.php` POST `CREATE|UPDATE|DELETE|SUBSTRACTBALANCE|ADDBALANCE`; GET los mismos (fragmento HTML).
- ⚠ El `credit_balance` del alta se registra con `pmethod_id = 1` **fijo**.
- ⚠ Al borrar, `customer_transactions.customer_id` se reasigna a `$new_customer_id` **aunque** `delete_action != 'insert_to'` → deja `customer_id` a NULL.
- ⚠ `_inc/customer.php:110,313` concatena el importe en el `UPDATE` (`SET balance = balance + {$amount}`).

**`admin/customer_profile.php` — ficha**
- **Guarda**: `read_customer_profile`; `customer_id` obligatorio y debe existir.
- **KPIs**: `customer_total_invoice()`, `customer_total_purchase_amount()`, `balance`/`due` de `customer_to_store`.
- **Columnas (`dt`)**: `created_at`, `invoice_id`, `invoice_note`, `items`, `invoice_amount`, `previous_due`, `payable_amount`, `paid_amount`, `due`, `btn_view`, `btn_pay`. Cabeceras: `label_date`, `label_invoice_id`, `label_note`, `label_items`, `label_total_item`, `label_previous_due`, `label_payable_amount`, `label_paid_amount`, `label_due`, `label_view`, `label_pay`.
- **Acciones**: `customerEdit()`, botón venta → `pos.php?customer_id=`, `addCustomerBalance()`, `substractCustomerBalance()`, `customer_transaction.php?customer_id=`, filtros `button_today_invoice`/`all_invoice`/`due`/`all_due`/`paid`/`inactive`.
- ⚠ El `aoColumns` del JS está desalineado con el array `$columns` del PHP — el emparejamiento real es **por nombre** (`SSP::data_output`, `_inc/lib/ssp.class.php:43`), no por posición.

### 3.15 Proveedores y marcas (`admin/supplier.php`, `admin/supplier_profile.php`)

**`admin/supplier.php`**
- **Guardas**: `read_supplier`; alta con `create_supplier`.
- **Columnas**: `sup_id`, `sup_name`, `sup_mobile`, `total_product`, `created_at`, `status`, `btn_purchase`, `btn_view`, `btn_edit`, `btn_delete`. Ocultables: 6=`create_purchase_invoice`, 7=`read_supplier_profile`, 8=`update_supplier`, 9=`delete_supplier`.
- **Acciones**: `btn_purchase` → `purchase.php?box_state=open&sup_id=` (deshabilitado si `total_product_of_supplier <= 0`), `btn_view` → `supplier_profile.php?sup_id=`, `btn_edit`, `btn_delete`.
- **Formulario**: `sup_name` (req), `code_name` (req), `sup_email` (req), `sup_mobile` (req), `gtin`, `sup_address` (req), `sup_city`, `sup_state`, `sup_country`, `supplier_store[]`, `sup_details`, `status`, `sort_order`.
- **Al borrar**: reasigna `holding_item`, `purchase_logs`, `purchase_returns`, `quotation_item`, `product_to_store`, `purchase_info`, `selling_item` (según `delete_action`).
- ⚠ `validate_existance` y el chequeo duplicado de `sup_name` se ejecutan **dos veces** (`_inc/supplier.php:79-85` y `:122-127`).

**`admin/supplier_profile.php`**
- **Guarda**: `read_supplier_profile`; `sup_id` en GET, inexistente → redirige.
- **Pestañas**: Compras / Ventas / Gráfico.
  - **Compras** — tabla con id DOM `#product-product-list` (⚠ el mismo que `stock_alert.php` y `expired.php`): `label_type`, `label_invoice_id`, `label_datetime`, `label_supplier`, `label_creator`, `label_invoice_amount`, `label_invoice_paid`, `label_due`, `label_status`, `label_pay`, `label_return`, `label_view`, `label_edit`, `label_delete`.
  - **Ventas** — `#report-report-list`: `label_serial_no`, `label_invoice_id`, `label_created_at`, `label_sup_name`, `label_quantity`, `label_purchase_price`, `label_selling_price`, `label_tax_amount`, `label_discount_amount`, `label_profit`.
- **Acciones**: `product.php?sup_id=`, `supplier.php?sup_id=&sup_name=` (editar), `purchase.php?box_state=open&sup_id=`, `purchase_log.php?sup_id=`, filtros `button_all_purchase`/`due`/`paid`/`stock_transfer`.
- ⚠ `hide_colums` duplica el índice 12 para dos permisos distintos (`update_purchase_invoice_info` y `delete_purchase_invoice`).

**`admin/brand_profile.php`** — ficha de marca: pestañas Detalles / Ventas. `dt`: `label_serial_no`, `label_invoice_id`, `label_created_at`, `label_brand_name`, `label_quantity`, `label_purchase_price`, `label_selling_price`, `label_tax_amount`, `label_discount_amount`, `label_profit`. Endpoints: `_inc/brand_profile.php`, `_inc/report_sell_the_brandwise.php?brandid=`.
⚠ `print_columns`/`hide_colums` recalculados por permisos `show_purchase_price`/`show_profit`/`view_purchase_price`/`view_profit` — slugs de *impresión* distintos de los de *vista*.

### 3.16 Contabilidad

#### Banca

| Pantalla | Guardas | Columnas (`dt`) | Acciones |
|---|---|---|---|
| `admin/bank_account.php` | `read_bank_account`; alta `create_bank_account` | `id`(`label_id`), `account_name`, `account_details`, `status`, `btn_edit`, `btn_delete` | `#edit-account`, `#delete-account`, `#create-account-submit` |
| `admin/bank_transactions.php` | `read_bank_transactions` | `created_at`, `banking_id`, `transaction_type`, `title`(cuenta), `deposit`, `withdraw`, `balance`, `btn_view` | filtro por cuenta + rango; `.view-deposit`/`.view-withdraw`; `#withdraw-btn`, `#deposit-btn`; botones DataTables + `#email-btn` |
| `admin/bank_transfer.php` | `read_bank_transfer` | `id`(=`ref_no`), `created_at`, `from_account`, `account`(destino), `amount` | `#transfer-btn` → `BankTransferModal` |
| `admin/bank_account_sheet.php` | `read_bank_account_sheet` | `id`, `account_name`, `deposit`, `withdraw`, `transfer_to_other`, `transfer_from_other`, `balance` | sólo impresión + `#email-btn` |

- ⚠ **No existe `admin/banking.php`**. La UI de banca son modales abiertos desde el menú: `BankingDepositModal()`, `BankingWithdrawModal()`, `BankTransferModal()`.
- **Endpoints** (`_inc/banking.php`): POST `action_type=WITHDRAW|DEPOSIT|TRANSFER`; GET `action_type=WITHDRAW|DEPOSIT|TRANSFER` (formularios) y `action_type=VIEW` + `invoice_id` + `view_type`.
- **Formularios**: retiro → `action_type=WITHDRAW`, `image`, `exp_category_id`*, `account_id`*, `ref_no`*, `title`*, `amount`*, `details`. Depósito → `action_type=DEPOSIT`, `image`, `source_id`, `account_id`*, `ref_no`*, `title`*, `amount`*, `capital` (oculto por `showCapital`), `details`. Transferencia → `action_type=TRANSFER`, `from_account_id`*, `to_account_id`*, `ref_no`*, `title`*, `amount`*, `details`.
- **Saldo**: `balance = (deposit + transfer_from_other) − (withdraw + transfer_to_other)` (`_inc/model/bankaccount.php:133`, `_inc/bank_account_sheet.php:94`).
- ⚠ `bank_accounts.initial_balance` existe en el esquema pero **no está en el formulario**.
- ⚠ `bank_accounts.id = 1` no se puede borrar.
- ⚠ `_inc/banking.php:374` concatena `account_id` en el `where_query` → inyección.
- ⚠ El saldo se comprueba dos veces (`_inc/banking.php:42` y `:63`) — bien, pero sin transacción entre ambas.
- ⚠ El filtro `transaction_type IN ('deposit','withdraw')` de `bank_transactions` **excluye las transferencias**.
- ⚠ El sidebar enlaza `bank_transactions.php?type=report` y `type` **no se usa** en el handler.

#### Gastos e ingresos

| Pantalla | Guardas | Columnas (`dt`) |
|---|---|---|
| `admin/expense.php` | `read_expense`; alta `create_expense` | `id`(`label_serial_no`), `title`, `category_name` (con `Parent > Child`), `amount`, `created_at`, `btn_view`, `btn_edit`, `btn_delete` |
| `admin/expense_category.php` | `read_expense_category`; alta `create_expense_category` | `category_id`, `category_name`, `total_item`, `sort_order`, `status`, `created_at`, `btn_edit`, `btn_delete` |
| `admin/income_source.php` | `read_income_source`; alta `create_income_source` | `source_id`, `source_name`, `total_item`, `sort_order`, `status`, `created_at`, `btn_edit`, `btn_delete` |
| `admin/expense_monthwise.php` | `read_expense_monthwise` | `Sl.` + una columna por categoría + `Total` (render PHP, sin DataTables) |
| `admin/income_monthwise.php` | `read_income_monthwise` | `label_serial_no`, `label_capital`, `label_profit`, una por fuente, `Total` |

- **Formulario de gasto**: `action_type=CREATE`, `sup_id`, `image`, `reference_no`*, `category_id`*, `title`*, `amount`*, `returnable` (`no|yes`), `note`. El `reference_no` se prefija `EXP` y debe ser único.
- **Formulario de categoría de gasto**: `category_name`*, `category_slug`*, `parent_id`, `category_details`, `status`*, `sort_order`*. Unicidad de `category_slug`.
- **Formulario de fuente de ingreso**: `source_name`*, `source_slug`* (readonly, autogenerado), `parent_id`, `source_details`, `status`*, `sort_order`*. Unicidad de `source_slug`.
- ⚠ **Los flags contables de `income_sources` y `expense_categorys` no se editan desde la UI** (`for_sell`, `for_loan`, `for_topup`, `sell_return`, `topup_delete`, `profitable`, `show_in_income`, `type`…). El sistema contable depende de filas que **sólo se cambian por SQL**.
- ⚠ `_inc/expense_category.php:294` y `_inc/income_source.php:287` filtran `is_hide != 1`, y **todas** las categorías/fuentes operativas del dump están `is_hide=1` → listados vacíos en una base recién importada.
- ⚠ `_inc/expense.php:69,179` — la validación del adjunto usa `!x == y` (siempre falso) → no valida tipo ni tamaño.
- ⚠ `_inc/expense.php:122,228,252,321` — los movimientos de gasto suman a `bank_accounts.total_deposit` en vez de `total_withdraw` → contadores globales incorrectos.
- ⚠ `_inc/model/income.php:96` — los informes de gasto leen de `bank_transaction_info`, **no** de `expenses`: un gasto sin `deposit_account_id` no aparece en ningún informe.
- ⚠ `_inc/model/income.php:78` vs `getTotalIncome` — dos criterios de ingreso incompatibles (`is_substract=1` se resta en uno y no en el otro).
- ⚠ N+1: 3 consultas por celda y día en los informes monthwise.

#### Préstamos

- `admin/loan.php` (guarda `read_loan`, alta `take_loan`): `dt` → `label_serial_no`, `label_date`→`created_at`, `label_loan_from`, `label_basic_amount`→`amount`, `label_interest`, `label_payable`, `label_paid`, `label_due`, `btn_pay`, `btn_view`, `btn_edit`, `btn_delete`. Filtros `text_all`/`text_paid`/`text_due` (`?type=paid|due`).
  Formulario `loan_take_form.php`: `action_type=TAKE`, `date`*, `loan_from`*, `ref_no`*, `title`*, `amount`*, `interest`*, `payable` (readonly), `details`, `image`, `status`*, `sort_order`*, `loan_store[]`.
  Endpoints `_inc/loan.php`: `TAKE|UPDATE|DELETE|PAID` + GET `TAKE|EDIT|DELETE|VIEW|PAY`.
- `admin/loan_summary.php` (guarda `read_loan_summary`): ficha + últimos pagos. `dt` → `label_serial_no`, `label_datetime`→`created_at`, `label_ref_no`, `label_created_by`, `label_note`, `label_paid`.
- ⚠ `payable = amount + (interest/100) × amount` y `due = payable` se calculan **en el modelo** (`_inc/model/loan.php:68`).
- ⚠ `editLoan` **no** actualiza `amount`, `interest`, `payable` ni `due` (`_inc/model/loan.php:109`).
- ⚠ `admin/loan_summary.php:12` exige `read_loan_summary` pero `_inc/loan_payment.php:17` exige `read_loan` → pantalla vacía o 422 según el rol.
- ⚠ `loan_payments.lloan_id` — typo en el nombre de la FK.
- ⚠ El pago valida `paid <= due` contra un `due` leído antes, sin transacción → sobrepago concurrente.

### 3.17 Reportes (21 pantallas)

Todas comparten: `header.php` + `left_sidebar.php`, `partials/apply_filter.php` (rango `from`/`to` con atajos `button_today`, `button_last_7_days`, `button_last_30_days`, `button_last_365_days`), y botones DataTables Print/Copy/Excel/CSV/PDF.

| Pantalla | Permiso | Columnas (`dt`) |
|---|---|---|
| `report_overview.php` | `read_overview_report` | parciales: KPIs + top lists (tabs `text_sell_overview` / `text_purchase_overview`) |
| `report_collection.php` | `read_collection_report` | `sl`, `username`, `invoice_count`, `net_amount`, `prev_due_collection`, `due_collection`, `due_given`, `received_amount` |
| `report_customer_due_collection.php` | `read_customer_due_collection_report` | `created_at`, `invoice_id`, `pmethod_name`, `created_by`, `amount`, `btn_view` |
| `report_supplier_due_paid.php` | `read_supplier_due_paid_report` | `created_at`, `invoice_id`, `pmethod_name`, `created_by`, `amount`, `btn_view` |
| `report_sell_itemwise.php` | `read_sell_report` | `sl`, `selling_date`, `item_name`, `total_item`, `purchase_price`, `sell_price` |
| `report_sell_categorywise.php` | `read_sell_report` | `id`, `selling_date`, `category_name`, `total_item`, `purchase_price`, `sell_price` |
| `report_sell_supplierwise.php` | `read_sell_report` | `serial_no`, `selling_date`, `sup_name`, `total_item`, `purchase_price`, `sell_price` |
| `report_sell_payment.php` | `read_sell_payment_report` | `id`, `created_at`, `type`, `ref_no`, `pmethod_name`, `note`, `amount` |
| `report_sell_tax.php` | `read_sell_tax_report` | `created_at`, `invoice_id`, `product_tax`, `order_tax`, `total_amount` |
| `report_tax_overview.php` | `read_tax_overview_report` | `tax_percent`, `count`, `subtotal`, `tax_amount`, `total` |
| `report_purchase_itemwise.php` | `read_purchase_report` | `p_id`, `created_at`, `item_name`, `total_item`, `purchase_price`, `paid_amount` |
| `report_purchase_categorywise.php` | `read_purchase_report` | `category_id`, `created_at`, `category_name`, `total_item`, `purchase_price`, `paid_amount` |
| `report_purchase_supplierwise.php` | `read_purchase_report` | `sup_id`, `created_at`, `sup_name`, `total_item`, `purchase_price`, `paid_amount` |
| `report_purchase_payment.php` | `read_purchase_payment_report` | `id`, `created_at`, `type`, `ref_no`, `pmethod_name`, `note`, `amount` |
| `report_purchase_tax.php` | `read_purchase_tax_report` | `created_at`, `invoice_id`, `tax_amount` |
| `report_stock.php` | `read_stock_report` | `p_id`, `sl`, `sup_name`, `product_name`, `available`, `sell_price`, `purchase_price` |
| `report_profit_and_loss.php` | `read_profit_and_loss_report` | `serial_no`→`price_id`, `title`, `this_month`, `this_year`, `till_now` |
| `report_income_and_expense.php` | `read_income_and_expense_report` | ingresos y gastos: `serial_no`, `title`, `this_month`, `this_year`, `till_now` |
| `report_cashbook.php` | `read_cashbook_report` | `#income-income-list` y `#expense-expense-list`: `serial_no`, `title`, `amount` |
| `report_pos_overview.php` | `read_profit_and_loss_report` | ⚠ **huérfano**, clon de P&G |

- **Dropdown de agrupación** (ventas y compras): `button_itemwise` / `button_categorywise` / `button_supplierwise`.
- ⚠ `report_tax_overview.php` — la pestaña `text_purchase_tax` muestra **"Unavailable Now…"**: la mitad de compras no está implementada.
- ⚠ `report_sell_itemwise.php:125-130` — `$hide_colums = "4,"` y luego se añade `"4,"` otra vez → la columna de precio de compra queda **duplicada y siempre oculta**.
- ⚠ Desalineación HTML/JSON en varios informes: el backend define 10-11 `dt` (incluye `tax`, `discount`, `profit`) y la vista 6 → los datos viajan pero no se muestran.
- ⚠ `report_supplier_due_paid.php:119` — el botón "ver" es `href="#"` (no navega); su par de ventas sí lleva a `view_invoice.php`.
- ⚠ `report_collection.php` — el `user_id` se concatena en el SQL.
- ⚠ `report_cashbook.php:23-29` — `pos_register` se consulta por `DAY()/MONTH()/YEAR()` de `created_at`, no por fecha de negocio → el saldo de apertura se duplica si hay varios registros el mismo día.
- ⚠ `report_stock.php` expone precios de compra sin el permiso `show_purchase_price` (a diferencia de los informes de venta).
- ⚠ `report_profit_and_loss.php` — el beneficio usa `income_sources.profitable='yes'` y, si `for_sell=1`, se sustituye por `get_profit_amount()` → la columna "profit" mezcla dos fuentes.

### 3.18 Tarjetas de regalo

**`admin/giftcard.php`**
- **Guardas**: `read_giftcard`; alta con `add_giftcard`; columnas ocultas sin `read_giftcard`/`giftcard_topup`/`update_giftcard`/`delete_giftcard`.
- **Columnas (`dt`)**: `card_no`(`label_card_no`), `value`(`label_value`), `balance`(`label_balance`), `created_by`, `customer`, `expiry`, `btn_view`, `btn_topup`, `btn_edit`, `btn_delete`.
- **Formulario**: `action_type=CREATE`, `card_no`* (readonly, autogenerado), `giftcard_value`*, `balance`*, `customer_id`*, `expiry`* (por defecto +1 año). El servidor exige `is_numeric(balance)`, `balance >= 0` y que el cliente **no** tenga ya tarjeta.
- **Endpoints**: `_inc/giftcard.php` POST `CREATE|UPDATE|DELETE|TOPUP`; GET `CREATE|EDIT|DELETE|VIEW|TOPUP`.
- **Flujo**: la emisión hace **dos depósitos** (venta de la tarjeta por `giftcard_value` + recarga por `balance`) (`_inc/model/giftcard.php:27-82`). Al borrar, **dos retiros** (valor + saldo) y se borran las recargas (`:139-206`).
- ⚠ `editGiftcard` sólo actualiza `card_no` y `expiry` → ni `value` ni `balance` son editables.
- ⚠ `gift_cards.created_by` es **varchar(55)** y guarda el id de usuario.
- ⚠ `gift_card_topups.card_id` apunta al **texto** `card_no`, no al id.
- ⚠ El listado **no filtra por `store_id`** (la tabla no tiene store).

**`admin/giftcard_topup.php`**
- **Guarda**: `read_giftcard_topup`.
- **Columnas (`dt`)**: `date`, `card_no` (campo real `card_id`), `amount`, `created_by`, `btn_delete`.
- **Borrar recarga**: `action_type=DELETE` con `id`, `card_no`, `amount` → descuenta el saldo y genera un **retiro** bancario (`is_substract=1`).
- ⚠ La vista oculta la columna con el slug `delete_topup_delete` pero el handler exige `delete_giftcard_topup` → botón visible que devuelve 422.
- ⚠ La comparación de saldo usa el importe **sin sanear** (coma decimal) frente a `(float)str_replace(',','')` → importes con coma decimal mal rechazados.

### 3.19 Usuarios, roles y seguridad

**`admin/user.php`**
- **Guardas**: `read_user`; alta con `create_user`.
- **Columnas**: `id`, `username`, `email`, `mobile`, `group`, `created_at`, `status`, `btn_profile`, `btn_edit`, `btn_delete`. Ocultables: 8=`update_user`, 9=`delete_user`.
- **Acciones**: `btn_profile` → `user_profile.php?id=`, `btn_edit`, `btn_delete` (deshabilitado para ids 1/2/3 en DEMO y para el propio usuario), `createNewUsergroup()`.
- **Formulario**: `user_image`, `username`*, `email`, `mobile`, `password`*, `password1`*, `group_id`*, `dob`, `user_store[]`*, `status`*, `sort_order`*.
- **Validaciones**: `validateString(username)`, `isItValidDate(dob)`, email o móvil, `validateInteger(group_id)`, `user_store` no vacío, `validateAlphanumeric(password)` + `checkPasswordStrongness()`, coincidencia `password`/`password1`, unicidad de email y móvil.
- ⚠ `btn_profile` (índice 7) **no** se oculta por permiso → visible sin `read_user_profile`.

**`admin/user_profile.php`**
- **Guarda**: `read_user_profile`.
- **Columnas (ventas)**: `label_date`, `label_invoice_id`, `label_note`, `label_items`, `label_total_item`, `label_payable`, `label_paid_amount`, `label_due`, `label_view`, `label_pay`.
- **Columnas (log de accesos)**: `serial`, `username`, `ip`, `time`.
- **Pestañas**: `#sell_report` y `#login_log` (las de compra/pago están comentadas).

**`admin/user_group.php` — roles**
- **Guardas**: `read_usergroup`; alta `create_usergroup`.
- **Columnas**: `group_id`, `name`, `total_user`, `btn_edit`, `btn_delete`. Ocultables: 3=`update_usergroup`, 4=`delete_usergroup`.
- **Alta**: `name`*, `slug`* (readonly, autogenerado).
- **Edición** (modal `UserGroupEditModal` → `_inc/template/user_group_form.php`): `name`, `slug` (inmutable si es `admin` o `cashier`) y la **matriz de permisos** como `access[<slug>]=true`.
- ⚠ `_inc/user_group.php:119-133` construye el array `$permission` **dos veces**; el campo `modify` nunca se envía.
- ⚠ `group_id=1` no se puede editar ni borrar; tampoco el grupo propio.

**`admin/user_preference.php`**
- **Guardas**: `read_user_preference`; POST `update_user_preference`.
- **Campos**: `preference[language]`*, `preference[base_color]`* (`black|blue|green|red|yellow`), `preference[pos_side_panel]`* (`right|left`), `preference[pos_pattern]`* (oculto, galería de `assets/itsolution24/img/pos/patterns`).
- ⚠ `base_color` se valida **dos veces**.

**`admin/password.php`**
- **Guarda**: `change_password` (o `group_id=1`); bloqueado en DEMO.
- **Campos**: `user_id`, `old` (sólo no-Admin), `new1`, `new2`. Validaciones: `md5(old) === users.password`, `validateAlphanumeric(new1)`, `strlen(new1) >= 6`, `new1 === new2`.
- ⚠⚠ Se escribe **MD5 sin sal** en `users.password` y el **texto plano** en `users.raw_password` (`admin/password.php:76-77`).
- ⚠ El grupo 1 **no valida** la contraseña antigua.

**`admin/reset.php` — borrado masivo**
- **Guarda**: `reset` + `!DEMO`. En CLI exige `APPID`.
- Borra en ~32 tablas transaccionales y pone a 0 `customer_to_store.balance/due`, `supplier_to_store.balance`, `product_to_store.quantity_in_stock`, `bank_accounts.total_*`, `bank_account_to_store.*`.
- ⚠⚠ **SQL por concatenación** de `$store_id` (procede de `$_POST['store']` sin sanitizar) (`admin/reset.php:67,71,74`).
- ⚠ La lista de tablas incluye `purchase_payments` y `transfer_items` duplicado, y no coincide con los nombres reales del esquema.

**`admin/logout.php`** — `unset($this->session->data['id'])` (no `session_destroy()`).

**Autenticación** (`index.php` raíz → `_inc/lib/user.php:79-107`)
- Login por `email` **o** `mobile` con `password = md5($password)`.
- El `JOIN user_to_store` exige `u2s.status = 1` → desactivar un usuario en **una** tienda lo bloquea en **todas**.
- Bloqueo por intentos: `TOTAL_LOGIN_TRY=10`, `UNLOCK_ACCOUNT_AFTER=10` minutos, contados sobre `login_logs` con `status='error'` e IP.
- Cookie `user_login` de 10 años si "recordarme".
- Sesión: `session_set_cookie_params(0,'/')`, `httponly`, `use_only_cookies`; estado relevante `$_SESSION['id']` y `$_SESSION['store_id']`.

### 3.20 Tiendas / multi-store

**`admin/store.php` — listado**
- **Guardas**: `is_loggedin`; `read_store`.
- **Columnas**: `store_id`, `name`, `country`, `address`, `created_at`, `btn_edit`, `btn_delete`, `btn_action`. Ocultables: 5=`update_store`, 6=`delete_store`, 7=`activate_store`.
- **Acciones**: `btn_edit` → `store_single.php?store_id=`, `btn_delete` (deshabilitado para `store_id=1` y para la tienda activa), `btn_action` → `store.php?active_store_id=` (`activate_store`, la resuelve `_init.php`).
- ⚠ El listado filtra por `user_to_store` para no-Admin → un Admin ve 5 tiendas y un cajero sólo las suyas.

**`admin/store_single.php` — configuración (5 pestañas: general, POS, email, FTP, cronjob)**
- **Guardas**: `read_store`; `store_id` de GET (o la tienda activa); inexistente → redirige. `$store->setStore($store_id)` cambia el contexto global.
- **Acciones**: `#update-store-btn` → POST `_inc/store.php` `action_type=UPDATE`; `#uploadlogo` / `#uploadFavicon` (`_inc/upload_logo.php`, `_inc/upload_favicon.php`); enlaces a `bank_account.php` y `user.php`; selector `receipt_template`.
- **Formulario (`#store-form`)**: `deposit_account_id`*, `name`*, `code_name`*, `country`*, `mobile`, `email`*, `zip_code`*, `address`*, `cashier_id`*, `vat_reg_no`, `preference[gst_reg_no]`, y en `preference[…]`: `timezone`*, `invoice_edit_lifespan`(+`_unit`), `invoice_delete_lifespan`(+`_unit`), `tax` (0-99), `sms_gateway`, `sms_alert`, `invoice_auto_sms`, `expiry_yes`, `expiring_soon_alert_days`, `datatable_item_limit`, `reference_format` (`year_sequence|year_month_sequence|sequence|random`), `sales_reference_prefix`, `receipt_template`, `invoice_view` (`standard|tax_invoice|indian_gst`), `business_state`, `change_item_price_while_billing`, `pos_product_display_limit`, `after_sell_page` (`pos|receipt_in_new_window|receipt_in_popup|toastr_msg|sweet_alert_msg`), `invoice_footer_text`, `sound_effect`, `stock_alert_quantity`, `email_from`/`email_address`/`email_driver`(`mail_function|send_mail|smtp_server`)/`send_mail_path`/`smtp_host`/`smtp_username`/`smtp_password`/`smtp_port`/`ssl_tls`(`tls|ssl`), `ftp_hostname`/`ftp_username`/`ftp_password`.
- ⚠⚠ `_inc/model/store.php:45-57` — `editPreference()` **reescribe `_init.php`** (línea 3) con el timezone, en runtime. Falla si el fichero no es escribible.
- ⚠ `_inc/model/store.php:31-32` — `editStore()` hace `UPDATE customers SET customer_address=?, …` **sin `WHERE`** → guarda la dirección de la tienda en **todos** los clientes.
- ⚠ `_inc/store.php:220` — `editPreference()` **reemplaza** el blob completo: cualquier clave no enviada por el formulario se pierde.
- ⚠ `invoice_auto_sms` y `stock_alert_quantity` se escriben pero **nunca se leen** (muertas). El umbral real de stock está en `product_to_store.danger_stock`.

**`admin/store_create.php` — alta (8 pestañas)**
- **Guarda**: `create_store`.
- **Flujo**: `addStore()` → `editPreference()` → por cada `product[]` crea `category_to_store`, `box_to_store`, `supplier_to_store`, `product_to_store` → `bank_account_to_store(account_id=1)` y fuerza `stores.deposit_account_id = 1` → replica `postemplate[]`, `printer[]`, `currency[]`, `pmethod[]` → añade el cliente "walking" `customer_id=1` → añade a `user_to_store` el `cashier_id`, el admin y el usuario actual.
- ⚠ El alta **siempre** fuerza `deposit_account_id = 1`, ignorando el elegido (`_inc/store.php:268-271`).
- ⚠ `after_sell_page` en el alta sólo ofrece `pos|invoice`, mientras que en la edición ofrece 5 valores.

### 3.21 Configuración del sistema

| Pantalla | Guarda | Columnas (`dt`) | Notas |
|---|---|---|---|
| `admin/currency.php` | `read_currency` | `currency_id`, `title`, `code`, `symbol_left`, `symbol_right`, `decimal_place`, `status`, `btn_edit`, `btn_delete`, `btn_activate` | |
| `admin/pmethod.php` | `read_pmethod` | `pmethod_id`, `name`, `sort_order`, `details`, `status`, `btn_edit`, `btn_delete` | slug de activación con typo: `updadte_pmethod_status` |
| `admin/taxrate.php` | `read_taxrate` | `taxrate_id`, `taxrate_name`, `code_name`, `taxrate`, `status`, `btn_edit`, `btn_delete` | |
| `admin/language.php` | `language_translation`; `?lang=` obligatorio | `lang_key`, `lang_value`, `btn_translate`, `btn_delete` | acciones por query string: `action_type=default|dublicate_entry|empty_value` (typo incluido) |
| `admin/filemanager.php` | `read_filemanager`; bloqueado en DEMO | — | componente `angular-filemanager`; acepta `jpg|JPEG|png|svg|gif`; modo FTP si `preference[ftp_hostname]` + `ftp_username` |
| `admin/backup_restore.php` | `backup` **o** `restore`; bloqueado en DEMO | — | `$angular_disabled = true` (no monta Angular); exporta `SHOW TABLES` **excluyendo** `users`, `user_group`, `user_to_store`; restore por upload multipart |
| `admin/receipt_template.php` | `receipt_template` | — | editor de `postemplatecontent` + `postemplatecss`; tags `{{ logo }}`, `{{ sl }}`, bucles `{{ key }}…{{ /key }}` (parser `Lex`, no Mustache) |
| `admin/preview_receipt.php` | sólo `is_loggedin()` | — | renderiza la plantilla con datos; **sin** `header.php`/`left_sidebar.php` |
| `admin/barcode_print.php` | `barcode_print` | `label_product_name_with_code`, `label_available`, `label_quantity`, `label_delete` | inputs `products[{p_id}][item_id|item_name|quantity]`; `per_page` 40/30/24/20/18/14/12/10; checkboxes `fields[site_name|product_name|price|currency|unit|category|product_image]` |

- ⚠ `admin/backup_restore.php:12` — la guarda usa `&&` entre `backup` y `restore` → un usuario con **sólo** `backup` es expulsado.
- ⚠ `admin/preview_receipt.php:31-35` — **bug real**: si hay fila, usa `get_postemplate_empty_data()` (vacío) en vez de `get_postemplate_data($row['invoice_id'])` → la vista previa sale con datos vacíos. Además carga `cssmin/main.css` (bundle obsoleto) y `$request->get['template_id']` sin `isset` → notice en PHP 8.2.
- ⚠ `admin/barcode_print.php:160` — el `<option value="40">` está **siempre** `selected` (bug de HTML).
- ⚠ `admin/language.php` — las acciones `default`/`dublicate`/`empty` van por query string, no por POST; el mismo endpoint mezcla lectura y escritura.

### 3.22 Analítica (`admin/analytics.php`)

- **Guarda**: `read_analytics`. Sin controller Angular: todo PHP + Chart.js. `$from = from() ?: date('Y-m-d')`.
- **Widgets**: 4 gráficos de torta (`top_products`, `top_customers`, `top_suppliers`, `top_brands`, límite 5), resumen de cashbook + barra de progreso (importe vendido / descuento / pendiente / cobrado), panel **Customer Analytics** (cumpleaños del día + mejor cliente), gráfico ingresos-vs-gastos, y los últimos 50 `login_logs` (SQL directo, `admin/analytics.php:229`).
- **Columnas**: tabla de cumpleaños → `label_customer`, `label_member_since`, `label_view`; tabla de logs → `label_username`, `label_ip`, `label_date`, `label_status`.
- ⚠ No es un motor analítico: no hay cohortes, embudos, segmentación ni métricas configurables.

### 3.23 SMS

**`admin/sms_setting.php`** — 5 pestañas de gateway. Campos `setting[clickatell][username|password|api_id]`, `setting[twilio][sender_id|auth_key|contact]`, `setting[msg91][auth_key|sender_id|country_code]`, `setting[mimsms][api_id|auth_key|sender_id]`, `setting[onnorokomsms][username|password|maskname|campaignname]`. Guarda `update_sms_setting`.
⚠ Credenciales **en claro** en `sms_setting`; el mapeo de columnas es irregular (Twilio escribe `sender_id→username`, `auth_key→password`, `contact→api_id`); `_inc/sms_setting.php:49` busca `'mimsms'` en minúsculas mientras la fila del dump es `'Mimsms'`.

**`admin/sms_send.php`** — pestañas `text_single` y `text_group`; la pestaña `text_event_sms` está **comentada**.
- Individual → `phone_number`*, `message`* (máx 160).
- Grupo → `people_type`* (`all_customer|all_user|group_id`), `peoples[id][mobile_number]`, `peoples[id][name]`, `message`*.
- Endpoints `_inc/sms/index.php`: `SENDINDIVIDUAL|SENDGROUP|SEND|RESEND|FORM|RESENDFORM|PROCEEDSCHEDULESMS|UPDATEDELIVERYSTATUS`; `_inc/sms.php?action_type=PEOPLE&people_type=`.
- Implementación real: `_inc/sms/_inc/gateway/*.php` con `GatewayInterface` y `SMSGateway::initGateway()`.
- Plantillas: `mail_sms_tag` (tipo `invoice`, tags `{{…}}` vía `ModelTagConverter`) y la de grupo con el tag `[name]`.
- ⚠ El envío es **síncrono** dentro del POST (la nota de cron de la pantalla no describe el comportamiento real).
- ⚠ `PROCEEDSCHEDULESMS` sólo implementa `onnorokomsms` y `Msg91`; el resto es `default: //…`.

**`admin/sms_report.php`** — `read_sms_report`.
- **Columnas (`dt`)**: `id`, `schedule_datetime`, `campaign_name`, `people_name`, `mobile_number`, `response_text`, `process_status`, `delivery_status`, `button_resend`.
- Filtros `button_all` / `button_pending` / `button_delivered` / `button_failed` (`?type=pending|delivered|failed`).
- ⚠ El rango de fechas se construye por concatenación de `DAY()/MONTH()/YEAR()`.

### 3.24 Layout: cabecera, menú y pie

**`admin/header.php`**
- `<html lang="<?= $document->langTag($active_lang)?>" ng-app="angularApp">` (salvo `$angular_disabled`); `<title>` = título + `store('name')`; favicon desde `store('favicon')`.
- **CSS**: según `DEMO || USECOMPILEDCSS` carga el bundle `cssmin/main.css`; si no, carga cada hoja desde fuente (Bootstrap, jQuery-UI, FontAwesome, Morris, Select2, Datepicker3, Timepicker, Bootstrap3-Wysihtml5, Perfect-scrollbar, Toastr, filemanager, `theme.css`, las 5 `skins/skin-*.css`, DataTables, `main.css`, `rtl.css` si `isRTL()`, `responsive.css`, `print.css`) y **siempre** `modern.css` al final.
- **Variables JS expuestas**: `baseUrl`, `adminDir`, `user` (sin `password`/`raw_password`/`pass_reset_code`), `lang`, `settings` (= `get_all_preference()` **sin** `smtp_password`/`ftp_password`), `store` (= `store()` **sin** `smtp_password`/`ftp_password`/`preference`), `isInstallment`, `totalProduct`, `deviceType`, `filemanager`, `redirectTo`, `logo`, `sendReportEmail`.
- ⚠ `admin/header.php:144` — `settings` expone todo `get_all_preference()` al navegador salvo dos claves: cualquier credencial futura quedaría filtrada.
- ⚠ `admin/header.php:9-22` — la query string se reconstruye con `str_replace('&','?')` → los parámetros múltiples se convierten en `?a?b` (afecta al filtro por fecha).
- ⚠ Con `USECOMPILEDASSET=false` se carga `angularmin/angular.js` (que ya incluye `angularApp.js`) **y** `angular/angularApp.js` → el módulo `angularApp` se registra dos veces.

**`admin/left_sidebar.php`** — panel de usuario (avatar, `user->getUserName()`, `user->getRole()`) + `<ul class="sidebar-menu">` con entradas `<li class="treeview">`; estado activo con `current_nav()` (`_inc/helper/common.php:74`).

**`admin/footer.php`** — (1) `<aside class="scrolling-sidebar">` con `SELECT * FROM shortcut_links WHERE type='report' AND status=1 ORDER BY sort_order` (19 accesos con su `permission_slug`); (2) `<footer class="main-footer">` con versión y copyright; (3) `#filter-box` con `from`/`to` ocultos y 4 atajos (`button_today`, `button_last_7_days`, `button_last_30_days`, `button_last_365_days`) + `button_filter`; (4) `<script> var from/to; </script>`; (5) bucle `foreach ($scripts as $script)` que emite los `<script src>` de `$document->addScript()` **después** del bundle Angular; (6) `<noscript>`.

---

## 4. Mapa de navegación (menú lateral)

Orden real en `admin/left_sidebar.php`. La condición es la expresión de permisos que decide si la entrada se muestra.

| # | Etiqueta | URL | Condición |
|---|---|---|---|
| 1 | `menu_dashboard` | `dashboard.php` | siempre |
| 2 | `menu_point_of_sell` | `pos.php` | `read_sell_invoice` |
| 3 | **Ventas** (padre) | `pos.php` | `read_sell_list`\|`read_sell_return`\|`read_sell_log`\|`read_giftcard`\|`add_giftcard`\|`read_giftcard_topup` |
| 4 | `menu_sell_list` | `invoice.php` | `read_sell_list` |
| 5 | `menu_return_list` | `sell_return.php` | `read_sell_return` |
| 6 | `menu_sell_log` | `sell_log.php` | `read_sell_log` |
| 7 | `menu_giftcard` | `giftcard.php` | `read_giftcard`\|`add_giftcard`\|`read_giftcard_topup` |
| 8 | `menu_add_giftcard` | `giftcard.php?box_state=open` | `add_giftcard` |
| 9 | `menu_giftcard_list` | `giftcard.php` | `read_giftcard` |
| 10 | `menu_giftcard_topup` | `giftcard_topup.php` | `read_giftcard_topup` |
| 11 | `menu_quotation` | `quotation.php` | `read_quotation` |
| 12 | `menu_add_quotation` | `quotation.php?box_state=open` | `create_quotation` |
| 13 | `menu_quotation_list` | `quotation.php` | `read_quotation` |
| 14 | `menu_installment` | `installment.php` | `INSTALLMENT` && `create_installment` |
| 15 | `menu_installment_list` | `installment.php` | `read_installment` |
| 16 | `menu_payment_list` | `installment_payment.php?type=all_payment` | `installment_payment` |
| 17 | `menu_payment_due_today` | `installment_payment.php?type=todays_due_payment` | `installment_payment` |
| 18 | `menu_payment_due_all` | `installment_payment.php?type=all_due_payment` | `installment_payment` |
| 19 | `menu_payment_due_expired` | `installment_payment.php?type=expired_due_payment` | `installment_payment` |
| 20 | `menu_overview_report` | `installment_overview.php` | `installment_overview` |
| 21 | **Compras** (padre) | ⚠ `purchase_list.php` (**no existe**) | `read_purchase_list`\|`create_purchase_invoice`\|`read_purchase_return` |
| 22 | `menu_add_purchase` | `purchase.php?box_state=open` | `create_purchase_invoice` |
| 23 | `menu_purchase_list` | `purchase.php` | `read_purchase_list` |
| 24 | `menu_due_invoice` | `purchase.php?type=due` | `read_purchase_list` |
| 25 | `menu_return_list` | `purchase_return.php` | `read_purchase_return` |
| 26 | `menu_purchase_logs` | `purchase_log.php` | `read_purchase_log` |
| 27 | `menu_transfer` | `transfer.php` | `read_transfer` |
| 28 | `menu_add_transfer` | `transfer.php?box_state=open` | `add_transfer`\|`update_transfer` |
| 29 | `menu_transfer_list` | `transfer.php` | `read_transfer` |
| 30 | `menu_receive_list` | `transfer.php?type=receive` | `read_transfer` |
| 31 | **Producto** (padre) | `product.php` | `read_product` |
| 32 | `menu_product_list` | `product.php` | `read_product` |
| 33 | `menu_add_product` | `product.php?box_state=open` | ⚠ `read_product` (debería ser `create_product`) |
| 34 | `menu_barcode_print` | `barcode_print.php` | `barcode_print` |
| 35 | `menu_category` | `category.php` | `read_category` |
| 36 | `menu_add_category` | `category.php?box_state=open` | ⚠ `crate_category` (**typo**) |
| 37 | `menu_product_import` | `import_product.php` | `import_product` |
| 38 | `menu_stock_alert` | `stock_alert.php` (+ badge) | `read_stock_alert` |
| 39 | `menu_expired` | `expired.php` (+ badge) | `preference[expiry_yes]` && `read_expired_product` |
| 40 | **Cliente** (padre) | `customer.php` | `read_customer`\|`read_customer_transaction` |
| 41 | `menu_add_customer` | `customer.php?box_state=open` | `create_customer` |
| 42 | `menu_customer_list` | `customer.php` | `read_customer` |
| 43 | `menu_statements` | `customer_transaction.php` | ⚠ **comentado** |
| 44 | **Proveedor** (padre) | `supplier.php` | `read_supplier` |
| 45 | `menu_add_supplier` | `supplier.php?box_state=open` | `create_supplier` |
| 46 | `menu_supplier_list` | `supplier.php` | `read_supplier` |
| 47 | **Contabilidad** (padre) | `bank_transactions.php?type=report` | `deposit`\|`withdraw`\|`transfer`\|`read_bank_*`\|`read_income_monthwise`\|`read_expense_monthwise`\|`read_income_and_expense_report`\|`read_profit_and_loss_report`\|`read_cashbook_report` |
| 48 | `menu_new_deposit` | `ng-click="BankingDepositModal()"` | `deposit` |
| 49 | `menu_new_withdraw` | `ng-click="BankingWithdrawModal()"` | `withdraw` |
| 50 | `menu_list_transactions` | `bank_transactions.php` | `read_bank_transactions` |
| 51 | `menu_new_transfer` | `ng-click="BankTransferModal()"` | `transfer` |
| 52 | `menu_list_transfer` | `bank_transfer.php` | `read_bank_transfer` |
| 53 | `menu_add_bank_account` | `bank_account.php?box_state=open` | `read_bank_account` && `create_bank_account` |
| 54 | `menu_bank_accounts` | `bank_account.php` | `read_bank_account` |
| 55 | `menu_income_source` | `income_source.php` | `read_income_source` |
| 56 | `menu_balance_sheet` | `bank_account_sheet.php` | `read_bank_account_sheet` |
| 57 | `menu_income_monthwise` | `income_monthwise.php` | `read_income_monthwise` |
| 58 | `menu_expense_monthwise` | `expense_monthwise.php?show_top=yes` | `read_expense_monthwise` |
| 59 | `menu_income_and_expense` | `report_income_and_expense.php` | `read_income_and_expense_report` |
| 60 | `menu_profit_and_loss` | `report_profit_and_loss.php` | `read_profit_and_loss_report` |
| 61 | `menu_cashbook` | `report_cashbook.php` | `read_cashbook_report` |
| 62 | **Gastos** (padre) | `expense.php` | `read_expense`\|`create_expense`\|`read_expense_category`\|`read_expense_summary`\|`read_expense_monthwise` |
| 63 | `menu_create_expense` | `expense.php?box_state=open` | `create_expense` |
| 64 | `menu_expense_list` | `expense.php` | `read_expense` |
| 65 | `menu_add_category` | `expense_category.php?box_state=open` | `read_expense_category` |
| 66 | `menu_category` | `expense_category.php` | `read_expense_category` |
| 67 | `menu_expense_monthwise` | `expense_monthwise.php` | `read_expense_monthwise` |
| 68 | `menu_summary` | `ng-click="ExpenseSummaryModal()"` | `read_expense_summary` |
| 69 | `menu_loan_manager` | `loan.php` | `read_loan`\|`take_loan`\|`read_loan_summary` |
| 70 | `menu_loan_list` | `loan.php` | `read_loan` |
| 71 | `menu_take_loan` | `loan.php?box_state=open` | `take_loan` |
| 72 | `menu_loan_summary` | `loan_summary.php?box_state=open` | `read_loan_summary` |
| 73 | **Reportes** (padre) | `report_overview.php` | 13 slugs `read_*_report` |
| 74 | `menu_report_overview` | `report_overview.php?type=sell` | `read_overview_report` |
| 75 | `menu_report_collection` | `report_collection.php` | `read_collection_report` |
| 76 | `menu_report_due_collection` | `report_customer_due_collection.php` | `read_customer_due_collection_report` |
| 77 | `menu_report_due_paid` | `report_supplier_due_paid.php` | `read_supplier_due_paid_report` |
| 78 | `menu_sell_report` | `report_sell_itemwise.php` | `read_sell_report` |
| 79 | `menu_purchase_report` | `report_purchase_supplierwise.php` | `read_purchase_report` |
| 80 | `menu_sell_payment_report` | `report_sell_payment.php` | `read_sell_payment_report` |
| 81 | `menu_purchase_payment_report` | `report_purchase_payment.php` | `read_purchase_payment_report` |
| 82 | `menu_tax_report` | `report_sell_tax.php` | `read_sell_tax_report` |
| 83 | `menu_purchase_tax_report` | `report_purchase_tax.php` | `read_purchase_tax_report` |
| 84 | `menu_tax_overview_report` | `report_tax_overview.php` | `read_tax_overview_report` |
| 85 | `menu_report_stock` | `report_stock.php` | `read_stock_report` |
| 86 | `menu_analytics` | `analytics.php` | `read_analytics` |
| 87 | **SMS** (padre) | `sms_send.php` | `send_sms`\|`read_sms_setting`\|`read_sms_report` |
| 88 | `menu_send_sms` | `sms_send.php` | `send_sms` |
| 89 | `menu_sms_report` | `sms_report.php` | `read_sms_report` |
| 90 | `menu_sms_setting` | `sms_setting.php` | `read_sms_setting` |
| 91 | **Usuario** (padre) | `user.php` | `read_user`\|`read_usergroup`\|`change_password` |
| 92 | `menu_add_user` | `user.php?box_state=open` | `create_user` |
| 93 | `menu_user_list` | `user.php` | `read_user` |
| 94 | `menu_add_usergroup` | `user_group.php?box_state=open` | `create_usergroup` |
| 95 | `menu_usergroup_list` | `user_group.php` | `read_usergroup` |
| 96 | `menu_password` | `password.php` | `change_password` && `!DEMO` |
| 97 | `menu_filemanager` | `filemanager.php` | `read_filemanager` && `!DEMO` |
| 98 | **Sistema** (padre) | `store_single.php` | 13 slugs (`read_store`, `receipt_template`, `read_user_preference`, `read_unit`, `read_taxrate`, `read_pmethod`, `read_currency`, `read_brand`, `read_box`, `read_printer`, ⚠`sms_setting`, `backup`, `language_translation`) |
| 99 | `menu_store` (padre) | `store.php` | `read_store` |
| 100 | `menu_create_store` | `store_create.php` | `create_store` |
| 101 | `menu_store_list` | `store.php` | `read_store` |
| 102 | `menu_store_setting` | `store_single.php` | `read_store` |
| 103 | `menu_receipt_template` | `receipt_template.php?template_id=<preference[receipt_template] o 1>` | `receipt_template` |
| 104 | `menu_user_preference` | `user_preference.php` | `read_user_preference` |
| 105 | `menu_brand` | `brand.php` | `read_brand` |
| 106 | `menu_add_brand` | `brand.php?box_state=open` | `create_brand` |
| 107 | `menu_brand_list` | `brand.php` | `read_brand` |
| 108 | `menu_currency` | `currency.php` | `read_currency` |
| 109 | `menu_pmethod` | `pmethod.php` | `read_pmethod` |
| 110 | `menu_unit` | `unit.php` | `read_unit` |
| 111 | `menu_taxrate` | `taxrate.php` | `read_taxrate` |
| 112 | `menu_box` | `box.php` | `read_box` |
| 113 | `menu_printer` | `printer.php` | `read_printer` |
| 114 | `menu_language` | `language.php?lang=en` | `read_language` |
| 115 | `menu_backup_restore` | `backup_restore.php` | (`backup`\|`restore`) && `!DEMO` |
| 116 | `menu_data_reset` | `reset.php` | `reset` && `!DEMO` |
| 117 | `menu_store_change` | `../store_select.php` | `activate_store` |

**Entradas rotas / huérfanas:**

| Elemento | Problema |
|---|---|
| `purchase_list.php` | **El fichero no existe** → 404 al desplegar "Compras" (`left_sidebar.php:243`) |
| `admin/banner.php` | No enlazado + slugs inexistentes → sólo Admin |
| `admin/order.php`, `admin/order_edit.php` | No enlazados y rotos (§3.6) |
| `admin/purchase_edit.php` | No enlazado; duplicado de la edición de cotización |
| `admin/report_pos_overview.php` | No enlazado; clon de P&G |
| `admin/customer_transaction.php` | Enlazado sólo desde `customer_profile.php` |
| `menu_statements` | Entrada comentada |

---

## 5. Modelo de permisos

### 5.1 Mecánica

- `user_group_id() == 1` → **superusuario**: todo permitido, sin comprobaciones.
- En otro caso → `has_permission('access', '<slug>')` busca `$permission['access'][$slug]` dentro del blob serializado de `user_group.permission`.
- La **matriz de permisos** vive en `_inc/template/user_group_form.php` (24 grupos, ~190 slugs) y es la **única fuente de verdad** de lo asignable. Se renderiza como `access[<slug>]=true`.
- El permiso se guarda con dos claves: `access` y `modify` — **`modify` nunca se envía** desde el formulario actual.

### 5.2 Los 3 grupos del dump

| `group_id` | Nombre | Slug | Permisos |
|---|---|---|---|
| 1 | Admin | `admin` | 122 (todo) |
| 2 | Cashier | `cashier` | **2**: `read_user` + `update_user` |
| 3 | Salesman | `salesman` | 191 |

Usuarios semilla: `mitienda@gmail.com` (Fredy Alegre, grupo 1), `cashier@gmail.com` (grupo 2), `salesman@gmail.com` (grupo 3), `yohn@gmail.com` y `JOSE@GMAIL.COM` (grupo 2). Todos con `raw_password='tusolutionweb'`.

⚠ El grupo **Cashier** tiene 2 permisos y **ninguno de caja**: un cajero no puede registrar una venta desde el menú, aunque el POS sí lo permite si tiene `create_sell_invoice` (que no tiene). Hay que revisar el reparto real de roles.

### 5.3 Slugs por dominio

| Dominio | Slugs |
|---|---|
| **Cliente** | `read_customer`, `read_customer_profile`, `create_customer`, `update_customer`, `delete_customer`, `add_customer_balance`, `substract_customer_balance`, `read_customer_transaction` |
| **Proveedor** | `read_supplier`, `read_supplier_profile`, `create_supplier`, `update_supplier`, `delete_supplier` |
| **Marca** | `read_brand`, `read_brand_profile`, `create_brand`, `update_brand`, `delete_brand` |
| **Usuario** | `read_user`, `read_user_profile`, `create_user`, `update_user`, `delete_user`, `change_password` |
| **Grupo** | `read_usergroup`, `create_usergroup`, `update_usergroup`, `delete_usergroup` |
| **Preferencias** | `read_user_preference`, `update_user_preference` |
| **Tienda** | `read_store`, `create_store`, `update_store`, `delete_store`, `activate_store`, `upload_logo`, `upload_favicon` |
| **Catálogo** | `read/create/update/delete` + `change_currency` (currency) · `updadte_pmethod_status` (pmethod, **typo**) · taxrate · unit · box · category · product · `read_stock_alert` · `read_expired_product` · printer · `read_banner`/`create_banner`/`update_banner`/`delete_banner` (**fuera de la matriz**) |
| **Facturación** | `read_sell_list`, `read_sell_invoice`, `create_sell_invoice`, `sell_payment`, `read_sell_log`, `create_sell_return`, `update_sell_invoice_info`, `delete_sell_invoice`, `read_purchase_list`, `create_purchase_invoice`, `purchase_payment`, `read_purchase_log`, `read_purchase_return`, `update_purchase_invoice_info`, `delete_purchase_invoice` |
| **Reportes** | `read_recent_activities`, `read_dashboard_accounting_report`, `read_sell_report`, `read_overview_report`, `read_collection_report`, `read_customer_due_collection_report`, `read_supplier_due_paid_report`, `read_analytics`, `read_purchase_report`, `read_purchase_payment_report`, `read_purchase_tax_report`, `read_sell_payment_report`, `read_sell_tax_report`, `read_tax_overview_report`, `read_stock_report`, `send_report_via_email` |
| **Contabilidad** | `deposit`, `withdraw`, `transfer`, `read_bank_account`, `read_bank_account_sheet`, `read_bank_transfer`, `read_bank_transactions`, `read_income_source`, `read_income_monthwise`, `read_expense_monthwise`, `read_expense_category`, `read_expense_summary`, `read_income_and_expense_report`, `read_profit_and_loss_report`, `read_cashbook_report`, `read_loan`, `take_loan`, `read_loan_summary` |
| **Cuotas** | `create_installment`, `read_installment`, `installment_payment`, `installment_overview`, `delete_installment` |
| **SMS** | `send_sms`, `read_sms_report`, `read_sms_setting`, `update_sms_setting`, `sms_sell_invoice` |
| **Idiomas** | `read_language`, `create_language`, `update_language`, `delete_language`, `language_translation`, `delete_language_key` |
| **Sistema** | `receipt_template`, `filtering`, `backup`, `restore`, `reset`, `show_purchase_price`, `show_profit`, `view_purchase_price`, `view_profit`, `read_filemanager`, `barcode_print`, `import_product`, `product_bulk_action`, `read_holding_order`, `add_quotation`, `add_purchase`, `read_pos_receipt_template` (los últimos **no están en la matriz**) |

### 5.4 Slugs huérfanos y typos

| Slug | Problema |
|---|---|
| `crate_category` | typo real en `left_sidebar.php:382` → "Añadir categoría" nunca se muestra a no-Admin |
| `updadte_pmethod_status` | typo real (`_inc/pmethod.php:252`) |
| `delete_topup_delete` | en la vista; el handler exige `delete_giftcard_topup` |
| `read_loan_summary` vs `read_loan` | vista y handler exigen slugs distintos |
| `read_banner`, `create_banner`, `update_banner`, `delete_banner` | no están en la matriz → no asignables |
| `sms_setting` | no está en la matriz (el real es `read_sms_setting`) |
| `read_holding_order`, `add_quotation`, `add_purchase`, `read_pos_receipt_template` | no están en la matriz |
| `view_purchase_price`, `view_profit` | no están en la matriz (el listado usa `show_*`) |
| `installment_overview`, `installment_payment` | sin prefijo `read_` (inconsistente con el resto) |
| `receipt_template` | sin prefijo `read_` |
| `email_sell_invoice`, `send_email` | usados en código — verificar su presencia en la matriz |

### 5.5 Regla de oro del sistema de permisos (y su hueco)

> `user_group_id() != 1` ⇒ todo requiere permiso; el grupo 1 lo tiene todo.

**El hueco**: las columnas de acción de los listados se ocultan por permiso en el HTML (`data-hide-columes`), pero **los formatters PHP no comprueban ese permiso**: el botón se renderiza siempre y quien no tiene permiso recibe un 422 al pulsarlo. Y hay pantallas que no comprueban nada: `admin/dashboard.php` (sólo `is_loggedin`), `admin/preview_receipt.php` (sólo `is_loggedin`), `admin/filemanager.php?ajax=1`, `_inc/payment.php?action_type=FIELD` (sólo `is_loggedin` — cualquier cajero autenticado puede enumerar métodos de pago con sus campos), `_inc/ajax.php` (sólo login).

---

## 6. Trampas y defectos (catálogo priorizado)

### P0 — Corruptores de datos

| # | Ubicación | Defecto | Impacto |
|---|---|---|---|
| 1 | `_inc/model/invoice.php:92-397` | `createInvoice()` escribe 8+ tablas **sin transacción** | Stock decrementado sin factura; facturas sin `selling_price` |
| 2 | `_inc/installment.php:94`, `_inc/installment_payment.php:94` | `UPDATE installment_orders SET last_installment_date = ?` **sin `WHERE invoice_id`** | Actualiza **todas** las filas de la tabla |
| 3 | `_inc/installment.php:106`, `_inc/installment_payment.php:106` | `UPDATE selling_price … WHERE invoice_id = ?` **sin `store_id`** | El pago de cuota toca facturas de otras tiendas |
| 4 | `_inc/installment.php:199-228` | Cascada manual sobre 8 tablas sin transacción | Estados intermedios huérfanos |
| 5 | `_inc/model/store.php:31-32` | `UPDATE customers SET …` **sin `WHERE`** | La dirección de la tienda se graba en **todos** los clientes |
| 6 | `_inc/model/store.php:45-57` | `editPreference()` **reescribe `_init.php`** en runtime | Falla sin permiso de escritura; auto-modificación de código |
| 7 | `admin/reset.php:67,71,74` | SQL por concatenación de `$store_id` desde `$_POST` | Inyección + borrado masivo |
| 8 | `_inc/expense.php:122,228,252,321` | Los movimientos de gasto suman a `total_deposit` en vez de `total_withdraw` | Contadores de caja globales incorrectos |
| 9 | `_inc/purchase.php:390-408` | Al borrar una compra, el ajuste de stock usa **un único `item_id`** | Sólo revierte el stock de un producto |
| 10 | `_inc/sell_return.php:142-153` | Actualiza `purchase_item` con dos claves distintas (`invoice_id` / `purchase_invoice_id`) | Lotes en estado inconsistente |
| 11 | `_inc/sell_return.php:284` | `customer_to_store.due -= $due_paid` | Deuda del cliente puede quedar negativa |
| 12 | `_inc/model/invoice.php:233-282` | El FIFO **no ordena por antigüedad** (`SELECT … WHERE status='active'` sin `ORDER BY id`) | El lote consumido no es determinista |
| 13 | `_inc/model/invoice.php:225-228` | El impuesto por ítem se calcula sobre el **precio de catálogo**, no sobre el cobrado | Impuesto incorrecto si el POS cambió el precio |
| 14 | `_inc/model/invoice.php:305` | `$capital = ($subtotal − $discount)` puede ser 0 | División por cero |
| 15 | `_inc/model/invoice.php:370-373` | Al vender una cotización no se borran `quotation_item`/`quotation_price` | La cotización queda historizada con importes viejos |
| 16 | `_inc/transfer.php:73` | El `INSERT` en la tienda destino **omite `brand_id`** | Productos sin marca en la tienda receptora |
| 17 | `_inc/transfer.php:169-275` | Una transferencia de N ítems genera **N** registros y **N** `purchase_info` | El listado de compras se multiplica |
| 18 | `_inc/model/loan.php:109` | `editLoan` no recalcula `amount`, `interest`, `payable`, `due` | Préstamo con importes inconsistentes |
| 19 | `_inc/loan.php:237` | El pago valida `paid <= due` sin transacción | Sobrepago concurrente |
| 20 | `_inc/model/giftcard.php:139-206` | Al borrar una tarjeta se generan **dos retiros** y se borran las recargas | El saldo en caja no reconcilia con `gift_cards.balance` |
| 21 | `_inc/model/giftcard.php:90` | `editGiftcard` no actualiza ni `value` ni `balance` | El formulario muestra campos que no se guardan |
| 22 | `_inc/store.php:220` | `editPreference()` **reemplaza** el blob completo | Toda clave no enviada por el formulario se pierde |
| 23 | `_inc/sell_log.php` / `admin/sell_log.php:100` | El selector de clientes usa un campo inexistente | Desplegable vacío |
| 24 | `_inc/model/invoice.php:293` | `selling_item.tax` y `gst` guardan el **porcentaje**, `item_tax` el importe | Mezcla de unidades persistida |

### P1 — Seguridad

| # | Ubicación | Defecto |
|---|---|---|
| 25 | `admin/password.php:76-77`, `_inc/lib/user.php:81` | MD5 **sin sal** + `users.raw_password` en **texto plano** |
| 26 | `admin/header.php:144` | `settings` expone todo `get_all_preference()` al navegador salvo 2 claves |
| 27 | `_inc/pos.php:67`, `_inc/model/product.php:481,486` | Concatenación de `$field`/`$query_string` en SQL → inyección en el buscador del POS |
| 28 | `_inc/banking.php:374`, `_inc/customer_transaction.php:48`, `_inc/bank_transfer.php:47-53`, `_inc/invoice.php:434`, `_inc/installment_payment.php:203-210`, `admin/report_cashbook.php:23-26`, `_inc/customer.php:110,313`, `_inc/supplier.php:245`, `_inc/report_collection.php` | Concatenación de parámetros en SQL |
| 29 | `_inc/ajax.php` (guarda) | Sólo comprueba `is_loggedin()`, sin permiso de lectura |
| 30 | `_inc/payment.php:70-79` | `action_type=FIELD` no comprueba permisos → cualquier cajero enumera métodos de pago y sus campos |
| 31 | `_inc/sms_setting.php` + `admin/sms_setting.php` | Credenciales de gateway **en claro** en base de datos |
| 32 | `_inc/lib/user.php:81-83` | `JOIN user_to_store` exige `u2s.status=1` → desactivar en una tienda bloquea en todas |
| 33 | `admin/filemanager.php`, `admin/preview_receipt.php`, `admin/dashboard.php` | Endpoints sin permiso específico |
| 34 | `admin/backup_restore.php:12` | `&&` entre `backup` y `restore` → un usuario con sólo `backup` es expulsado |

### P2 — Funcional / mantenibilidad

| # | Ubicación | Defecto |
|---|---|---|
| 35 | `_inc/order.php:189` | `ModelOrder::createOrder()` **no existe** → fatal al crear un pedido |
| 36 | `_inc/order.php:312,324,332` | DELETE sobre tablas inexistentes (`order_item`, `order_info`) |
| 37 | `_inc/model/order.php:118-125` | `ORDER BY` duplicado → SQL inválido |
| 38 | `_inc/model/order.php:43` | `$paid_amount` no definida |
| 39 | `admin/purchase_edit.php` | Huérfana; monta el formulario de cotización; `purchase_edit_form.php` y `PurchaseEditController.js` sin usar |
| 40 | `admin/report_pos_overview.php` | Clon huérfano de P&G |
| 41 | `left_sidebar.php:243` | Enlace a `purchase_list.php` (inexistente) → 404 |
| 42 | `left_sidebar.php:382` | Slug `crate_category` (typo) |
| 43 | `left_sidebar.php:358-364` | "Añadir producto" se muestra con `read_product` en vez de `create_product` |
| 44 | `left_sidebar.php:997` | Bloque "Sistema" usa el slug inexistente `sms_setting` |
| 45 | `admin/banner.php` | Huérfano; `hide_colums` desplazado (oculta borrar, nunca editar) |
| 46 | `admin/user.php:118-127` | `btn_profile` no se oculta por permiso |
| 47 | `_inc/user_group.php:119-133` | `$permission` se construye **dos veces**; `modify` nunca se envía |
| 48 | `_inc/supplier.php:79-85,122-127` | Validación duplicada de `sup_name` |
| 49 | `_inc/product.php` (`validate_existance`) | Duplicidad por `p_name`, no por `p_code` |
| 50 | `admin/product.php:362` | `GROUP BY products.p_id` → una fila por producto en multi-store |
| 51 | `_inc/import_product.php:223-228` | El upsert borra y recrea `product_to_store` → pierde stock y preferencias de la tienda |
| 52 | `_inc/import_product.php:163` | `!$x < 0` es un no-op por precedencia |
| 53 | `_inc/purchase.php:166` vs `:275` | Se valida `$_FILES["image"]` pero se sube `$_FILES["attachment"]` |
| 54 | `_inc/expense.php:69,179` | `!x == y` en la validación del adjunto (siempre falso) |
| 55 | `admin/purchase.php:161` | `$hide_colums .= "1101,"` (índice inválido) |
| 56 | `admin/purchase.php:171`, `admin/sell_return.php:81`, `admin/stock_alert.php:100`, `admin/expired.php:121`, `admin/supplier_profile.php:318` | Ids DOM `#invoice-invoice-list` / `#product-product-list` **compartidos** entre pantallas |
| 57 | `_inc/sell_return.php:443,450` | `btn_edit`/`btn_delete` devuelven `'-'` |
| 58 | `_inc/report_supplier_due_paid.php:119` | Botón "ver" con `href="#"` |
| 59 | `admin/report_sell_itemwise.php:125-130` | `$hide_colums` con `"4,"` duplicado → columna de coste siempre oculta |
| 60 | `admin/report_sell_itemwise.php` y familia | Backend define 10-11 `dt`, la vista 6 → columnas que viajan y no se muestran |
| 61 | `admin/report_tax_overview.php:129` | La pestaña de impuestos de compras muestra "Unavailable Now…" |
| 62 | `admin/report_cashbook.php:23-29` | `pos_register` filtrado por `DAY()/MONTH()/YEAR()` en vez de fecha de negocio |
| 63 | `admin/report_stock.php` | Expone precio de compra sin `show_purchase_price` |
| 64 | `_inc/model/income.php:78` vs `getTotalIncome` | Dos criterios de ingreso incompatibles en el mismo informe |
| 65 | `_inc/model/income.php:96` | Los informes de gasto leen de `bank_transaction_info`, no de `expenses` |
| 66 | `_inc/model/income.php:131-147` | La columna "profit" mezcla `income_sources.profitable` con `get_profit_amount()` |
| 67 | `_inc/expense_category.php:294`, `_inc/income_source.php:287` | Filtro `is_hide != 1` deja los listados vacíos en una base recién importada |
| 68 | `pos_moderno.sql:852-866` | Los flags contables de `income_sources`/`expense_categorys` **no son editables desde la UI** |
| 69 | `_inc/installment_payment.php` | Copia literal de `_inc/installment.php` |
| 70 | `_inc/installment.php:106` / `:100` | `UPDATE` sin `store_id` y sin `WHERE invoice_id` |
| 71 | `_inc/installment_payment.php:196-218` | `type=all_payment` sin `case`; `todays_due_payment` compara con hoy, no con el vencimiento |
| 72 | `admin/giftcard_topup.php:82` vs `_inc/giftcard_topup.php:35` | Slug de vista ≠ slug del handler |
| 73 | `_inc/giftcard_topup.php:62` vs `:54` | Comparación de saldo con importe sin sanear |
| 74 | `admin/preview_receipt.php:31-35` | La vista previa usa datos vacíos |
| 75 | `admin/preview_receipt.php:21` | `$request->get['template_id']` sin `isset` → notice en PHP 8.2 |
| 76 | `admin/barcode_print.php:160` | `<option value="40">` siempre `selected` |
| 77 | `admin/language.php:115` | Typo `button_dublicate_entry`; acciones por query string mezcladas con el listado |
| 78 | `admin/header.php:9-22` | Query string reconstruida con `str_replace('&','?')` |
| 79 | `admin/header.php:223-229` | Con `USECOMPILEDASSET=false` el módulo `angularApp` se registra dos veces |
| 80 | `_inc/quotation.php` (`model/quotation.php:232`) | `getQuotationInfo` busca por `reference_no` **o** `customer_id` |
| 81 | `_inc/place_order.php:130-134` | Condición de validación contradictoria |
| 82 | `_inc/customer.php:141` | `credit_balance` con `pmethod_id = 1` fijo |
| 83 | `_inc/customer.php:259-260` | Reasignación de `customer_transactions.customer_id` aunque `delete_action != 'insert_to'` |
| 84 | `_inc/loan.php` vs `admin/loan_summary.php:12` | Slugs de vista y handler incompatibles |
| 85 | `_inc/sms/index.php:162-248` | Envío SMS síncrono en el POST |
| 86 | `_inc/sms/index.php` | `PROCEEDSCHEDULESMS` sólo para `onnorokomsms` y `Msg91` |
| 87 | `_inc/sms_setting.php:49` | `WHERE type='mimsms'` vs fila `'Mimsms'` |
| 88 | `_inc/sms_report.php:64-70` | Rango de fechas por concatenación de `DAY()/MONTH()/YEAR()` |
| 89 | `_inc/expense_monthwise.php`, `_inc/income_monthwise.php` | N+1: 3 consultas por celda y día |
| 90 | `_inc/model/store.php` / `admin/store_single.php:87` | El formulario depende del JS para anteponer `baseUrl + "/_inc/"` |
| 91 | `admin/store_create.php` | Fuerza `deposit_account_id = 1` |
| 92 | `_inc/ajax.php:221` | El autocomplete devuelve un **string pipe-separado** en vez de JSON |
| 93 | `admin/printer.php` | Botón "ver" es un stub |
| 94 | `_inc/lib/user.php` (`User::logout`) | `unset($_SESSION['id'])` sin `session_destroy()` |

---

## 7. Qué reutilizar, qué rehacer, qué descartar

### 7.1 Reutilizar tal cual (dominio y reglas de negocio)

| Elemento | Por qué |
|---|---|
| **El modelo de datos de 73 tablas** | Es el activo real: captura el dominio con detalle (lotes FIFO, crédito, cuotas, transferencias, tarjetas, extractos). Rediseñarlo desde cero es reinventar lo que ya funciona. |
| **La receta de 5 archivos** | Es repetible y reduce la fricción de añadir una entidad. Como convención de proyecto, funciona. |
| **El patrón maestro/bridge multi-store** | Correcto y bien implementado en los modelos. |
| **Las reglas de negocio puras** (cálculo de descuentos, impuestos, FIFO, intereses, saldo de cuenta) | Están verificadas y son correctas en su mayor parte. Se extraen tal cual, con sus correcciones. |
| **Los nombres de dominio** (`selling_info`/`selling_item`/`selling_price`, `purchase_info`/`purchase_item`/`purchase_price`, `holding_*`, `quotation_*`) | La tríada "cabecera / línea / dinero" es un patrón sólido. Se conserva, y además se **documenta**. |
| **Las plantillas de recibo (`pos_templates`) y el parser Lex** | Funcionan y el negocio las usa. |
| **La integración SMS** (`_inc/sms/`) | Implementación real con 5 gateways. Se conserva con las credenciales movidas a `.env`/secret store. |
| **El protocolo de sincronización v2 (`sync.php`)** | Crítico para el despliegue en Venezuela. **No tocar la firma HMAC ni el formato de payload** (`_inc/helper/sync.php` es compartido por ambos lados). |
| **Los scripts de verificación ya escritos** (`crawl_deprecations.ps1`, `run_sync_test.ps1`, `run_sqli_test.ps1`, `test_css_delivery.ps1`, `check_sidebar_cascade.php`, `css_validate.php`) | Son la única red de seguridad existente. Se portan al nuevo proyecto. |

### 7.2 Corregir sobre la marcha (no rehacer desde cero)

| Elemento | Corrección |
|---|---|
| `installment_orders` / `installment_payments` | `WHERE invoice_id` + `store_id` en todos los `UPDATE`; cascada dentro de transacción. |
| `purchase_item` (FIFO) | `ORDER BY id` explícito; `status` como estado del lote con `CHECK`. |
| `expenses` + `bank_transaction_*` | Definir **una** fuente de verdad: el gasto nace en `expenses` y genera un movimiento contable; los informes leen de `expenses`. |
| `income_sources` / `expense_categorys` | Los flags contables (`for_sell`, `for_loan`, …) pasan a ser **datos editables desde la UI** o, mejor, se eliminan del modelo y se sustituyen por el **tipo de movimiento** (§7.3). |
| `stores.preference` / `users.preference` | Se dejan de serializar blobs PHP: pasan a columnas tipadas o tabla `preferences(key, value)` por tienda/usuario. Se elimina la reescritura de `_init.php`. |
| `selling_price` | Columnas de impuesto: separar `tax_rate` (porcentaje) de `tax_amount` (importe) en tablas distintas. |
| `gift_cards` / `gift_card_topups` | `card_id` como FK numérica (no texto), `created_by` como FK. |
| `transfers` / `transfer_items` | Una transferencia = una fila `transfers` + N `transfer_items` (el bucle actual crea N cabeceras). |
| `loan_payments.lloan_id` | Corregir a `loan_id` (con migración). |
| `customer_transactions` | `type` con valores canónicos; el label "Sell" para `purchase` se corrige. |
| `payments.details` | Blob PHP serializado → columnas `json` o tabla de detalle. |

### 7.3 Rehacer (o eliminar)

| Elemento | Decisión |
|---|---|
| **Front completo** (AngularJS 1.6 + jQuery + AdminLTE) | **Rehacer.** Es el motivo de fondo del proyecto. |
| **Capa de autorización** | **Rehacer.** Slugs sueltos → matriz de permisos tipada y verificable en el servidor. |
| **Autenticación y contraseñas** | **Rehacer.** MD5 → `password_hash()` (argon2id/bcrypt). Eliminar `raw_password`. |
| **Checkout y toda operación de escritura** | **Rehacer con transacciones** obligatorias y fila bloqueada (`SELECT … FOR UPDATE`) en stock y saldo. |
| **Contabilidad** | **Rehacer como libro con tipos de movimiento explícitos**, no por flags booleanos dispersos. |
| **`_init.php`** | **No tocar** (está ofuscado), pero **no reescribir en runtime**. La sustitución es la soporte del sistema nuevo. |
| **Módulo de pedidos (`admin/order.php`)** | **Descartar** salvo decisión explícita del negocio (§3.6). |
| **`admin/purchase_edit.php`** | **Descartar** (duplicado de cotización). |
| **`admin/report_pos_overview.php`** | **Descartar** (clon de P&G). |
| **`admin/banner.php`** | **Decidir**: o se enlaza y se arregla, o se descarta. Hoy es inaccesible. |
| **`_inc/ajax.php` con `type`** | **Rediseñar**: un solo endpoint de lectura por recurso, con filtros tipados. |
| **`admin/reset.php`** | **Rehacer**: sólo CLI, con `APPID`, tablas parametrizadas y confirmación explícita. |
| **Callbacks de permisos por columna** | **Eliminar**: la visibilidad la decide el servidor, y el servidor no devuelve el botón si no puede ejecutarlo. |

---

## 8. Propuesta para el sistema nuevo

### 8.1 Principios de diseño

1. **Transacciones obligatorias.** Cada operación de escritura es una unidad atómica. El stock, el saldo y el libro contable se actualizan bajo bloqueo de fila.
2. **Permisos verificados en el servidor, derivables de una única matriz.** La matriz es código (o datos versionados), no un formulario que puede quedarse desincronizado con el código.
3. **El servidor decide qué se ve.** Si un usuario no puede ejecutar una acción, la acción no llega al cliente. Nada de botones que devuelven 422.
4. **Sin secretos en la base de datos ni en el navegador.** Credenciales en variables de entorno / secret store; el cliente recibe sólo lo que necesita.
5. **Una sola fuente de verdad por dato.** Cada cifra tiene un dueño: el saldo de caja, el saldo del cliente, el stock, el beneficio.
6. **El dominio primero, la interfaz después.** La lógica de negocio vive en un núcleo testeable, independiente de HTTP y de la UI.

### 8.2 Stack propuesto

| Capa | Elección | Razón |
|---|---|---|
| Lenguaje backend | **PHP 8.3 + Laravel 11** o **Node 22 + NestJS** | El equipo ya conoce PHP; el dominio es puramente transaccional y CRUD+reglas. Si se quiere un front moderno sin fricción, NestJS + React/Next es la alternativa más directa. |
| Front | **React 19 + TypeScript + Vite** (o Next.js si se necesita SSR/rutas públicas) | Reemplaza AngularJS 1.6 + jQuery + Bootstrap 3.3.5 + AdminLTE. |
| UI kit | **Tailwind CSS 4 + shadcn/ui** (o PrimeReact/Tailwind para mayor madurez en tablas) | El POS necesita densidad de información y velocidad de teclado; shadcn + TanStack Table cubre eso. |
| Datos | **PostgreSQL 17** | `decimal(25,4)` nativo, JSONB para metadatos, `SELECT … FOR UPDATE` robusto, y el modelo maestro/bridge se expresa igual o mejor. |
| ORM / acceso | **Eloquent / Prisma / SQL explícito con repositorios** | Las consultas analíticas del legacy (los 21 reportes) se benefician de SQL explícito; el resto, de un ORM. |
| Cola y trabajos | **Laravel Queues / BullMQ** | SMS, impresión remota, sincronización offline→online y backups no pueden vivir en el ciclo de una request. |
| Impresión | **Esc/POS en worker + plantilla por tienda** | La impresión actual es síncrona y frágil. |
| Tests | **PHPUnit/Pest** (o **Vitest** + **Playwright** para E2E del POS) | Hoy no hay ninguno. Es la mayor ganancia de calidad. |
| Despliegue | **Docker Compose** (app + worker + PostgreSQL + Redis + Nginx) | Reproducible, y permite los Terminales POS Windows/Linux sin fricción. |

**Recomendación concreta**: si el objetivo es "sistema nuevo con vista moderna" y el equipo es PHP, **Laravel 11 + Inertia.js + React + Tailwind + PostgreSQL** da la ruta más corta: un solo lenguaje, un solo build, transacciones y colas de serie, y la posibilidad de servir el POS como PWA instalable en los terminales.

### 8.3 Arquitectura objetivo

```
┌───────────────────────────────────────────────────────────────┐
│  Cliente web / PWA POS (React + TS + Tailwind)                │
│  · POS táctil      · Backoffice      · Kiosco de autoservicio │
└───────────────────────────┬───────────────────────────────────┘
                            │ HTTP/JSON (REST) + WebSocket (estado de caja)
┌───────────────────────────▼───────────────────────────────────┐
│  API  (Laravel / NestJS)                                      │
│  · Auth (OIDC-ready, argon2id, sesiones + refresh)             │
│  · Policy layer: permisos verificados server-side              │
│  · Resources: sales, purchases, products, stock, customers,    │
│               suppliers, accounting, reports, transfers…       │
│  · OpenAPI como contrato único con el front                    │
└───────────────────────────┬───────────────────────────────────┘
                            │
┌───────────────────────────▼───────────────────────────────────┐
│  Dominio (módulos de aplicación)                               │
│  · SellingService  → CheckoutService  (transaccional)         │
│  · PurchasingService                                            │
│  · InventoryService  (lotes FIFO, transferencias)              │
│  · AccountingService  (libro con tipos de movimiento)         │
│  · CustomerAccountService (saldo, deuda, extracto)             │
│  · InstallmentService                                           │
│  · GiftCardService                                              │
│  · PricingService (descuentos, impuestos, redondeos)          │
└───────────────────────────┬───────────────────────────────────┘
                            │
┌───────────────────────────▼───────────────────────────────────┐
│  Persistencia (PostgreSQL) + Cola (Redis) + Almacenamiento (S3) │
│  · Migraciones versionadas desde el esquema actual             │
│  · Jobs: SMS, impresión, backup, sincronización, reportes     │
└───────────────────────────────────────────────────────────────┘
```

**Cambios estructurales clave respecto al legacy:**

| Legacy | Nuevo | Por qué |
|---|---|---|
| `selling_info` / `selling_item` / `selling_price` | `sales`, `sale_items`, `sale_totals` (o `sale_payments`) | Mismo patrón, nombres legibles y FKs reales |
| `purchase_item` como lote | `inventory_lots` (id, product_id, store_id, qty_in, qty_out, unit_cost, received_at, **ORDER BY id**) | El FIFO deja de depender de `status` mutable |
| `selling_price` con 30 columnas de importes | Igual, pero con **un solo origen** por importe y columnas de tasa separadas de las de importe | Fin del lío porcentaje/importe |
| `expenses` + `bank_transaction_*` con flags | `ledger_entries` con `entry_type` (enum explícito) + `ledger_lines` | La clasificación deja de estar en flags booleanos |
| `user_group.permission` (blob serializado) | Tabla `permissions` + `role_permissions` + `role_store_scopes` | La matriz es consultable y verificable |
| `stores.preference` (blob) | `store_settings(key, value)` tipado, o columnas | Se elimina la reescritura de `_init.php` y el "replace" que pierde claves |
| `payments.details` (blob serializado) | `sale_payments` con columnas | Datos consultables |
| `*_to_store` (12 tablas puente) | Se mantiene el patrón, pero con FKs reales y una política de borrado explícita | Es una decisión correcta |
| `_inc/ajax.php?type=…` | Recursos REST con filtros tipados | Se elimina la concatenación de SQL |
| `SSP::simple` + `aoColumns` | Endpoint de listados paginated con un contrato declarado (filtros, orden, columnas visibles por rol) | Fin del desalineamiento HTML/JSON |
| Sin tests | Suite de tests del dominio: checkout, FIFO, impuestos, crédito, cuotas, transferencias, reversión por devolución | La garantía que hoy no existe |

### 8.4 Plan de migración por fases

**Fase 0 — Cimientos (2-3 semanas)**
1. Crear el repositorio nuevo, CI, y el esqueleto de la app + base de datos.
2. Escribir el **mapeo de esquema**: migraciones que creen el modelo destino desde cero con los mismos datos (ETL desde MySQL, no una copia de tablas).
3. Escribir un **importador idempotente** de las 73 tablas, con validación de recuento y de importes al cierre de cada tabla.
4. Escribir los **tests del dominio** sobre el modelo destino (FIFO, totales, IVA, crédito) — son la prueba de que la migración no cambió las reglas.

**Fase 1 — Backend del núcleo (4-6 semanas)**
5. Auth, roles y permisos reales; tiendas y scoping.
6. Catálogo: productos, categorías, marcas, unidades, cajas, impuestos, proveedores, clientes.
7. Compras + entradas de stock por lotes.
8. **Ventas / checkout transaccional** (el módulo crítico) + recibos + impresión.
9. Caja y libro contable.

**Fase 2 — Operación (4-6 semanas)**
10. Devoluciones (venta y compra), notas de crédito, ajustes.
11. Transferencias entre almacenes.
12. Cuotas, préstamos, tarjetas de regalo, extractos de cliente.
13. Cotizaciones y pedidos en espera.
14. Cola: SMS, impresión remota, backups.

**Fase 3 — Reportes y migración final (3-4 semanas)**
15. Los 21 reportes, sobre el modelo nuevo (y corregir los que estaban mal: impuestos de compra, beneficio real, cobros).
16. Panel y analítica.
17. **Migración en vivo**: drier de 2 semanas con el sistema legacy y el nuevo en paralelo sobre el mismo negocio, comparando totales a diario (facturas, cobros, stock, caja) hasta cuadrar.
18. Corte: congelar legacy, última sincronización, cambiar DNS, mantener el legacy en sólo lectura durante 30 días.

**Riesgos a vigilar:**

| Riesgo | Mitigación |
|---|---|
| El negocio depende de comportamientos que el legacy tiene "bugs" (p. ej. que la devolución se vea pero no se pueda editar) | Documentar los comportamientos **a mano** en el §3 antes de migrar; convertir cada decisión en un test. |
| Datos históricos malformados (contadores de caja incorrectos, categorías `is_hide=1`) | No migrar los contadores derivados (`bank_accounts.total_*`): se recalculan. Migrar sólo los hechos (`bank_transaction_info/price`). |
| El POS debe seguir funcionando durante la transición | El POS nuevo se despliega **antes** que el backoffice, en un solo terminal, y se despliega por.store. |
| La sincronización offline→online es crítica | Mantener `sync.php` y su protocolo v2 **intactos** hasta el final; el nuevo sistema debe hablar el mismo protocolo desde el primer día (adaptador, no reescritura). |
| La impresión de tickets es un punto de fallo físico | Mantener el motor actual de tickets como componente aislado en el nuevo stack hasta que la impresión sea estable. |

### 8.5 Módulos que deben quedar fuera del alcance inicial

Para no repetir el problema del legacy (110 pantallas y un menú de 117 entradas), el alcance inicial debe ser explícito:

| Fuera de alcance inicial | Motivo |
|---|---|
| Módulo de pedidos (`admin/order.php`) | Roto y huérfano. Decidir con el negocio antes. |
| Banners | Huérfano y sin permisos. |
| Analítica avanzada / cohortes | No existe hoy; es un módulo nuevo, no una migración. |
| Facturación electrónica / SENIAT | Es un requisito **nuevo** para Venezuela, no una migración del legacy. Planificar aparte. |
| Kiosco de autoservicio | Módulo nuevo. |
| Aplicación móvil nativa | El PWA cubre el caso de uso. |

---

## 9. Decisiones abiertas (requieren respuesta del negocio)

| # | Decisión | Impacto |
|---|---|---|
| 1 | ¿Los **pedidos** (`admin/order.php`) son un módulo real en uso? | Si sí, hay que rehacerlo; si no, se descarta. |
| 2 | ¿Se conserva la **impresión en red/local** (`printers`, `PrintReceiptModal`) o se migra a impresión de servidor? | Cambia el modelo de `stores` y el worker de impresión. |
| 3 | ¿La **sincronización offline→online** sigue siendo requisito en Venezuela? | Si sí, el protocolo v2 es un contrato fijo: se implementa como adaptador desde el día 1. |
| 4 | ¿Hace falta **facturación electrónica** (SENIAT / Venezuela)? | Módulo nuevo con impacto en `selling_info`/`selling_price`. |
| 5 | ¿Se migran los **datos históricos** o se arranca en limpio con el saldo inicial? | Decidible: los saldos y el stock se recalculan; los movimientos se pueden archivar aparte. |
| 6 | ¿Cuántas **tiendas** y cuántos **usuarios** reales hay? | Determina si el multi-store se mantiene o se simplifica a un solo almacén con varias ubicaciones. |
| 7 | ¿Se conserva el **modelo de cuotas con interés** tal cual? | La lógica actual (`interest_percentage` sobre el payable) es simple; si el negocio necesita amortización real, es un módulo nuevo. |
| 8 | ¿Se conserva el **cobro por WhatsApp/SMS** con gateways concretos? | Define qué credenciales se migran y a dónde. |
| 9 | ¿El **nuevo sistema** replaces al legacy, o conviven? | Si conviven, hay que definir la fuente de verdad por tabla durante la transición. |
| 10 | ¿Quién mantiene el **nuevo** sistema a partir del día 1? | El legacy no tiene CI ni tests; el nuevo debe empezar con ambos desde el primer commit. |

---

## Anexo A — Verificación rápida del inventario

Para revalidar cualquier dato de este documento:

```bash
# Tablas del esquema (73)
grep -oP 'CREATE TABLE (IF NOT EXISTS )?`?\K[A-Za-z_0-9]+' "BASE DE DATOS/pos_moderno.sql" | sort

# Entradas del menú lateral (117) y sus condiciones
grep -n "menu_\|has_permission" admin/left_sidebar.php

# Slugs de permiso: la matriz real
grep -oP "access\[\K[a-z_]+" _inc/template/user_group_form.php | sort -u

# Slugs usados en código pero ausentes de la matriz
grep -rhoP "has_permission\('access',\s*'\K[a-z_]+" _inc/ admin/ | sort -u \
  | comm -23 - <(grep -oP "access\[\K[a-z_]+" _inc/template/user_group_form.php | sort -u)

# Trampas P0: UPDATE sin WHERE
grep -rn "UPDATE .*SET" _inc/ | grep -v "WHERE"

# Trampas P1: concatenación en SQL
grep -rn "\$where_query\|\$data\['filter_\|query_string" _inc/ | grep -v "?"
```

## Anexo B — Glosario

| Término | Significado en este sistema |
|---|---|
| **POS** | Punto de venta (`admin/pos.php`); pantalla táctil de cobro. |
| **Lote / FIFO** | Cada compra genera uno o varios lotes en `purchase_item`; la venta consume del más antiguo. `status`: `stock` (pendiente) → `active` (disponible) → `sold` (agotado). |
| **Crédito** | Pago con saldo del cliente (`customer_to_store.balance`). |
| **Deuda** | Saldo pendiente de pago (`customer_to_store.due`). |
| **Extracto** | Historial de movimientos de saldo del cliente (`customer_transactions`). |
| **Holding** | Pedido en espera en el POS (`holding_info`/`holding_item`/`holding_price`). |
| **Cotización** | Presupuesto (`quotation_info` + `is_order=0`); se convierte en venta desde el POS (`?qref=`). |
| **Caja** | Cuenta de efectivo (`bank_accounts`) con saldo por tienda (`bank_account_to_store`). La cuenta por defecto de la tienda es `stores.deposit_account_id`. |
| **Libro de caja** | `bank_transaction_info` + `bank_transaction_price`: todos los ingresos y salidas de efectivo. |
| **Store** | Tienda/punto de venta físico; el contexto activo está en `$_SESSION['store_id']`. |
| **Bridge** | Tabla `*_to_store`: estado por tienda de una entidad maestra. |
| **Slug** | Nombre del permiso, p. ej. `read_sell_list`. |
| **dt** | Nombre de la columna que DataTables pide al backend. |




