# Diseño y funcionamiento de Laravel Relation Generator

Este documento parte del baseline `v1.1.0` y registra el primer incremento
estructural de LRG 2.0. Describe el comportamiento actual del código y separa
las funciones implementadas de los límites y propuestas de evolución.

## 1. Qué es el proyecto

Laravel Relation Generator es una biblioteca PHP para Laravel que registra un
comando Artisan:

```text
relation:make
```

El comando ayuda a conectar dos modelos Eloquent. Según la selección del
usuario, puede:

1. Añadir métodos de relación a los modelos.
2. Crear o actualizar las propiedades `$fillable` leyendo las migraciones.
3. Añadir métodos de consulta a `ConsultasController`.
4. Añadir rutas GET a `routes/web.php`.
5. Registrar controladores CRUD existentes en rutas API usando un generador
   independiente.

No crea modelos ni migraciones, no ejecuta migraciones y no configura
controladores o rutas que no estén dentro de estas operaciones. Modifica los
archivos existentes directamente en la aplicación Laravel que ejecuta el
comando.

## 2. Estructura del paquete

```text
src/
├── Console/Commands/RelationMakeCommand.php
├── Generators/
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
| `Console/Commands/RelationMakeCommand.php` | Lee argumentos y selección, pide los datos que necesita y coordina las operaciones. |
| `Relations/RelationDefinition.php` | Transporta los modelos, la cardinalidad y los nombres opcionales de tabla. |
| `Generators/RelationshipGenerator.php` | Calcula los nombres de las relaciones y añade sus métodos a los modelos. |
| `Generators/MigrationReader.php` | Conserva `getColumns()` para el flujo previo y ofrece `analyze()` para obtener el esquema normalizado. |
| `Generators/StructuralSchema/MigrationParser.php` | Tokeniza e interpreta el subconjunto estático admitido de migraciones. |
| `Generators/StructuralSchema/SchemaBuilder.php` | Aplica cambios de esquema en orden y deriva las relaciones estructurales. |
| `Generators/FillableGenerator.php` | Construye y escribe `$fillable` en un modelo. |
| `Generators/QueryGenerator.php` | Crea o actualiza `ConsultasController` con métodos que cargan relaciones. |
| `Generators/RouteGenerator.php` | Añade rutas GET que apuntan a los métodos del controlador. |
| `Generators/ApiRouteGenerator.php` | Mantiene los recursos CRUD en `routes/lrg_api.php` y registra una inclusión en `routes/api.php`, separado por ahora de `relation:make`. |

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

La firma actual conserva tres argumentos posicionales obligatorios:

```bash
php artisan relation:make ModelA ModelB 1:N
```

Los valores admitidos para `cardinality` son exactamente `1:1`, `1:N` y `N:M`.
La validación de cardinalidad ocurre antes de preguntar qué generar. Si no es
válida, el comando muestra un error y devuelve `Command::FAILURE`.

Después solicita una selección interactiva. La selección predeterminada es
`todo`, así que aceptar la respuesta predeterminada mantiene la generación de
los cuatro elementos. Los nombres reconocidos, en minúsculas, son:

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

Los nombres aceptados son los literales del cuadro: por ejemplo, el parser
acepta `relacion`, no una versión acentuada como `relación`. La selección se
obtiene mediante `ask()`, no mediante una opción o argumento de consola; por
tanto, la interfaz actual es interactiva.

### Orden de ejecución

Con una selección válida, el comando opera en este orden:

1. Si se seleccionó `relacion`, solicita la tabla que contiene la clave
   foránea para `1:1` o `1:N`, o la tabla pivote para `N:M`.
2. Si se seleccionó `fillable`, localiza las migraciones de ambos modelos.
   Si no encuentra alguna, termina con error antes de comenzar a generar.
3. Si se seleccionó `relacion`, modifica los dos modelos.
4. Si se seleccionó `fillable`, lee las migraciones y modifica los dos modelos.
5. Calcula los nombres de relación.
6. Si se seleccionó `consultas`, modifica o crea el controlador.
7. Si se seleccionó `rutas`, modifica el archivo de rutas.
8. Muestra mensajes de éxito y devuelve `Command::SUCCESS`.

Solo se solicita información de tablas cuando se genera la relación. Del mismo
modo, localizar migraciones solo es necesario para `fillable`; elegir solo
`relacion`, `consultas` o `rutas` no inicia esa búsqueda.

Hay dos avisos de dependencia, no bloqueantes:

- `consultas` sin `relacion` avisa que las relaciones deben existir ya en los
  modelos.
- `rutas` sin `consultas` avisa que los métodos del controlador deben existir
  ya.

El comando no valida que esos métodos o relaciones realmente existan. Tampoco
ejecuta todas las validaciones sobre todos los archivos antes de la primera
escritura: si una operación posterior falla, las modificaciones anteriores
pueden quedar aplicadas. No hay transacción ni reversión automática.

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

| Selección | Archivos objetivo |
|---|---|
| `relacion` | `app/Models/ModelA.php`, `app/Models/ModelB.php` |
| `fillable` | Los mismos modelos; la información se obtiene de `MigrationReader::analyze()`. |
| `consultas` | `app/Http/Controllers/ConsultasController.php` |
| `rutas` | `routes/web.php` |

Si se seleccionan varias operaciones, el comando puede modificar los mismos
modelos en más de una operación (`relacion` y `fillable`). Las rutas y
ubicaciones no son configurables por opciones del comando.

## 11. Ejemplos de uso

Generar todo (aceptando la selección predeterminada `todo`):

```bash
php artisan relation:make User Post 1:N
```

Cuando se solicite la selección, introducir `todo`. La dirección de la clave
foránea se determina desde las migraciones, sin pedir el nombre de la tabla.

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

1. **Edición acotada de modelos.** Las relaciones y `$fillable` usan el
   tokenizer de PHP y rechazan estructuras que no pueden editar con seguridad.
   Los generadores heredados de consultas y rutas conservan sus ediciones
   textuales.
2. **Convenciones rígidas de proyecto.** Se esperan modelos en
   `app/Models`, clases bajo `App\Models`, un controlador específico y
   `routes/web.php`.
3. **Análisis estructural acotado.** `getColumns()` sigue disponible como API
   legado textual; `analyze()` cubre solo el subconjunto estático descrito en
   la sección 14.
4. **Límites de Eloquent.** No se generan relaciones con claves compuestas.
   La configuración de fillable es estructural, no una política de acceso ni
   de autorización.
5. **Consultas y rutas fijas.** Se generan consultas `get()` sin filtros,
   paginación o autorización, y rutas GET sin middleware ni nombres.
6. **Generadores heredados.** Consultas y rutas conservan sus comprobaciones
   textuales e idempotencia limitada.
7. **Escrituras por archivo.** Las ediciones individuales usan archivo
   temporal y reemplazo; un fallo del segundo modelo no revierte un primer
   archivo ya actualizado.
8. **Selección interactiva solamente.** Los elementos no son opciones CLI
   independientes; una automatización debe responder al prompt.
9. **Modelo cargable para tablas personalizadas.** Si un modelo configura una
   tabla no convencional, debe poder cargarse para que el comando use su
   `getTable()`; de lo contrario se usa la convención Laravel.
10. **Pruebas.** `php tests/run.php` cubre parser, índice y generadores; la
    prueba de consultas Eloquent/SQLite se ejecuta con el autoloader de
    `illuminate/database`.

## 13. Estado de referencia

- Baseline inicial: `v1.1.0`; el análisis estructural de la sección siguiente
  es el primer incremento funcional de LRG 2.0.
- El comando conserva los tres argumentos posicionales y agrega una selección
  interactiva.
- No se cambia la firma del comando ni la selección interactiva. Las
  relaciones y `$fillable` ya consumen metadatos estructurales.

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
- `N:M` si una tabla tiene exactamente dos claves foráneas a tablas distintas
  y una clave primaria/única sobre la combinación de columnas foráneas.

Una relación contiene `cardinality`, `sourceTable`, `sourceColumns`,
`targetTable`, `targetColumns`, `foreignKey`, `pivotTable`, `foreignKeyTable`,
`foreignKeyColumns`, `referencedTable` y `referencedColumns`. Para una relación
`1:N`, `sourceTable` es el lado uno y `targetTable` el lado muchos; los campos
`foreignKeyTable`/`foreignKeyColumns` y `referencedTable`/
`referencedColumns` conservan la dirección real de la clave. Las claves
foráneas de la tabla pivote también se conservan como restricciones
individuales.

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
- `timestamps()`, `timestampsTz()`, `nullableTimestamps()`, `softDeletes()` y
  `softDeletesTz()`.
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

### Pruebas y uso en el siguiente incremento

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
el esquema no reemplaza el índice anterior. El índice solo se actualiza al
invocar `MigrationReader::analyze()`; el comando `relation:make` lo actualiza
cuando genera relaciones o `$fillable`.

### Contrato recomendado para consumidores posteriores

```text
MigrationReader::analyze(string|array $migrationPaths, ?string $indexPath = null): DatabaseSchema
DatabaseSchema::$tables: array<string, SchemaTable>
DatabaseSchema::$relations: list<SchemaRelation>
DatabaseSchema::$migrationFiles: list<string>
DatabaseSchema::toArray(): array{tables: array, relations: list<array>, migration_files: list<string>}
```

Los consumidores posteriores pueden llamar a `analyze()` y usar directamente
`DatabaseSchema` o su serialización normalizada. El análisis no ejecuta CRUD
ni operaciones sobre tablas pivote; el generador de rutas API documentado en
la sección 17 registra únicamente controladores CRUD preexistentes.

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
la clase y su sintaxis. Los modelos deben existir en `app/Models`. Los cambios
son atómicos por archivo, pero no hay transacción conjunta entre ambos modelos.
Los generadores heredados de consultas y rutas, su selección y su flujo se
mantienen; cuando la relación se genera, el comando comparte sus nombres con
el generador de consultas.

La suite estructural se ejecuta con `php tests/run.php`. Las consultas reales
de Eloquent sobre SQLite se verifican por separado:

```bash
php tests/eloquent_integration.php <ruta-a-vendor/autoload.php>
```

La prueba requiere `illuminate/database` y una extensión PDO SQLite disponibles
en el entorno.

## 17. Generación de rutas CRUD API (quinto incremento LRG 2.0)

`ApiRouteGenerator` es intencionalmente independiente del comando
`relation:make` en este incremento. Se usa después de generar los controladores
CRUD:

```php
(new ApiRouteGenerator())->generate(base_path(), [
    App\Models\User::class,
    App\Models\BlogPost::class,
]);
```

Para cada modelo calcula un recurso Laravel en snake_case plural (`users`,
`blog_posts`) y registra `Route::apiResource()` hacia
`App\Http\Controllers\Api\{Model}Controller`. Esto expone GET colección,
POST colección, GET recurso, PUT/PATCH recurso y DELETE recurso; Laravel
resuelve los métodos `index`, `store`, `show`, `update` y `destroy` y no crea
las acciones web `create`/`edit`.

El generador administra solo el bloque delimitado por marcadores en
`routes/lrg_api.php`; el contenido manual fuera de ese bloque se conserva.
Añade una sola inclusión marcada `require_once` al final de `routes/api.php`,
sin tocar `routes/web.php`. Las escrituras son atómicas individualmente.
Repetir la misma generación conserva el archivo sin añadir declaraciones.

Antes de escribir comprueba la existencia de cada controlador en
`app/Http/Controllers/Api`, su namespace/clase y que las cinco acciones
necesarias sean métodos públicos con PHP válido. Confirma la carga de
`routes/api.php` mediante la configuración estática `withRouting(api: ...)`
de Laravel 11+ o el grupo API convencional (`middleware('api')`,
`prefix('api')`) del `RouteServiceProvider` de Laravel 10. Si el archivo API
no existe o no puede verificarse su registro, falla con el requisito pendiente
y no informa éxito.

Detecta llamadas estáticas de rutas manuales con URI solapada, nombres
convencionales en uso, importaciones `Route` incompatibles y bloques
administrados ambiguos. Ante una posible colisión o argumentos dinámicos de
ruta cuya URI no se pueda determinar, rechaza la generación. No puede resolver
prefijos de grupos dinámicos, aliases de rutas definidos mediante código
arbitrario ni colisiones de rutas cargadas desde archivos externos; esos
patrones requieren revisión manual. Tampoco crea controladores ni rutas
anidadas/pivote.

En este incremento no se modifica `RelationMakeCommand`: el comando principal
continúa generando únicamente las operaciones anteriores. La integración
automática de controlador y rutas se deja para el incremento siguiente. El
generador puede probarse desde una aplicación que ya tenga los controladores:

```bash
php tests/api_routes_integration.php <ruta-a-vendor/autoload.php>
```

La integración comprueba `route:list` y despacha solicitudes HTTP sobre una
aplicación Laravel temporal. También se ejecutan pruebas autocontenidas de
preservación de `routes/web.php`, rutas manuales, idempotencia, configuración
Laravel 10/11, controladores incompletos y conflictos detectables.
