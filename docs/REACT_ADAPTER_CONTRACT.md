# Adaptador React: arquitectura y contratos 1.0

Este documento define la base del adaptador y sus contratos, independientes del
formato interno del índice LRG. `lrg:frontend:template` está implementado;
`lrg:frontend:connect` y la conexión HTTP están implementados. Los límites de
instalación y conexión se documentan en [la plantilla](../templates/react/README.md).

## Responsabilidades previstas

Los únicos comandos públicos frontend previstos son:

- `php artisan lrg:frontend:template --path=../frontend`: verificar el proyecto
  React/Vite y la compatibilidad de una plantilla distribuida por LRG, preparar
  e instalar sus archivos administrados y registrar el manifiesto. No conecta
  el backend, crea proyectos ni instala dependencias npm.
- `php artisan lrg:frontend:connect --path=../frontend`: verificar una
  instalación compatible y el backend preparado; construir y sincronizar
  metadatos y configuración de conexión. Una ejecución posterior resincroniza
  esos archivos. No instala la plantilla ni genera CRUD o rutas backend.

Ambos deben fallar con diagnósticos accionables si faltan requisitos, existe
ambigüedad o hay conflictos; nunca deben registrar parcialmente el éxito.
El diseño interno previsto, bajo el namespace PSR-4 existente y un directorio
`src/Frontend/`, separa estas responsabilidades sin exigir una clase por
responsabilidad:

1. Resolver y validar la raíz y los destinos del frontend autorizado.
2. Detectar versiones y capacidades y comprobar la compatibilidad de plantilla.
3. Preparar e instalar archivos con verificación de propiedad y rollback.
4. Leer metadatos LRG y comprobar modelos, controladores y rutas existentes.
5. Construir el contrato de entidades desde información verificada.
6. Preparar la configuración HTTP central y, cuando corresponda, proxy Vite.
7. Leer y actualizar el registro de archivos administrados.

Se deben reutilizar `MigrationReader`, el esquema estructural y, tras evaluar
sus límites, `FileChangeTransaction`; ninguno comprueba por sí solo la
seguridad de rutas frontend, la propiedad de archivos ni la compatibilidad.
los comandos son orquestadores y ambos están registrados en el provider.

## Contrato de metadatos `lrg.frontend.metadata` versión `1.0`

El documento es JSON UTF-8, sin secretos, en
`frontend/src/generated/metadata.json`. Las rutas de esta sección son relativas
a la raíz del frontend; `frontend/` es ilustrativo, no un nombre obligatorio.
El objeto raíz contiene exactamente:

| Propiedad | Tipo | Significado |
|---|---|---|
| `contract` | string | Literal `lrg.frontend.metadata`. |
| `version` | string | Literal `1.0`. |
| `api_prefix` | string | Prefijo efectivo de API, `""` o ruta absoluta sin `/` final, por ejemplo `/api` o `/v1/api`. |
| `entities` | array de entidad | Entidades publicables verificadas; puede estar vacío. |
| `relations` | array de relación | Relaciones verificadas entre entidades publicadas; puede estar vacío. |

### Entidad

Cada entidad tiene `id` (string no vacío, único y estable entre
sincronizaciones mientras no cambie su identidad backend), `primary_key`
(nombre de campo), `fields` (array) y `operations` (objeto). Los nombres
de campo son únicos dentro de la entidad y el identificador primario debe
referirse a un campo publicado. No se depende de nombres concretos de modelos
o tablas. Una entidad sin clave primaria simple verificable no se publica:
el CRUD actual usa `findOrFail` y no admite claves compuestas.

Cada campo contiene:

| Propiedad | Tipo | Significado |
|---|---|---|
| `name` | string | Nombre exacto en el JSON de la API. |
| `type` | string | Uno de `string`, `integer`, `number`, `boolean`, `date`, `datetime`, `time`, `json`, `uuid`, `ulid`, `binary`, `unknown`. |
| `access` | string | `editable` o `read_only`; la clave primaria es `read_only` en 1.0. |
| `constraints` | objeto | Solo restricciones verificadas; objeto vacío si no se conocen. |

Las claves opcionales de `constraints` son `nullable` (boolean),
`required_on_create` (boolean), `max_length` (entero no negativo), `precision`
(entero positivo), `scale` (entero no negativo), `unsigned` (boolean) y
`enum` (array no vacío de strings únicos). Una propiedad ausente significa
desconocido, no `false` ni ausencia de restricción. `nullable` no implica que
un campo pueda omitirse. `required_on_create` no se infiere solo por la
migración: debe reflejar una regla de entrada verificada. Las restricciones
estructurales no garantizan validación HTTP ni autorización. `type` describe
el valor lógico; `unknown` prohíbe suponer un control o coerción. No se
exportan valores predeterminados ni expresiones SQL.

