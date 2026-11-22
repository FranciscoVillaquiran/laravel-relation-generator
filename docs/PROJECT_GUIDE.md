# Diseño y funcionamiento de Laravel Relation Generator

Este documento describe la arquitectura actual de LRG `v3.0.0`, incluyendo
las capacidades heredadas y las limitaciones que requieren decisiones o
configuración de la aplicación.

## 1. Qué es el proyecto

Laravel Relation Generator es una biblioteca PHP para Laravel que registra un
comando Artisan:

```text
relation:make
```

El comando conecta dos modelos Eloquent según las migraciones existentes.
Puede:

1. Añadir métodos de relación a los modelos.
2. Crear o actualizar las propiedades `$fillable` leyendo las migraciones.
3. Añadir métodos de consulta a `ConsultasController`.
4. Añadir rutas GET a `routes/web.php`.
5. Generar controladores CRUD estructurales para ambos modelos.
6. Registrar los controladores en rutas REST API.

No crea modelos ni migraciones, no ejecuta migraciones y no genera autenticación
ni reglas de negocio. La modalidad CRUD requiere modelos Eloquent y migraciones
existentes en la aplicación Laravel.

## 2. Estructura del paquete

```text
src/
├── Console/Commands/RelationMakeCommand.php
├── Generators/
│   ├── CrudGenerator.php
│   ├── FileChangeTransaction.php
│   ├── FillableGenerator.php
│   ├── ApiRouteGenerator.php
│   ├── MigrationReader.php
│   ├── QueryGenerator.php
│   ├── RelationshipGenerator.php
│   ├── RouteGenerator.php
│   └── StructuralSchema/
│       ├── DatabaseSchema.php
│       ├── MigrationParser.php
│       ├── SchemaBuilder.php
│       └── schema value objects
├── Relations/RelationDefinition.php
└── RelationGeneratorServiceProvider.php
```

| Archivo | Responsabilidad |
|---|---|
| `RelationGeneratorServiceProvider.php` | Registra el comando cuando Laravel se ejecuta en consola. |
| `Console/Commands/RelationMakeCommand.php` | Valida argumentos/opciones, prepara los cambios, coordina los generadores y confirma el índice. |
| `Relations/RelationDefinition.php` | Transporta los modelos, la cardinalidad y los nombres opcionales de tabla. |
| `Generators/RelationshipGenerator.php` | Calcula los nombres de las relaciones y añade sus métodos a los modelos. |
| `Generators/MigrationReader.php` | Conserva `getColumns()` para el flujo previo y ofrece `analyze()` para obtener el esquema normalizado. |
| `Generators/StructuralSchema/MigrationParser.php` | Tokeniza e interpreta el subconjunto estático admitido de migraciones. |
| `Generators/StructuralSchema/SchemaBuilder.php` | Aplica cambios de esquema en orden y deriva las relaciones estructurales. |
| `Generators/FillableGenerator.php` | Construye y escribe `$fillable` en un modelo. |
| `Generators/QueryGenerator.php` | Crea o actualiza `ConsultasController` con métodos que cargan relaciones. |
| `Generators/RouteGenerator.php` | Añade rutas GET que apuntan a los métodos del controlador. |
| `Generators/CrudGenerator.php` | Genera controladores CRUD sencillos que delegan las operaciones a Eloquent. |
| `Generators/ApiRouteGenerator.php` | Acumula recursos en `routes/lrg_api.php` y mantiene una inclusión en `routes/api.php`. |
| `Generators/FileChangeTransaction.php` | Conserva backups, aplica escrituras preparadas y recupera archivos si la operación falla. |

