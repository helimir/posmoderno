-- Spanish translation of the seed data the vendor shipped in English
-- (expense categories, income sources, payment methods, brands,
--  units, boxes, tax rates and currency names) and of the keys with
-- an illegible wording.

START TRANSACTION;

UPDATE expense_categorys SET category_name = 'Salario de Empleado', category_slug = 'salario_de_empleado' WHERE category_id = 1;
UPDATE expense_categorys SET category_name = 'Alquiler del Local', category_slug = 'alquiler_del_local' WHERE category_id = 2;
UPDATE expense_categorys SET category_name = 'Factura de Luz y Cable', category_slug = 'factura_de_luz_y_cable' WHERE category_id = 3;
UPDATE expense_categorys SET category_name = 'Publicidad', category_slug = 'publicidad' WHERE category_id = 4;
UPDATE expense_categorys SET category_name = 'Bonos', category_slug = 'bonos' WHERE category_id = 5;
UPDATE expense_categorys SET category_name = 'Otros', category_slug = 'otros' WHERE category_id = 6;
UPDATE expense_categorys SET category_name = 'Devoluciones', category_slug = 'devoluciones' WHERE category_id = 7;
UPDATE expense_categorys SET category_name = 'Devolucion de Ventas', category_slug = 'devolucion_de_ventas' WHERE category_id = 8;
UPDATE expense_categorys SET category_name = 'Compra de Productos', category_slug = 'compra_de_productos' WHERE category_id = 9;
UPDATE expense_categorys SET category_name = 'Eliminacion de Ventas', category_slug = 'eliminacion_de_ventas' WHERE category_id = 10;
UPDATE expense_categorys SET category_name = 'Eliminacion de Prestamos', category_slug = 'eliminacion_de_prestamos' WHERE category_id = 11;
UPDATE expense_categorys SET category_name = 'Eliminacion de Recarga de Tarjeta', category_slug = 'eliminacion_de_recarga_de_tarjeta' WHERE category_id = 12;
UPDATE expense_categorys SET category_name = 'Eliminacion de Venta de Tarjeta', category_slug = 'eliminacion_de_venta_de_tarjeta' WHERE category_id = 13;
UPDATE expense_categorys SET category_name = 'Pago de Prestamos', category_slug = 'pago_de_prestamos' WHERE category_id = 14;
UPDATE expense_categorys SET category_name = 'Pago Pendiente a Proveedor', category_slug = 'pago_pendiente_a_proveedor' WHERE category_id = 15;
UPDATE income_sources SET source_name = 'Ventas', source_slug = 'ventas' WHERE source_id = 1;
UPDATE income_sources SET source_name = 'Devolucion de Compras', source_slug = 'devolucion_de_compras' WHERE source_id = 2;
UPDATE income_sources SET source_name = 'Cobranza de Deudas', source_slug = 'cobranza_de_deudas' WHERE source_id = 3;
UPDATE income_sources SET source_name = 'Otros', source_slug = 'otros' WHERE source_id = 4;
UPDATE income_sources SET source_name = 'Prestamo Otorgado', source_slug = 'prestamo_otorgado' WHERE source_id = 5;
UPDATE income_sources SET source_name = 'Venta de Tarjeta de Regalo', source_slug = 'venta_de_tarjeta_de_regalo' WHERE source_id = 6;
UPDATE income_sources SET source_name = 'Recarga de Tarjeta de Regalo', source_slug = 'recarga_de_tarjeta_de_regalo' WHERE source_id = 7;
UPDATE income_sources SET source_name = 'Transferencia de Inventario', source_slug = 'transferencia_de_inventario' WHERE source_id = 8;
UPDATE income_sources SET source_name = 'Eliminacion de Compra', source_slug = 'eliminacion_de_compra' WHERE source_id = 9;
UPDATE income_sources SET source_name = 'Eliminacion de Gasto', source_slug = 'eliminacion_de_gasto' WHERE source_id = 10;
UPDATE pmethods SET name = 'Contra entrega', details = 'Detalles del metodo de pago contra entrega' WHERE pmethod_id = 1;
UPDATE pmethods SET name = 'Tarjeta de regalo', details = 'Detalles del metodo de pago mediante tarjeta de regalo' WHERE pmethod_id = 3;
UPDATE pmethods SET name = 'Credito', details = 'Pago mediante saldo a favor del cliente' WHERE pmethod_id = 4;
UPDATE currency SET title = 'Dolar Estadounidense' WHERE code = 'USD';
UPDATE currency SET title = 'Libra Esterlina' WHERE code = 'GBP';
UPDATE currency SET title = 'Euro' WHERE code = 'EUR';
UPDATE currency SET title = 'Dolar de Hong Kong' WHERE code = 'HKD';
UPDATE currency SET title = 'Rupia India' WHERE code = 'INR';
UPDATE currency SET title = 'Rublo Ruso' WHERE code = 'RUB';
UPDATE currency SET title = 'Yuan Renminbi Chino' WHERE code = 'CNY';
UPDATE currency SET title = 'Dolar Australiano' WHERE code = 'AUD';
UPDATE currency SET title = 'Taka Bangladesi' WHERE code = 'BDT';
UPDATE currency SET title = 'Rupia Pakistani' WHERE code = 'PKR';
UPDATE currency SET title = 'Kuna Croata' WHERE code = 'HRK';
UPDATE currency SET title = 'Ringgit Malasio' WHERE code = 'MYR';
UPDATE currency SET title = 'Riyal Saudi' WHERE code = 'SAR';
UPDATE currency SET title = 'Dolar Canadiense' WHERE code = 'CAD';
UPDATE currency SET title = 'Soles' WHERE code = 'PEN';
UPDATE `brands` SET `brand_name` = 'Sin marca' WHERE `brand_name` = 'No brand';
UPDATE `brands` SET `code_name` = 'sin_marca' WHERE `code_name` = 'No brand';
UPDATE `categorys` SET `category_name` = 'Sin categoria' WHERE `category_name` = 'uncategory';
UPDATE `categorys` SET `category_slug` = 'sin_categoria' WHERE `category_slug` = 'uncategory';
UPDATE `units` SET `unit_name` = 'Piezas' WHERE `unit_name` = 'Pieces';
UPDATE `units` SET `code_name` = 'piezas' WHERE `code_name` = 'Pieces';
UPDATE `boxes` SET `box_name` = 'Caja Comun' WHERE `box_name` = 'Common Box';
UPDATE `boxes` SET `code_name` = 'caja_comun' WHERE `code_name` = 'Common Box';
UPDATE `taxrates` SET `taxrate_name` = 'Sin impuesto' WHERE `taxrate_name` = 'No Tax';
UPDATE `taxrates` SET `code_name` = 'sin_impuesto' WHERE `code_name` = 'No Tax';
UPDATE `taxrates` SET `taxrate_name` = 'IVA @0%' WHERE `taxrate_name` = 'GST @0%';
UPDATE `taxrates` SET `code_name` = 'iva_0' WHERE `code_name` = 'GST @0%';
UPDATE `taxrates` SET `taxrate_name` = 'IVA @18%' WHERE `taxrate_name` = 'GST @18%';
UPDATE `taxrates` SET `code_name` = 'iva_18' WHERE `code_name` = 'GST @18%';
UPDATE `taxrates` SET `taxrate_name` = 'IVA @10%' WHERE `taxrate_name` = 'VAT @10%';
UPDATE `taxrates` SET `code_name` = 'iva_10' WHERE `code_name` = 'VAT @10%';
UPDATE language_translations SET lang_value = 'CATEGORIA DE GASTO' WHERE lang_id = 7 AND lang_key = 'label_exp_category';
UPDATE language_translations SET lang_value = 'MONTO' WHERE lang_id = 7 AND lang_key = 'label_amount';
UPDATE language_translations SET lang_value = 'NUMERO DE REFERENCIA' WHERE lang_id = 7 AND lang_key = 'label_ref_no';
UPDATE language_translations SET lang_value = 'CONCEPTO' WHERE lang_id = 7 AND lang_key = 'label_about';
INSERT INTO currency (code, title, symbol_left, symbol_right, decimal_place, value) VALUES ('VES', 'Bolivar Venezolano', 'Bs', '', 2, 1);

COMMIT;