`operations` puede contener únicamente `list`, `create`, `show`, `update` y
`delete`, cada una con un endpoint como se describe abajo. Una clave ausente
significa que la operación no se ha verificado o no se ofrece; no autoriza
inferirla de la migración ni de la mera existencia del controlador. `update`
puede anunciar `PUT`, `PATCH` o ambos mediante endpoints separados en un array
no vacío; las demás operaciones tienen un único objeto endpoint. Un endpoint
publicado no garantiza que el usuario actual tenga permiso de ejecutarlo.

### Relaciones y asociación

Cada relación contiene `id` (string único y estable), `cardinality` (`1:1`,
`1:N` o `N:M`), `source` y `target` (IDs de entidades publicadas),
`source_fields` y `target_fields` (arrays no vacíos de nombres de campos,
en orden), y `association_operations` (objeto). Para `1:N`, `source` es
el lado uno; para `1:1` se conserva la dirección estructural de origen y
destino; para `N:M` se indica explícitamente la orientación elegida. Los
campos deben existir en sus respectivas entidades. Los arrays representan las
claves de las entidades, no las columnas intermedias de una pivote.

`association_operations` puede contener `replace`, cuyo valor es un endpoint
con `body_field` adicional: nombre del campo JSON que recibe el array completo
de identificadores destino. Se publica únicamente si el endpoint y su
semántica han sido verificados; un objeto vacío no implica que exista una
operación de asociación. `replace` sustituye todas las asociaciones; un array
vacío las elimina. El CRUD existente admite `related_ids` para asignación N:M
en su lado propietario, pero una relación estructural por sí sola no acredita
que exista una ruta de asociación ni asignación desde el lado inverso.

### Endpoints

Un endpoint contiene `method`, `path` y `parameters`:

- `method`: `GET`, `POST`, `PUT`, `PATCH` o `DELETE`.
- `path`: ruta relativa al prefijo API, comenzando con `/`, sin origen,
  query ni fragmento. Los parámetros se escriben como `{nombre}` en segmentos
  completos; no se incluyen valores concretos.
- `parameters`: array de objetos `{ "name": "...", "source": "entity_id" }`.
  Cada placeholder aparece exactamente una vez en el array y viceversa.
  `entity_id` es el identificador primario de la entidad propietaria de la
  operación o, para `replace`, de `source`. Deben codificarse los valores
  como segmentos de URL; nunca se interpreta el valor como una ruta.

El cliente compone la base HTTP centralizada, `api_prefix` y `path` sin
duplicar separadores ni el prefijo. Debe respetar el path base de `APP_URL`,
si existe. En desarrollo, el proxy y la forma de componer URLs deben preservar
ese path base; no se puede asumir que `APP_URL` sea solo un origen.

Ejemplo ilustrativo, no una declaración de rutas existentes:

```json
{
  "contract": "lrg.frontend.metadata",
  "version": "1.0",
  "api_prefix": "/api",
  "entities": [
    {
      "id": "articles",
      "primary_key": "id",
      "fields": [
        {"name": "id", "type": "integer", "access": "read_only", "constraints": {}},
        {"name": "title", "type": "string", "access": "editable", "constraints": {"max_length": 100}}
      ],
      "operations": {
        "list": {"method": "GET", "path": "/articles", "parameters": []},
        "update": [
          {"method": "PATCH", "path": "/articles/{article}", "parameters": [{"name": "article", "source": "entity_id"}]}
        ]
      }
    },
    {
      "id": "labels",
      "primary_key": "id",
      "fields": [
        {"name": "id", "type": "integer", "access": "read_only", "constraints": {}}
      ],
      "operations": {}
    }
  ],
  "relations": [
    {
      "id": "articles.labels",
      "cardinality": "N:M",
      "source": "articles",
      "target": "labels",
      "source_fields": ["id"],
      "target_fields": ["id"],
      "association_operations": {
        "replace": {
          "method": "PUT",
          "path": "/articles/{article}/labels",
          "parameters": [{"name": "article", "source": "entity_id"}],
          "body_field": "related_ids"
        }
      }
    }
  ]
}
```

Los objetos de 1.0 son cerrados: se rechazan propiedades y valores no
definidos, IDs o nombres duplicados, referencias inválidas y endpoints
incoherentes. Los campos obligatorios están indicados explícitamente arriba;
las propiedades de `constraints` y las operaciones son opcionales. Todos los
strings de nombres e IDs deben ser no vacíos. Los consumidores deben rechazar
identificadores de contrato y versiones no soportados, sin interpretar
silenciosamente una versión desconocida como 1.0. Un cambio incompatible
requiere otra versión de contrato y una negociación explícita con la plantilla.

