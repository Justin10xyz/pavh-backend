# Spec — Inventario: Catálogo de Productos (Fase 1: modelo base)
 
## Objetivo de esta fase
 
Crear la capa de datos del catálogo de productos: estructura padre/variante, generación automática de código propio, y datos de ejemplo reales (línea Creato, Interceramic) para verificar que el modelo funciona antes de construir CRUD/API o vistas.
 
**Fuera de alcance en esta fase** (se abordan en specs siguientes): controlador/rutas API, vistas de frontend, importador de listas de precios de proveedores, altas/bajas de stock con historial de movimientos, ficha técnica completa (PEI/ETT/tráfico).
 
## Contexto de negocio
 
- Un **producto** (padre) es una línea/colección — ej. "Creato". Vive en la tabla `productos`.
- Una **variante** es la combinación color + medida dentro de esa línea — ej. Creato / Taupe / 60x120. Es la unidad real que se vende, tiene precio y stock. Vive en `producto_variantes`.
- El proveedor vende por caja; el cliente factura/cotiza por m² o por pieza según el producto. La caja es la unidad real de inventario; m²/piezas se calculan a partir de un factor de conversión que el proveedor ya provee (m² por caja).
- El código interno del proveedor (ej. `IN.CPRE.ANNT.1250.1001.1`) se guarda solo como referencia libre — no es confiable como llave (incompleto, formato inconsistente entre listas).
## Estructura de datos
 
Tablas y columnas en inglés (convención de código del proyecto); el contenido de negocio (valores como "Creato", "Taupe") se mantiene como venga del proveedor.
 
### Tabla `categories`
| Campo | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| name | string | ej. "Floor" — nombre en inglés, consistente con el resto del código |
| code_prefix | string | ej. "PIS" — el prefijo que se usa al generar el código de variante. Vive aquí (no hardcodeado en el servicio) para poder agregar categorías sin tocar código |
| timestamps | | |
 
Se siembra con un solo registro por ahora (`Floor` / `PIS`); se agregan más cuando definas los demás tipos de producto.
 
### Tabla `unit_types`
| Campo | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| name | string | ej. "m2", "piece" |
| timestamps | | |
 
Se siembra con dos registros (`m2`, `piece`) que cubren los casos que ya conocemos.
 
### Tabla `suppliers`
| Campo | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| name | string | ej. "Interceramic" |
| timestamps | | |
 
Se crea desde ya aunque hoy solo haya un proveedor — evita hardcodear y facilita el importador de listas de precios más adelante.
 
### Tabla `products` (padre — la línea)
| Campo | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| supplier_id | FK → suppliers | |
| category_id | FK → categories | |
| unit_type_id | FK → unit_types | vive en el padre: toda la línea se vende igual, no cambia por variante |
| name | string | ej. "Creato" |
| purchase_unit | string | por ahora siempre `caja` |
| timestamps | | |
 
### Tabla `product_variants` (color + medida — lo que se vende y tiene stock)
| Campo | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| product_id | FK → products | |
| code | string, unique | generado por el sistema, ej. `PIS-CREATO-TAU-60X120` |
| supplier_code | string, nullable | referencia libre, ej. `IN.CPRE.ANNT.1250.1001.1` |
| color | string | ej. "Taupe" |
| size | string | ej. "60X120" (normalizada: mayúsculas, sin "x" minúscula, sin espacios) |
| price_per_m2 | decimal(10,2) | |
| price_per_box | decimal(10,2) | |
| pieces_per_box | integer, nullable | |
| m2_per_box | decimal(8,3), nullable | factor de conversión — null si `unit_type` del producto padre es `piece` |
| kilos_per_box | decimal(8,2), nullable | |
| boxes_per_pallet | integer, nullable | |
| stock_boxes | integer, default 0 | unidad real de inventario |
| minimum_stock | integer, nullable | umbral de alerta de stock bajo |
| timestamps | | |
 
