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
- `foreignTable`: tabla indicada para `1:1` o `1:N`; opcional.
- `pivotTable`: tabla indicada para `N:M`; opcional.

El comando crea esta definición incluso si no se seleccionó `relacion`.
`RelationshipGenerator::relationNames()` usa los nombres y la cardinalidad para
proporcionar los nombres que consume `QueryGenerator`, sin escribir archivos.

## 6. Cómo se generan las relaciones Eloquent

`RelationshipGenerator::generate()` comprueba la cardinalidad y delega a la
implementación correspondiente. Primero requiere que ambos archivos existan
bajo `app_path('Models')`, con el nombre literal `<Modelo>.php`.

### Nombres calculados

Las relaciones se nombran de forma simétrica:

| Cardinalidad | En el modelo A | En el modelo B |
|---|---|---|
| `1:1` | nombre de B en camel case con la primera letra en minúscula | nombre de A con la primera letra en minúscula |
| `1:N` | nombre de B en minúscula inicial y pluralizado | nombre de A en minúscula inicial |
| `N:M` | nombre de B en minúscula inicial y pluralizado | nombre de A en minúscula inicial y pluralizado |

La pluralización es una regla local sencilla: una palabra terminada en `y`
cambia a `ies`; una terminada en `s` queda igual; en los demás casos se añade
`s`. No usa el pluralizador de Laravel y no cubre plurales irregulares ni todos
los casos del idioma inglés.

### Tipo de método y tabla de clave foránea

Para `1:1` y `1:N`, el comando pide la tabla que contiene la clave foránea. El
generador compara el texto recibido (después de quitar espacios en los
extremos) con el nombre del modelo B o con una forma pluralizada en snake case
derivada de ese modelo. Si coincide, coloca `hasOne`/`hasMany` en A y
`belongsTo` en B; si no coincide, invierte esa orientación.

Para `N:M`, se requiere el nombre de la tabla pivote y se generan métodos
`belongsToMany` en ambos modelos usando esa tabla.

Los métodos generados llaman a Eloquent sin especificar explícitamente nombres
de claves foráneas, claves locales o claves relacionadas. Los modelos y
relaciones generados presuponen el namespace fijo `App\Models`.

### Escritura en los modelos

Cada método se inserta justo antes de la última llave `}` del archivo del
modelo. El generador comprueba si el texto exacto del método ya está presente
para evitar insertarlo de nuevo. Esta comprobación es textual: no interpreta
PHP ni detecta métodos equivalentes con formato distinto.

## 7. Cómo se localizan las migraciones y se genera `$fillable`

### Localización de la migración

Cuando se elige `fillable`, el comando:

1. Convierte el nombre del modelo a snake case y lo pluraliza mediante el
   helper `str()` de Laravel.
2. Recorre los archivos `*.php` de `database/migrations`.
3. Devuelve el primer archivo cuyo contenido contiene exactamente el patrón
   `Schema::create('<tabla>'`.

Esto asume el nombre convencional pluralizado de la tabla y esa forma literal
de declaración. No consulta la base de datos ni evalúa la migración. Si varias
migraciones coinciden, usa la primera según el orden devuelto por `glob()`.

### Lector anterior y análisis estructural

El método público `getColumns($migrationPath)` conserva el comportamiento
histórico: busca nombres de columnas con una expresión regular y reconoce
textualmente `id()`, `timestamps()` y `softDeletes()`. El comando existente
`relation:make` sigue usando este método para `$fillable`; este incremento no
lo reemplaza ni modifica la firma del comando.

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
no detectarse.

### Escritura de `$fillable`

`FillableGenerator` requiere que el archivo del modelo exista. Filtra `id`,
`created_at`, `updated_at` y `deleted_at`; las columnas restantes se escriben
en `protected $fillable = [...]`.