El autoload PSR-4 del paquete usa el prefijo
`Franciscovillaquiran\LaravelRelationGenerator\` para el directorio `src/`.
Debe respetarse esa capitalización, especialmente en sistemas de archivos
sensibles a mayúsculas.

## 3. Instalación y registro del comando

`composer.json` declara el paquete como biblioteca, el autoload PSR-4 y el
service provider en `extra.laravel.providers`. Laravel puede descubrir el
provider mediante el descubrimiento de paquetes de Composer. El provider no
registra servicios en `register()`; en `boot()` comprueba
`$this->app->runningInConsole()` y registra `RelationMakeCommand`.

Requisitos declarados:

- PHP `^8.1`.
- `illuminate/support` `^10.0 || ^11.0 || ^12.0 || ^13.0`.

La restricción permite resolver versiones de Illuminate de Laravel 10 a 13,
sujetas también a la versión de PHP requerida por cada versión de Laravel. El
`composer.lock` del repositorio fija las dependencias de desarrollo a una
resolución concreta; no representa por sí mismo todas las combinaciones de
Laravel soportadas por la restricción.

## 4. Interfaz y flujo del comando

La firma conserva los tres argumentos posicionales y agrega dos opciones
booleanas:

```bash
php artisan relation:make ModelA ModelB 1:N
php artisan relation:make Category Product 1:N --crud --api
```

Los valores admitidos para `cardinality` son exactamente `1:1`, `1:N` y `N:M`.
La validación de cardinalidad ocurre antes de preguntar qué generar. Si no es
válida, el comando muestra un error y devuelve `Command::FAILURE`.

Sin `--crud` ni `--api`, el comando conserva la selección interactiva. La
selección predeterminada es `todo`, que mantiene la generación de los cuatro
elementos heredados:

| Selección | Operación |
|---|---|
| `relacion` | Métodos de relación en ambos modelos. |
| `fillable` | Propiedades `$fillable` de ambos modelos. |
| `consultas` | Métodos de consulta de ambos modelos en `ConsultasController`. |
| `rutas` | Rutas GET para las dos direcciones. |
| `todo` | Ejecuta las cuatro operaciones anteriores. |

Se pueden indicar varias opciones separadas por comas, por ejemplo
`relacion, fillable`. Se eliminan espacios alrededor de cada opción, se ignoran
segmentos vacíos y se eliminan selecciones repetidas. La selección es
insensible a mayúsculas porque cada segmento se convierte a minúsculas. `todo`
no se puede combinar con otras opciones. Un valor desconocido o una selección
vacía se rechaza.

Los nombres aceptados son los literales del cuadro: por ejemplo, `relacion`,
no `relación`. La modalidad `--crud` omite el prompt y selecciona relaciones,
fillable y CRUD para ambos modelos. `--api` debe combinarse con `--crud`;
genera además las rutas API. La modalidad CRUD no genera
`ConsultasController` ni rutas web.

### Flujo y preparación

Antes de escribir en la aplicación, el comando:

1. Valida cardinalidad, modelos, tablas y metadatos estructurales.
2. Lee el índice o interpreta migraciones, sin escribir todavía el índice.
3. Ejecuta los generadores sobre copias temporales de todos los archivos
   objetivo. Allí detecta ambigüedades, configuraciones manuales incompatibles,
   controladores existentes y colisiones de rutas.
4. Comprueba la sintaxis PHP de los resultados preparados.
5. Conserva copias recuperables, aplica el conjunto de cambios y verifica los
   resultados de escritura.
6. Comprueba que las migraciones no cambiaron durante la generación y actualiza
   `.lrg/lrg-index.json` al final.
7. Si una fase falla, recupera los archivos e índice anteriores y devuelve
   `Command::FAILURE`.

La modalidad interactiva ejecuta únicamente los elementos elegidos. Las
selecciones `consultas` y `rutas` siguen dependiendo de los métodos y
controladores heredados; `todo` conserva su significado anterior y no activa
CRUD.

`--crud` necesita modelos en `app/Models` compatibles con Eloquent, tablas y
claves primarias simples. `--api` requiere un archivo `routes/api.php`
registrado en la configuración Laravel admitida y no ambigua. El comando no
solicita tablas ni cardinalidades adicionales: obtiene esa información de las
migraciones o falla si no puede resolverla.

## 5. Datos que se comparten entre componentes

`RelationDefinition` es un objeto sencillo con estas propiedades públicas:

- `modelA`: nombre de clase del primer modelo.
- `modelB`: nombre de clase del segundo modelo.
- `cardinality`: cardinalidad validada por el comando.
- `foreignTable`: tabla opcional para el generador legado `generate()`.
- `pivotTable`: tabla opcional para el generador legado `generate()`.
- `modelATable` / `modelBTable`: nombres físicos de tabla para la generación
  estructural; el comando los toma del modelo Eloquent o de la convención Laravel.

El comando crea esta definición incluso si no se seleccionó `relacion`.
`RelationshipGenerator::relationNames()` usa los nombres y la cardinalidad para
proporcionar los nombres que consume `QueryGenerator`, sin escribir archivos.

## 6. Cómo se generan las relaciones Eloquent

`RelationshipGenerator::generateFromSchema()` usa un `DatabaseSchema` ya
obtenido por `MigrationReader`, resuelve la FK o tabla pivote y valida la
cardinalidad antes de modificar los modelos. El comando usa esta ruta para
`relacion`. El método legado `generate()` se conserva para consumidores previos.
Ambos exigen que los archivos de modelo existan en `app/Models`.

### Nombres, orientación y claves

Los nombres de métodos se calculan con `Illuminate\Support\Str`. En la ruta
estructural, la dirección de la FK decide qué modelo declara `hasOne()` o
`hasMany()` y cuál declara `belongsTo()`:

- `1:1`: requiere una FK cuya columna local sea única; el lado que contiene la
  FK recibe `belongsTo()` y el otro `hasOne()`.
- `1:N`: el lado que contiene la FK recibe `belongsTo()` y el otro `hasMany()`.
- `N:M`: requiere una tabla pivote reconocida por el lector y declara
  `belongsToMany()` en ambos lados.

La salida estructural incluye las claves foráneas/locales/referidas explícitas.
Para `belongsToMany()` se especifican además la tabla pivote, ambas claves
pivote y ambas claves relacionadas; así no se depende del orden convencional
de nombres de tablas.

El comando resuelve los nombres de tabla del modelo Eloquent cuando la clase
está disponible; de otro modo usa `Str::snake()` y `Str::plural()`. Un modelo
con tabla configurada manualmente debe poder cargarse en Laravel para respetar
ese nombre.

No se generan relaciones Eloquent basadas en claves compuestas porque el ORM
no ofrece soporte nativo para ellas. Si más de una FK o pivote puede conectar
los modelos solicitados, se informa la ambigüedad y no se escribe el código.
Una cardinalidad diferente a la declarada en la migración también se rechaza.

### Escritura en los modelos

Los modelos se tokenizan con `token_get_all()` y `TOKEN_PARSE`; no se ejecuta
su código. Los métodos se agregan dentro de la clase detectada. Si el mismo
nombre ya existe con el cuerpo generado, la ejecución es idempotente; un
método con ese nombre y una definición diferente provoca un conflicto. Se
preservan los miembros no relacionados.

## 7. Cómo se localizan las migraciones y se genera `$fillable`

### Lector anterior y análisis estructural

El método público `getColumns($migrationPath)` conserva el comportamiento
histórico: busca nombres de columnas con una expresión regular y reconoce
textualmente `id()`, `timestamps()` y `softDeletes()`. El comando existente
`relation:make` conserva esta API disponible, pero ahora usa el análisis
estructural para generar `$fillable`; la firma del comando no cambia.

El nuevo método `analyze($migrationPaths)` construye el esquema estructural
descrito en la sección 14. Usa `token_get_all()` y un intérprete acotado de
tokens PHP; nunca incluye, evalúa ni ejecuta los archivos de migración.

El método histórico también agrega estos casos reconocidos por texto:

- `$table->id()` agrega `id`.
- `$table->timestamps()` agrega `created_at` y `updated_at`.
- `$table->softDeletes()` agrega `deleted_at`.

Al final elimina duplicados. `getColumns()` no analiza el AST, las variables,
condiciones o helpers de la migración. Las llamadas sin un nombre explícito de
columna y variantes de formato que no coincidan con los textos buscados pueden
no detectarse. El comando nuevo ya no usa `getColumns()` para generar `$fillable`.
El analizador estructural sí reconoce `$table->rememberToken()` como una
columna nullable `string(100)`, y la excluye de `$fillable`.

### Escritura de `$fillable`

`FillableGenerator::generateFromTable()` requiere un modelo existente y usa
`SchemaTable`. Excluye todas las columnas de PK, las autoincrementales,
`created_at`, `updated_at`, `deleted_at` y `remember_token`. Las demás columnas
incluidas por el lector, también las FK, son candidatos estructurales.

Si no existe `$fillable`, crea una lista literal. Si existe una lista literal
estática, solo acepta que ya incluya todos los candidatos y la conserva sin
cambios; si faltan columnas, hay `$guarded`, o la configuración es dinámica o
ambigua, informa el conflicto en vez de ampliar o reemplazar una allowlist
manual. Se eliminan duplicados al generar una lista nueva.

La admisibilidad estructural no es una política de autorización: una migración
no indica qué campos puede asignar cada usuario. Revise manualmente campos
sensibles (por ejemplo credenciales, roles o permisos) antes de aceptar una
allowlist generada.

## 8. Cómo se genera `ConsultasController`

La ruta web heredada de destino es fija:

```text
app/Http/Controllers/ConsultasController.php
```

Si el archivo no existe, `QueryGenerator` crea el directorio necesario y un
controlador `App\Http\Controllers\ConsultasController` que extiende a
`Controller`. El stub incluye `use Illuminate\Http\Request;`, aunque los
métodos generados no reciben ni utilizan `Request`.

Después añade imports `App\Models\<Modelo>` para ambos modelos y dos métodos:

```text
consultas<ModelA><ModelB>()
consultas<ModelB><ModelA>()
```

Cada método ejecuta `Modelo::with(['relacion'])->get()` y devuelve el resultado
de la consulta. El nombre de la relación se obtiene de la cardinalidad según
la tabla de nombres de la sección anterior.

El generador inserta imports después de la declaración `namespace` y métodos
antes de la última llave. Si el controlador ya existe, se asume que tiene una
declaración de namespace y una clase compatible. La detección de imports y
métodos es textual y exacta; no realiza análisis sintáctico del archivo.

## 9. Cómo se generan las rutas

El destino es siempre `routes/web.php`; si no existe, `RouteGenerator` lanza
una excepción. Para las dos direcciones añade rutas GET con esta forma:

```text
/consultas/<modelo-a-kebab>-<modelo-b-kebab>
/consultas/<modelo-b-kebab>-<modelo-a-kebab>
```

Cada ruta referencia el método correspondiente en
`App\Http\Controllers\ConsultasController`. El generador añade ese import si
no encuentra ya el texto exacto. No añade un import para la fachada `Route`,
por lo que depende de que esté disponible como alias en la aplicación Laravel.

La detección de duplicados de rutas es textual. Las rutas se agregan al final
del contenido existente; no se inspeccionan grupos, middleware, nombres de
ruta, controladores invocables ni configuración de API. Esta funcionalidad
heredada permanece independiente de las rutas CRUD API.

## 10. Archivos de la aplicación que puede modificar

| Operación | Archivos objetivo |
|---|---|
| Relaciones / `$fillable` | `app/Models/ModelA.php`, `app/Models/ModelB.php` |
| Consultas heredadas | `app/Http/Controllers/ConsultasController.php` |
| Rutas web heredadas | `routes/web.php` |
| `--crud` | `app/Http/Controllers/Api/ModelAController.php`, `ModelBController.php` |
| `--api` | `routes/lrg_api.php` y una inclusión administrada en `routes/api.php` |
| Esquema estructural | `.lrg/lrg-index.json`, solo al completar el flujo con éxito |

Si se seleccionan varias operaciones, el comando puede modificar los mismos
modelos en más de una operación (`relacion` y `fillable`). Antes de aplicarlas,
las genera sobre copias temporales. Las rutas y ubicaciones no son
configurables por opciones del comando. Los controladores o archivos de rutas
manuales incompatibles se rechazan, no se sobrescriben.

## 11. Ejemplos de uso

Generar todo (aceptando la selección predeterminada `todo`):

```bash
php artisan relation:make User Post 1:N
```

Cuando se solicite la selección, introducir `todo`. Esto conserva las cuatro
operaciones heredadas; no genera CRUD.

Generar relación, fillables, CRUD y rutas API sin preguntas:

```bash
php artisan relation:make Category Product 1:N --crud --api
```

`--crud` sin `--api` genera los controladores, pero no registra rutas. Usar
`--api` sin `--crud` se rechaza. Los modelos y migraciones deben existir; LRG
no crea modelos, tablas ni ejecuta migraciones.

Generar únicamente relaciones y `$fillable`:

```text
relacion, fillable
```

Generar únicamente las consultas:

```text
consultas
```

En este último caso, las relaciones referenciadas deben existir previamente.
Para generar rutas sin métodos de consulta, esos métodos deben existir
previamente en `ConsultasController`.

## 12. Límites y riesgos actuales

Estos puntos describen el comportamiento del código actual, no capacidades
implícitas de Laravel:

1. **Análisis acotado.** `getColumns()` sigue disponible como API legado
   textual; `analyze()` solo admite el subconjunto estático documentado en la
   sección 14. Lógica dinámica, SQL crudo y helpers de esquema desconocidos se
   rechazan.
2. **Convenciones de proyecto.** Se requieren modelos en `app/Models` y bajo
   `App\Models`; controladores API usan el namespace
   `App\Http\Controllers\Api`.
3. **Límites de Eloquent.** Las relaciones Eloquent no admiten claves
   compuestas de forma nativa. Los endpoints CRUD usan las claves y los
   comportamientos configurados en cada modelo; el generador no deriva reglas
   de validación a partir de claves, restricciones o tipos de columna.
4. **CRUD directo.** Las operaciones envían los datos de la solicitud a
   Eloquent y no agregan validación ni manejo de errores de negocio. Las
   restricciones declaradas en la base de datos siguen aplicándose; Laravel y
   la base de datos determinan la respuesta ante registros inexistentes o
   errores de integridad. No se generan transacciones de negocio ni operaciones
   API de asociación N:M.
5. **Seguridad de asignación.** `$fillable` es estructural, no una política de
   autorización. Revisa campos sensibles antes de exponer CRUD. No se genera
   login, middleware, permisos ni reglas particulares.
6. **Generadores heredados.** `QueryGenerator` y `RouteGenerator` mantienen
   su comportamiento de consultas `get()` y rutas GET sin filtros,
   paginación o autorización. La modalidad `--crud` no los invoca.
7. **Conflictos.** Un método, allowlist, controlador, ruta o configuración
   manual incompatible bloquea la operación completa. Los grupos de rutas
   dinámicos y configuraciones Laravel API no reconocibles se rechazan en vez
   de considerarse cargados.
8. **Pruebas.** `php tests/run.php` cubre análisis y generadores;
   `php tests/full_generation_integration.php <autoload>` valida el comando,
   migraciones, rutas y HTTP en un Laravel disponible. Las versiones exactas
   comprobadas se indican al final de este documento.

## 13. Estado de referencia

- Baseline: `v1.1.0`; el comando conserva sus tres argumentos posicionales y
  su selección interactiva heredada.
- La modalidad nueva es `relation:make ModelA ModelB cardinality --crud
  [--api]`.
- Los seis incrementos funcionales de LRG 2.0 están implementados en este
  checkout: análisis, índice, relaciones/fillables, CRUD, rutas API e
  integración transaccional del comando.

## 14. Análisis estructural de migraciones (primer incremento LRG 2.0)

### Entrada y orden

```php
$schema = (new MigrationReader())->analyze($migrationDirectory);
```

`analyze()` acepta la ruta de un archivo PHP, un directorio con migraciones
directas `*.php` o una lista de rutas. Para resolver referencias a tablas y
columnas, la entrada debe incluir el historial relevante completo del esquema;
un archivo aislado que dependa de tablas creadas fuera de esa entrada se
reporta como incompleto. Los archivos se ordenan por nombre de archivo (la
convención temporal de Laravel) y solo se interpreta el cuerpo del método
`up()`. `down()` se ignora. Las migraciones se inspeccionan como texto
tokenizado con `token_get_all()`; no se ejecutan y no se consulta la base de
datos.

El lector admite las llamadas `Schema::create()`, `Schema::table()`,
`Schema::drop()`, `Schema::dropIfExists()` y `Schema::rename()`, incluyendo el
Facade importado con nombre o alias. Los callbacks admitidos son cierres
`function (...) { ... }` con cadenas literales de llamadas `$table->metodo(...)`.
Los argumentos estructurales deben ser literales estáticos: cadenas, números,
booleanos, `null` o arreglos de esos valores.

### Elementos estructurales resultantes

`DatabaseSchema` ofrece:

- `tables`: mapa por nombre exacto de tabla a `SchemaTable`.
- `relations`: lista de `SchemaRelation` derivadas de claves foráneas,
  restricciones únicas y claves pivote.
- `migrationFiles`: rutas de archivos procesadas en orden.
- `table($name)`: consulta una tabla, o devuelve `null`.
- `toArray()`: representación normalizada sin persistirla en disco.

Cada `SchemaTable` contiene `columns`, `indexes` y `foreignKeys` como mapas
indexados por nombre. Los nombres de índice y foreign key sin nombre explícito
se derivan con la convención Laravel no prefijada
`tabla_columnas_tipo`; la configuración de prefijo de conexión no forma parte
de este análisis.

Una `SchemaColumn` expone `name`, `type`, `length`, `precision`, `scale`,
`nullable`, `default`, `hasDefault`, `defaultIsExpression`, `autoIncrement`,
`unsigned` y `enumValues`. `defaultIsExpression` diferencia el valor especial
`CURRENT_TIMESTAMP` producido por `useCurrent()` de una cadena literal igual.
Una `SchemaIndex` expone `name`, `type`, `columns` y `unique`. Una
`SchemaForeignKey` expone nombre, columnas locales, tabla/columnas referidas y
acciones `onDelete`/`onUpdate`.

Las relaciones estructurales usan:

- `1:1` si las columnas locales de una clave foránea tienen una restricción
  primaria o única exacta.
- `1:N` para una clave foránea no única, orientada desde la tabla referida
  (lado uno) hasta la tabla que contiene la clave (lado muchos).
- `N:M` si una tabla tiene exactamente dos claves foráneas a tablas distintas,
  ninguna clave foránea individual es única y se cumple al menos una condición:
  una restricción primaria/única sobre el par de columnas o el nombre pivote
  convencional de Laravel (nombres de tabla singulares, en orden alfabético).

Una relación contiene `cardinality`, `sourceTable`, `sourceColumns`,
`targetTable`, `targetColumns`, `foreignKey`, `pivotTable`, `foreignKeyTable`,
`foreignKeyColumns`, `referencedTable` y `referencedColumns`. Para una relación
`1:N`, `sourceTable` es el lado uno y `targetTable` el lado muchos; los campos
`foreignKeyTable`/`foreignKeyColumns` y `referencedTable`/
`referencedColumns` conservan la dirección real de la clave. Las claves
foráneas de la tabla pivote también se conservan como restricciones
individuales.
Una pivote convencional con PK sustituta (`id`) y sin unicidad del par puede
generar `belongsToMany()`; en ese caso la base de datos no impide insertar
duplicados para una misma asociación.

### Patrones admitidos

- Columnas estáticas con tipos `id`, `increments`, `tinyIncrements`,
  `smallIncrements`, `mediumIncrements`, `bigIncrements`, `foreignId`,
  `foreignUuid`, `foreignUlid`, `string`, `char`, `binary`, tipos enteros con
  signo o sin signo, `float`, `double`, `decimal`, `boolean`, fechas y horas,
  `text`, `mediumText`, `longText`, `json`, `jsonb`, `uuid`, `ulid`, `enum` y
  `set`.
- Longitudes, precisión/escala, nulabilidad, valores predeterminados literales,
  `useCurrent()`, unsigned, autoincremento, índices únicos/primarios/estándar
  y valores de enum.
- `timestamps()`, `timestampsTz()`, `nullableTimestamps()`, `softDeletes()`,
  `softDeletesTz()` y `rememberToken()` (columna nullable `string(100)`).
- `morphs()`, `nullableMorphs()`, `numericMorphs()`, `uuidMorphs()`,
  `ulidMorphs()` y variantes nullable, con sus columnas de tipo/ID e índice
  compuesto. `morphs()` usa el tipo numérico predeterminado de Laravel; si la
  aplicación cambia globalmente ese valor, utiliza el helper explícito
  `uuidMorphs()` o `ulidMorphs()` para que el tipo sea determinable.
- Claves foráneas con `foreignId()->constrained('tabla', 'columna', 'nombre')`
  o `foreign(...)->references(...)->on(...)`; se admiten columnas
  referenciadas compuestas y políticas `onDelete`, `onUpdate`,
  `cascadeOnDelete`, `restrictOnDelete`, `nullOnDelete`, `noActionOnDelete` y
  las variantes `...OnUpdate`.
- Claves primarias y restricciones unique simples o compuestas, con nombre
  opcional.
- Cambios acotados de `Schema::table()`: añadir/cambiar columnas, quitar o
  renombrar columnas, añadir índices/foreign keys y quitar índices/foreign
  keys por nombre o columnas.
- Renombrar o eliminar tablas mediante las llamadas `Schema` indicadas.

Cuando `constrained()` no recibe tabla, se deriva el nombre solo si está
disponible el pluralizador de Laravel y la columna termina en `_id`. El lector
no adivina nombres singulares irregulares fuera del pluralizador.

### Estructuras excluidas y errores

No se evalúan variables, constantes PHP, concatenaciones, llamadas a funciones,
expresiones dinámicas, callbacks arrow, lógica condicional/bucles, helpers que
ocultan operaciones de esquema, SQL crudo (`DB::statement()` o
`DB::unprepared()`), tipos o modificadores de `Blueprint` desconocidos ni
variantes no listadas. Las sentencias de `up()` que no sean llamadas estáticas
soportadas a `Schema` se consideran ambiguas. Las llamadas o archivos sin
`up()` soportado tampoco se usan como sustituto de una migración analizable
cuando el archivo sí contiene llamadas de esquema.

En esos casos se lanza `MigrationAnalysisException` con `migrationFile`,
`line`, `structure` y `unknownData`; la excepción identifica el dato que no
pudo determinarse. No devuelve un esquema parcial con apariencia de completo.
La implementación verifica la existencia de tablas/columnas referidas y la
aridad de las claves foráneas al finalizar el recorrido.

### Pruebas estructurales

La suite autocontenida se ejecuta con:

```bash
php tests/run.php
```

Incluye fixtures para `1:1`, `1:N`, `N:M`, claves compuestas, cambios de
`Schema::table()`, políticas de claves foráneas, datos ambiguos y la API
heredada. No requiere instalar una herramienta de test adicional.

### Índice persistente (incremento 2)

En una aplicación Laravel, `analyze()` mantiene automáticamente
`base_path('.lrg/lrg-index.json')`. Fuera de Laravel no crea archivos por
defecto; se puede proporcionar una ruta explícita como segundo argumento:

```php
$schema = (new MigrationReader())->analyze(
    database_path('migrations'),
    base_path('.lrg/lrg-index.json')
);
```

El documento JSON contiene:

- `format_version`: versión del formato del índice.
- `migrations`: lista ordenada de rutas de origen, huellas SHA-256 y
  operaciones estructurales normalizadas que permiten reutilizar el resultado
  del parser sin almacenar el código PHP.
- `schema.tables`, `schema.relations` y `schema.migration_files`: vista
  normalizada del estado completo después de aplicar las migraciones.
- `index_sha256`: huella de integridad del contenido restante del índice.

Las rutas dentro de la aplicación se guardan relativas a la raíz del proyecto.
El índice no reemplaza las migraciones: en cada lectura se enumeran los
archivos y se calculan sus huellas. Solo se reutilizan operaciones cuando
coinciden ruta y SHA-256; los archivos nuevos o modificados se vuelven a
interpretar. Los archivos eliminados o renombrados dejan de formar parte del
índice. El constructor reaplica las operaciones vigentes en orden para
reconstruir el estado y validar sus dependencias, sin volver a tokenizar las
migraciones sin cambios.

Un índice ausente, dañado o de otra versión se descarta y se reconstruye desde
las migraciones. El nuevo JSON se escribe en un archivo temporal en el mismo
directorio y se instala mediante reemplazo; un error al interpretar o construir
el esquema no reemplaza el índice anterior. La firma de `analyze()` acepta un
tercer booleano opcional `persistIndex`; con `false` permite preparar el
esquema sin escribir el índice. `relation:make` usa esa modalidad durante la
planificación y actualiza el índice solo después de aplicar y verificar los
cambios de modelos, controladores y rutas.

### Contrato recomendado para consumidores posteriores

```text
MigrationReader::analyze(string|array $migrationPaths, ?string $indexPath = null, bool $persistIndex = true): DatabaseSchema
DatabaseSchema::$tables: array<string, SchemaTable>
DatabaseSchema::$relations: list<SchemaRelation>
DatabaseSchema::$migrationFiles: list<string>
DatabaseSchema::toArray(): array{tables: array, relations: list<array>, migration_files: list<string>}
```

Los consumidores pueden usar `DatabaseSchema` o su serialización normalizada.
El analizador no ejecuta CRUD ni migraciones; el generador CRUD consume los
metadatos existentes y no crea controladores para tablas pivote.

## 15. Generación estructural de relaciones y `$fillable` (tercer incremento LRG 2.0)

Cuando se elige `relacion` o `fillable`, `relation:make` analiza el directorio
completo de migraciones con `MigrationReader::analyze()` y utiliza el índice
persistente de la sección anterior. Los argumentos posicionales del comando y
la selección interactiva no cambian.

`RelationshipGenerator::generateFromSchema()` identifica las tablas de ambos
modelos, selecciona una relación de la cardinalidad solicitada y utiliza sus
claves reales. Una FK local única produce `hasOne()`/`belongsTo()`; una FK no
única produce `hasMany()`/`belongsTo()`; una pivote reconocida produce
`belongsToMany()` con tabla, claves pivote, clave local y clave referida
explícitas. Los nombres se calculan con `Illuminate\Support\Str`.

No se adivina una cardinalidad desde los nombres de clase. Una cardinalidad que
no concuerde con el esquema, varias FKs candidatas, una pivote no inequívoca o
claves compuestas incompatibles con Eloquent producen error antes de escribir.
La edición de clases es sintáctica y acotada: preserva métodos no relacionados,
omite una relación ya generada de forma equivalente y reporta conflicto si el
método solicitado ya existe con otro cuerpo.

`FillableGenerator::generateFromTable()` excluye claves primarias,
autoincrementos, timestamps convencionales, `deleted_at` y
`remember_token`. Las FK permanecen entre los candidatos para permitir
asignaciones CRUD cuando la aplicación las autorice. Si no hay `$fillable`, se
genera la lista. Una lista manual estática se conserva únicamente si ya
contiene todos los candidatos; de lo contrario se informa conflicto y no se
amplía la allowlist. Configuración `$guarded` o dinámica tampoco se modifica.

Este filtrado es estructural, no seguridad de autorización: no infiere
permisos ni identifica por sí mismo todos los campos sensibles. Revise las
allowlists generadas en busca de credenciales, roles y otros campos cuyo
control depende de la aplicación.

Las relaciones y fillables se insertan usando el tokenizer de PHP, validando
la clase y su sintaxis. Los modelos deben existir en `app/Models`. En el
comando, todas las escrituras se preparan en un directorio temporal y se
aplican mediante `FileChangeTransaction`; las copias recuperables se mantienen
hasta verificar también el índice. Los generadores heredados de consultas y
rutas conservan su comportamiento y selección; cuando se elige `todo`, el
comando comparte los nombres de relación con el generador de consultas.

La suite estructural se ejecuta con `php tests/run.php`. Las consultas reales
de Eloquent sobre SQLite se verifican por separado:

```bash
php tests/eloquent_integration.php <ruta-a-vendor/autoload.php>
```

La prueba requiere `illuminate/database` y una extensión PDO SQLite disponibles
en el entorno.

## 17. Controladores CRUD estructurales y rutas API

Con `--crud`, `CrudGenerator` recibe la entidad `SchemaTable` normalizada y
genera:

```text
app/Http/Controllers/Api/{Model}Controller.php
```

Cada controlador implementa `index`, `store`, `show`, `update` y `destroy`
con operaciones directas de Eloquent (`all`, `create`, `findOrFail`, `update`
y `delete`). Devuelve JSON, usa 201 para creación, 200 para consulta y
actualización, y 204 para eliminación. No genera validaciones ni transforma
excepciones de Eloquent o la base de datos en respuestas personalizadas.
`findOrFail` conserva el comportamiento Laravel estándar cuando un registro no
existe.

La asignación usa los `$fillable` generados estructuralmente para los modelos;
el generador no inspecciona tipos de columna, claves foráneas ni índices para
crear reglas de entrada. Las restricciones del esquema se mantienen en la
base de datos. No agrega filtros, paginación, autenticación, autorización, ni
`attach()`, `detach()` o `sync()`. Si el controlador ya existe y no es idéntico
al generado por LRG, lo conserva y rechaza la operación.

Con `--api`, `ApiRouteGenerator` registra cada entidad solicitada mediante
`Route::apiResource()`. Los recursos usan snake_case plural (`users`,
`blog_posts`) y publican GET colección, POST colección, GET recurso,
PUT/PATCH recurso y DELETE recurso, sin las acciones web `create`/`edit`.
Las invocaciones sucesivas acumulan recursos administrados previamente y no
duplican rutas.

El generador administra solo el bloque delimitado por marcadores en
`routes/lrg_api.php`; el contenido manual fuera de ese bloque se conserva.
Añade una sola inclusión marcada `require_once` al final de `routes/api.php`,
sin tocar `routes/web.php`. El archivo API debe existir y estar registrado en
Laravel.

Antes de escribir comprueba la carga de `routes/api.php` mediante la
configuración estática `withRouting(api: ...)` de Laravel 11+ o el grupo API
convencional (`middleware('api')`, `prefix('api')`) del `RouteServiceProvider`
de Laravel 10. `withRouting(using: ...)` y otras configuraciones no
verificables se rechazan. Comprueba también el namespace, clase y cinco
métodos públicos de todos los controladores CRUD planificados.

Detecta llamadas estáticas de rutas manuales con URI solapada, nombres
convencionales en uso, importaciones `Route` incompatibles y bloques
administrados ambiguos. Ante una posible colisión o argumentos dinámicos de
ruta cuya URI no se pueda determinar, rechaza la generación. No puede resolver
prefijos de grupos dinámicos, aliases de rutas definidos mediante código
arbitrario ni colisiones de rutas cargadas desde archivos externos; esos
patrones requieren revisión manual.

## 18. Estabilidad, pruebas y estado de los incrementos

Ejecutar las pruebas autocontenidas y de integración:

```bash
php tests/run.php
php tests/full_generation_integration.php <ruta-a-vendor/autoload.php>
```

La integración crea una aplicación temporal con migraciones SQLite, ejecuta
`relation:make` dos veces para las tres cardinalidades, comprueba los métodos
Eloquent, `php artisan route:list`, operaciones CRUD HTTP, actualizaciones
parciales, preservación de rutas web, el conflicto con un controlador manual,
el rechazo de una migración ambigua y una falla controlada después de una
escritura parcial; verifica que los archivos generados y el índice se
recuperen. `tests/run.php` también inyecta un fallo de escritura para probar
el rollback transaccional directamente.

Combinaciones ejecutadas en este entorno:

- PHP 8.3.33 con Laravel Framework 12.73.0: pruebas integrales aprobadas.
- PHP 8.3.33 con Laravel Framework 13.43.0: pruebas integrales aprobadas.
- Los patrones estáticos de registro API para Laravel 10 y 11 se cubren en
  `tests/run.php`, pero no se ejecutó el flujo integral completo sobre esas
  versiones ni se validó PHP 8.1/8.2 en este entorno.

Los requisitos declarados del paquete siguen siendo PHP `^8.1` e Illuminate
10–13; las combinaciones no ejecutadas requieren validación adicional en CI.