**Nota sobre categorías y prefijos de código:** al ser tabla normalizada, el prefijo (`PIS`) vive en `categories.code_prefix` — el servicio de generación de código lo lee de ahí, nunca lo hardcodea. Así, agregar una categoría nueva (ej. "Material" / `MAT`) es un insert, no un deploy.
 
## Generación de código de variante
 
**Formato:** `[PREFIJO_CATEGORIA]-[LINEA]-[COLOR]-[MEDIDA]`
 
Ejemplo: `PIS-CREATO-TAU-60X120`
 
**Reglas de construcción:**
1. **Prefijo de categoría**: se lee de `categories.code_prefix` (ej. `PIS` para "Floor"). Nunca hardcodeado en el servicio — así agregar categorías nuevas no requiere tocar código.
2. **Línea**: nombre completo del producto padre, mayúsculas, sin espacios (ej. "Essential Mood" → `ESSENTIALMOOD`).
3. **Color**: primeras 3 letras de la primera palabra del color, mayúsculas (ej. "Taupe" → `TAU`, "Cool Powder" → `COO`, "Gray Canvas" → `GRA`).
4. **Medida**: tal cual, mayúsculas, sin espacios, "x" normalizada a "X" (ej. "60x120" → `60X120`).
**Manejo de colisión:**
Antes de guardar, se valida unicidad del código completo contra la base de datos.
- Si hay colisión (ej. "Gray" y "Graphite" en la misma línea ambos truncan a `GRA`), se resuelve extendiendo el segmento de color a 4 letras (`GRAY`, `GRAP`).
- Si sigue habiendo colisión con 4 letras, se agrega un dígito incremental al final del código completo (`PIS-CREATO-GRA2-60X120`) como última salida.
- Esta lógica vive en un servicio dedicado (`VariantCodeGenerator`, no en el modelo ni el controlador) para poder testearla de forma aislada.
## Datos de ejemplo para verificación (seeder)
 
Usar los datos reales de la línea Creato (Interceramic) para poblar y verificar el modelo:
 
| Medida | Colores | Precio m² | Precio caja | Piezas/caja | m²/caja |
|---|---|---|---|---|---|
| 60X120 | Taupe, Terracota, Ivory, Gray, Graphite | 359.00 | 516.96 | 2 | 1.440 |
| 30X60 | Taupe, Terracota, Ivory, Gray, Graphite, Teal, Espresso | 199.00 | 322.38 | 9 | 1.620 |
| 20X20 | Teal, Terracota, Taupe, Gray Canvas | 199.00 | 199.00 | 25 | 1.000 |
| 20X20 | Teal, Espresso, Taupe Blend | 229.00 | 229.00 | 25 | 1.000 |
 
(nota: hay dos variantes 20X20 con distinto set de colores y distinto precio — son grupos de color separados dentro de la misma medida; al armar el seeder, generar una fila de variante por cada color individual, no por grupo)
 
## Pasos escoteados para Claude Code
 
1. Migración `categories` + modelo `Category`, con seeder que inserte `Floor` / `PIS`
2. Migración `unit_types` + modelo `UnitType`, con seeder que inserte `m2` y `piece`
3. Migración `suppliers` + modelo `Supplier`, con seeder que inserte "Interceramic"
4. Migración `products` + modelo `Product` (FKs a `Category`, `UnitType`, `Supplier`; relación `hasMany` a `ProductVariant`)
5. Migración `product_variants` + modelo `ProductVariant` (con relación `belongsTo` a `Product`)
6. Servicio `VariantCodeGenerator` con su lógica de colisión (leyendo el prefijo desde `Category`), aislado y testeable
7. Test unitario del servicio de generación de código (casos: normal, colisión simple, colisión doble)
8. Seeder con los datos reales de Creato (tabla de arriba) usando el servicio de generación de código
9. Verificación manual: correr los seeders, confirmar en base de datos que los códigos generados son correctos y únicos
**No incluir en estos pasos:** controlador, rutas, endpoints API, ni nada de frontend — eso es la siguiente spec.