Busca una propiedad `protected $fillable = [...]` mediante una expresión
regular y la reemplaza si la encuentra. Si no la encuentra, inserta una nueva
propiedad antes de la última llave del archivo. La lista se reconstruye desde
la migración: no se fusiona con el contenido anterior ni se conserva una
configuración manual de columnas.

## 8. Cómo se genera `ConsultasController`

La ruta de destino es fija:

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
ruta, controladores invocables ni configuración de API.

## 10. Archivos de la aplicación que puede modificar

| Selección | Archivos objetivo |
|---|---|
| `relacion` | `app/Models/ModelA.php`, `app/Models/ModelB.php` |
| `fillable` | Los mismos modelos; la información se busca en `database/migrations/*.php`. |
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

Cuando se solicite la selección, introducir `todo`. Para `1:N`, la generación
de la relación también preguntará qué tabla contiene la clave foránea.

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

1. **Edición textual de PHP.** Los generadores insertan o reemplazan texto con
   expresiones regulares y búsqueda de la última llave; no usan un parser de
   PHP.
2. **Convenciones rígidas de proyecto.** Se esperan modelos en
   `app/Models`, clases bajo `App\Models`, un controlador específico y
   `routes/web.php`.
3. **El flujo heredado sigue siendo limitado.** La localización de migraciones
   para `$fillable` se infiere por convención y una cadena concreta;
   `getColumns()` extrae nombres mediante texto. El nuevo `analyze()` cubre un
   subconjunto estructural explícito, no toda la sintaxis de migraciones PHP.
4. **Relaciones sin claves personalizadas.** No se configuran claves foráneas,
   claves locales, claves relacionadas, columnas pivote ni columnas extra de
   pivote.
5. **Consultas y rutas fijas.** Se generan consultas `get()` sin filtros,
   paginación o autorización, y rutas GET sin middleware ni nombres.
6. **Generación parcialmente idempotente.** Hay comprobaciones de duplicados
   textuales, pero diferencias de formato pueden producir duplicados.
7. **Escrituras no transaccionales.** Una excepción después de una escritura no
   restaura automáticamente los archivos anteriores. Los resultados de
   operaciones de lectura/escritura (`file_get_contents`,
   `file_put_contents`, `glob`) no se validan en todos los puntos.
8. **Selección interactiva solamente.** Los elementos no son opciones CLI
   independientes; una automatización debe responder al prompt.
9. **Lector estructural limitado.** `analyze()` solo interpreta el subconjunto
   estático de migraciones que se documenta en la sección 14; el lector
   histórico `getColumns()` sigue siendo textual y el comando `$fillable` aún
   depende de él.
10. **Pruebas acotadas al lector.** `php tests/run.php` cubre el análisis
    estructural y el comportamiento legado del lector y del cálculo de nombres.
    No es una suite de integración completa de Laravel ni cubre todos los
    generadores de archivos.

## 13. Estado de referencia

- Baseline inicial: `v1.1.0`; el análisis estructural de la sección siguiente
  es el primer incremento funcional de LRG 2.0.
- El comando conserva los tres argumentos posicionales y agrega una selección
  interactiva.
- No se cambia la firma del comando ni se conectan todavía metadatos
  estructurales a los generadores existentes.

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
invocar `MigrationReader::analyze()`; el comando `relation:make` y sus
generadores conservan su flujo existente.

### Contrato recomendado para consumidores posteriores

```text
MigrationReader::analyze(string|array $migrationPaths, ?string $indexPath = null): DatabaseSchema
DatabaseSchema::$tables: array<string, SchemaTable>
DatabaseSchema::$relations: list<SchemaRelation>
DatabaseSchema::$migrationFiles: list<string>
DatabaseSchema::toArray(): array{tables: array, relations: list<array>, migration_files: list<string>}
```

Los consumidores posteriores pueden llamar a `analyze()` y usar directamente
`DatabaseSchema` o su serialización normalizada. El índice persistente no está
conectado todavía a los generadores Eloquent actuales. No se implementan CRUD,
rutas API ni cambios funcionales en dichos generadores.