## Contrato de metadatos `lrg.frontend.metadata` versión `2.0`

La versión 2.0 es incompatible con 1.0: añade propiedades a objetos cerrados.
La plantilla distribuida valida ambas versiones; una versión desconocida se
rechaza. El manifiesto de instalación actual anuncia únicamente 1.0: el
conector coteja los archivos con la plantilla distribuida y valida el JSON con
el validador instalado antes de publicar `frontend/src/generated/lrg-schema.json`.

El servicio interno `Frontend\MetadataGenerator` requiere una aplicación
Laravel inicializada, sus modelos cargables y el índice estructural ya vigente:

```php
$generator = new \Franciscovillaquiran\LaravelRelationGenerator\Frontend\MetadataGenerator();
$metadata = $generator->build(base_path()); // array, con objetos para mapas JSON
$json = $generator->json(base_path());      // JSON UTF-8 determinista y salto de línea final
```

Ambos métodos consultan las rutas reales mediante `route:list --json` en un
proceso nuevo y verifican dos veces la vigencia e integridad del índice, sin
actualizarlo. Ejecutar en un proceso Laravel nuevo después de cambiar modelos
evita usar clases PHP previamente cargadas. Fallan explícitamente cuando el
índice, los archivos, las rutas o sus capacidades no coinciden. No escriben
archivos; el conector prepara, valida y publica el JSON
atómicamente, con la política de propiedad y rollback correspondiente.

El objeto raíz contiene `contract: "lrg.frontend.metadata"`, `version: "2.0"`,
`api_prefix`, `entities` y `relations`. Se mantienen la forma de los endpoints
de 1.0 (`method`, `path` relativo al prefijo y `parameters`) y los nombres
`list`, `create`, `show`, `update` y `delete`; `update` es un array de endpoints.
Una operación ausente no está disponible. Se rechazan rutas con dominios o
parámetros que el contrato no puede expresar.

Cada entidad añade a los campos de 1.0:

- `label`: etiqueta derivada del identificador técnico, no una traducción.
- `table`: nombre de tabla verificado en el modelo y el índice.
- `display_field`: `null` mientras no haya una convención fiable registrada.
- `response_convention`: mapa de operaciones presentes a `json` o
  `no_content`. No garantiza la forma de la respuesta JSON ni autorización.

Cada campo conserva `name`, `type`, `access` y `constraints`. Además de las
restricciones estructurales 1.0, `constraints.unique: true` indica un índice
único de esa columna y `constraints.database_required` indica únicamente que
la columna no es nullable, no tiene default y no es autoincremental. No es una
regla de validación HTTP. El campo opcional `default` solo contiene literales
numéricos, booleanos o `null`; se omiten expresiones y strings para evitar
exportar posibles secretos. No se infiere `required_on_create`: los CRUD actuales
no generan reglas de validación.

Ejemplo real, abreviado a una entidad del fixture de integración; las
operaciones omitidas aquí sí se incluyen cuando están registradas:

```json
{
  "id": "widgets",
  "label": "Widgets",
  "table": "widgets",
  "primary_key": "id",
  "display_field": null,
  "fields": [
    {"name": "enabled", "type": "boolean", "access": "editable",
     "constraints": {"nullable": false, "unsigned": false, "database_required": false},
     "default": true},
    {"name": "id", "type": "integer", "access": "read_only",
     "constraints": {"nullable": false, "unsigned": true, "database_required": false, "unique": true}},
    {"name": "title", "type": "string", "access": "editable",
     "constraints": {"nullable": true, "unsigned": false, "database_required": false,
                     "max_length": 120, "unique": true}}
  ],
  "operations": {
    "list": {"method": "GET", "path": "/widgets", "parameters": []}
  },
  "response_convention": {"list": "json"}
}
```

Cada relación contiene los campos 1.0 más `name`, `inverse_name`, `foreign_key`
(`null` o un objeto con `table`, `columns`, `referenced_table` y
`referenced_columns`), `pivot_table` (`null` o nombre de tabla) y
`pivot_foreign_keys` (`null` o un objeto `source`/`target`, cada uno con
`columns` y `referenced_columns`). Para 1:N,
`source` es la entidad referenciada y `target` contiene la FK; en 1:1 se usa
la misma dirección. En N:M el lado propietario de la operación es `source`,
cuando existe; en caso contrario se usa el orden estructural. `source_fields`
y `target_fields` son columnas de las entidades, no de la pivote.

En N:M, `association_operations.replace` existe solo si se verifica la ruta
`PUT` de sincronización, con `body_field: "related_ids"`. Cuando el controlador
LRG realmente acepta ese campo en CRUD, `association_operations.create` y
`update` contienen `endpoints` (array), `body_field` y
`semantics: "replace"`. Un objeto vacío significa que no se verificó ninguna
operación de asociación; no se inventan rutas attach/detach.

### Fuentes, comprobaciones y límites

Se reutilizan el índice y `MigrationReader`, los modelos Eloquent (`getTable`,
clave y `$fillable`), los patrones originales de `RelationshipGenerator` y
`CrudGenerator`, y `RegisteredApiRouteInspector`. Los métodos de relación se
comparan mediante el editor basado en tokens, sin ejecutarlos. Se admiten
controladores LRG sin modificaciones y relaciones generadas reconocibles;
controladores manuales o modificados se rechazan porque no acreditan qué datos
aceptan. Se requieren claves primarias simples concordantes y pivotes simples
con dos FK a las claves primarias, sin columnas de negocio. Si faltan los dos
métodos de relación, no se publica la relación; si falta solo uno o es
incompatible, se informa el error.

No se publican campos ocultos por el modelo o reconocidos por nombres como
contraseñas, tokens, secretos y claves. Esta exclusión conservadora no detecta
todos los datos sensibles ni constituye autorización: revisar el backend y
proteger sus endpoints sigue siendo responsabilidad de la aplicación. Si una
columna obligatoria para insertar no puede incluirse en el formulario, el
servicio rechaza la entidad en lugar de anunciar una creación inutilizable.
Las restricciones de base de datos no implican validación HTTP. No se infieren
etiquetas o campos de representación a partir de nombres de columnas.

## Manifiesto de instalación `lrg.frontend.installation` versión `1.0`

El registro previsto es `frontend/.lrg/frontend-manifest.json`, JSON UTF-8:

```json
{
  "contract": "lrg.frontend.installation",
  "version": "1.0",
  "template": {
    "id": "lrg-react",
    "version": "1.0.0",
    "metadata_contract": {"id": "lrg.frontend.metadata", "version": "1.0"},
    "compatibility": {
      "php": ">=8.1",
      "laravel": ">=10 <14",
      "react": ">=18 <20",
      "vite": ">=5 <7"
    }
  },
  "managed_files": [
    {"path": "src/generated/metadata.json", "sha256": "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef", "role": "metadata"}
  ]
}
```

Los valores de versión y rangos son **solo un ejemplo**, no una afirmación de
compatibilidad ni una plantilla implementada. Las propiedades mostradas son
obligatorias y no se admiten otras en 1.0. `template.id` identifica la plantilla;
`template.version` es su versión exacta; `metadata_contract` identifica el
contrato soportado. `compatibility` contiene rangos Composer para `php` y
`laravel` y rangos npm para `react` y `vite`. Deben corresponder a capacidades
realmente probadas de la plantilla distribuida, no a supuestos de este ejemplo.
Se comparan contra las versiones efectivamente instaladas y las capacidades
necesarias; un rango por sí solo no demuestra compatibilidad.

Cada entrada de `managed_files` tiene una ruta relativa normalizada con `/`,
un SHA-256 hexadecimal minúsculo de los bytes instalados y `role`: `template`,
`metadata` o `connection`. Las rutas son únicas. La lista puede estar vacía
antes de conectar. El manifiesto no se enumera a sí mismo: se administra como
registro reservado y se actualiza en la misma transacción que los archivos.
El hash del ejemplo no corresponde a un archivo real.

El manifiesto es información local, no una prueba criptográfica de origen.
Antes de confiar en él, el instalador debe validar su formato, identidad y
versión contra una plantilla conocida distribuida por LRG y cotejar cada ruta
y rol con los destinos permitidos por esa plantilla. Debe comparar los hashes
registrados con los bytes actuales y rechazar divergencias; para archivos de
plantilla debe contrastar también el hash con el contenido de la versión
distribuida. El hash local detecta cambios accidentales, no una alteración
coordinada del archivo y del manifiesto: para detectar esta última se requiere
un registro previo confiable o verificación equivalente. Si no se puede
verificar la propiedad, se detiene la operación. Un manifiesto inválido o una
ruta no reconocida no autoriza sobrescritura. En la primera
instalación, un destino ya existente sin propiedad comprobada es un conflicto,
aunque esté dentro de `src/generated/`. Los archivos personalizados nunca se
registran como administrados. El manifiesto no almacena credenciales, rutas
absolutas, secretos ni contenido privado del backend.

## Archivos, conexión y seguridad obligatoria

- Los componentes visuales instalados pertenecen a la plantilla; LRG solo puede
  actualizar archivos explícitamente administrados y sin cambios locales.
  `frontend/src/generated/` contiene los metadatos sincronizables, no convierte
  automáticamente otros archivos de ese directorio en propiedad de LRG.
- La configuración de conexión se centraliza en `.lrg/vite-proxy.mjs` y
  `src/generated/lrg-http.js`. El registro separado
  `.lrg/frontend-connection.json` identifica los archivos y sus hashes, sin
  alterar el manifiesto cerrado de instalación 1.0. La dirección del backend
  proviene de `config('app.url')`; se rechazan URLs inválidas o con credenciales,
  query o fragmento. El navegador utiliza rutas relativas y no recibe APP_URL.
- Laravel/PHP y React/npm mantienen dependencias independientes; LRG no instala
  ni comparte dependencias npm y debe seguir funcionando sin React o Node.
- Validar `--path`, normalizar y resolver la raíz existente, comprobar que es
  el proyecto autorizado y que los destinos permanecen dentro de ella. Rechazar
  rutas absolutas en el manifiesto, `..`, segmentos vacíos, separadores
  alternativos, enlaces simbólicos, junctions o reparse points que puedan
  escapar o alterar el destino. Evitar también escrituras a través de hard
  links hacia archivos externos. Revalidar antes de escribir para reducir
  carreras; no seguir enlaces durante la escritura ni la restauración. Si la
  plataforma no permite comprobar estas condiciones de forma segura, fallar.
  Un hash no reemplaza estas comprobaciones. No se escribe fuera del frontend
  autorizado.
- Preparar y validar el conjunto completo antes de aplicarlo, detectar
  colisiones, archivos desconocidos o personalizados y cambios concurrentes;
  fallar sin modificaciones silenciosas. Repetir una operación sin cambios no
  duplica archivos, registros ni configuración.
- Mantener copias recuperables hasta verificar todas las escrituras y el
  manifiesto; ante fallos de instalación o sincronización restaurar la versión
  anterior y reportar cualquier fallo de recuperación. Una transacción entre
  varios archivos no garantiza atomicidad ante corte de energía o terminación
  abrupta: la conexión actual no dispone de diario recuperable para ese caso;
  requiere inspección manual de los archivos y respaldos.
- El proxy Vite y cualquier edición de configuración deben seguir estas mismas
  reglas; no se autoriza modificar un `vite.config` personalizado solo por
  estar presente. Si no puede integrarse sin ambigüedad, informar el conflicto.

## Evidencia actual y límites para incrementos posteriores

El provider registra `relation:make`, `lrg:frontend:template` y
`lrg:frontend:connect`. `MigrationReader::analyze()`
tokeniza migraciones y construye `DatabaseSchema`; el índice
`.lrg/lrg-index.json` cachea operaciones y esquema por huellas, pero no es el
contrato público frontend. Los generadores existentes producen relaciones,
fillable, controladores CRUD y rutas API bajo las condiciones descritas en
[la guía del proyecto](PROJECT_GUIDE.md). La generación CRUD no implementa
validación HTTP ni autorización, y las rutas deben estar registradas en
Laravel. El índice no demuestra que exista un endpoint operativo ni qué campos
son seguros para exponer; la selección de campos requiere verificación
explícita y debe excluir datos sensibles. No publicar los defaults,
credenciales, tokens, rutas locales ni otros metadatos sensibles.

El paquete declara PHP `^8.1` e `illuminate/support` `^10.0 || ^11.0 ||
^12.0 || ^13.0`, sujeto a las restricciones efectivas de cada instalación.
Es una restricción de Composer, no prueba de ejecución en todas las
combinaciones. La versión instalada debe obtenerse del proyecto Laravel
anfitrión, y las versiones efectivas de React y Vite del frontend elegido; las
declaraciones de `package.json` por sí solas no prueban qué está instalado.
Si no es posible verificar versiones o capacidades, se informa y se detiene
la operación; no se cambia el manifiesto ni se instalan dependencias.

### Condiciones para integraciones posteriores

Respetar los
contratos, propiedad y validaciones anteriores; probar versiones reales y
capacidades sin fijar una versión arbitraria, probar conflictos, enlaces,
idempotencia y rollback, y preservar el flujo `relation:make` y el uso de LRG
sin frontend. Si una capacidad o dato no puede verificarse, no inventarlo ni
publicarlo como disponible. La conexión revierte fallos ordinarios, pero no
tiene un diario de recuperación ante terminación abrupta.
