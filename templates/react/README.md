# Plantilla React LRG: demostración

Esta copia se deriva de `RepasoExamen/frontend`; no modifica ese proyecto.
Conserva su sidebar, marca, paleta, tipografía, tarjetas, tabla, modal y
adaptación responsive. Se sustituyeron las secciones y campos específicos por
metadatos y una fuente intercambiable. Se añadieron estilos mínimos para
textarea, errores y asociaciones. Las fuentes Google son las mismas que en la
plantilla original y necesitan acceso externo o el navegador usará sans-serif.

Esta carpeta es un proyecto de prueba autocontenido, no un instalador. No
configura ni contacta Laravel. La fuente predeterminada es memoria, indica
«Demostración sin backend» y pierde los cambios al recargar.

```bash
npm ci
npm run dev
npm test
npm run lint
npm run build
```

El proyecto original usa React y React DOM `^19.1.0`, Vite `^7.0.0` y el plugin
React `^5.0.0`. Se añadió ESLint exclusivamente como herramienta de desarrollo
para poder ejecutar el lint solicitado; no es una dependencia de ejecución de
la interfaz. Se comprobó Node 22.23.2, React/React DOM 19.3.1, Vite 7.3.8 y
ESLint 10.15.0. No se afirman otras combinaciones compatibles. El lock de
esta carpeta corresponde al proyecto de prueba, no debe imponerse a un proyecto
destino. El lint configura reglas básicas de JavaScript y sintaxis JSX; no
incluye las reglas de hooks o accesibilidad de plugins.

## Entrada y responsabilidades

`App` recibe `metadata`, `presentation` y `source` como props. Sin props carga
la demostración. `metadata` admite
[`lrg.frontend.metadata` 1.0 y 2.0](../../docs/REACT_ADAPTER_CONTRACT.md).
Con metadatos externos y sin fuente, muestra «Sin conexión» y no reutiliza los
datos de demostración. La validación rechaza versiones desconocidas,
propiedades adicionales y referencias estructurales inválidas. Las
operaciones que no figuran en el contrato no se ofrecen. En 2.0 se usa `label` y, para identificar registros, `display_field`
cuando existe o la clave primaria cuando es `null`. Los defaults literales se
aplican al formulario; `database_required` refleja una restricción de estructura,
no una garantía de validación HTTP.

`presentation` es configuración **separada**, opcional y local a la plantilla,
indexada por ID de entidad. Cada entrada puede tener `label` y `fields`, este
último indexado por nombre técnico de campo, con `label` y `widget` opcionales.
Para un campo de tipo `string`, `widget: "text"` muestra un textarea y
`widget: "email"` un input email. Los demás tipos se derivan de los tipos
existentes en el contrato: `number` se presenta como decimal; `enum` como
select y las asociaciones N:M verificadas como multiselect. Los nombres
técnicos se preservan en los datos enviados. `time`, `json`, `binary` y
`unknown` no se editan automáticamente. No se inventan reglas de validación.

La representación de FKs simples 1:1 y 1:N utiliza las columnas indicadas en
la relación y la clave primaria de la entidad seleccionable. Si hay
ambigüedad, claves compuestas o no se dispone de una lista verificable, no se
ofrece un campo numérico libre ni se supone su valor. Para asociaciones solo
se ofrece edición desde `source` si el contrato contiene `replace`, la fuente puede leer
las asociaciones actuales y la lista de destinos está disponible. De otro
modo se muestran en solo lectura si se conocen, o se indica que no se pueden
leer. En una asociación 1:1 el selector admite como máximo un destino. En 2.0,
el formulario CRUD ofrece asociaciones N:M solo cuando el contrato declara
explícitamente `create` o `update`, y envía el array únicamente si el usuario
modifica la selección. Al editar, exige una lectura completa de asociaciones
y que todos los IDs actuales estén en la lista de destinos; si no, no permite
reemplazarlas. Una lectura fallida se informa y no produce un array vacío.
En 1.0 nunca se envía un array al CRUD para deducir una asociación.

`source` es la frontera de datos utilizada por la demostración y por el cliente
HTTP que publica el comando de conexión:

| Método asíncrono | Resultado |
|---|---|
| `list(entity, endpoint)` | Array normalizado de registros. |
| `show(entity, id, endpoint)` | Un registro. |
| `create(entity, payload, endpoint)` | Resultado de creación. |
| `update(entity, id, payload, endpoints)` | Resultado de actualización; `endpoints` es el array declarado. |
| `delete(entity, id, endpoint)` | Resultado ignorado. |
| `getLinks(relation, id)` | Opcional; array completo de IDs actualmente asociados. |
| `replaceLinks(relation, id, ids, endpoint)` | Opcional; reemplaza la lista completa. |

`source.mode` identifica el modo al usuario. Los métodos solo se invocan para
operaciones declaradas. El cliente HTTP normaliza las respuestas soportadas
en esta frontera y rechaza promesas con `{ message, status, fields }`
para errores 422 por campo, 409, 404, servidor o conexión. No hay un supuesto
único de JSON en los componentes. `getLinks` requiere una lectura completa
verificable proporcionada por la fuente: el contrato **no** define un
endpoint de lectura de asociaciones. No se debe inferir esta capacidad de
`replace`. El cliente prioriza PATCH cuando está disponible y usa rutas
relativas; el proxy compone el origen de `APP_URL` y el prefijo verificado.

El contrato no permite marcar campos sensibles. Es obligatorio que el productor
solo publique campos autorizados y no sensibles. La plantilla además omite
nombres que contienen `password`, `passwd`, `secret`, `token` o `credential`,
pero ese filtro no identifica todos los datos sensibles ni reemplaza
autorización o revisión. Una operación publicada tampoco concede permisos.

`src/demo.js` exporta los cuatro objetos `scenarios`: `simple`, `oneToMany`,
`oneToOne` y `manyToMany`, además de `metadata` que los combina para navegarlos
en una misma pantalla. Todos usan los mismos componentes y claves `code` en
vez de asumir `id`. Para probar cada configuración individualmente se puede
pasar, por ejemplo, `<App metadata={scenarios.oneToMany}
presentation={presentation} source={source} />` desde el punto de entrada.

## Instalación desde Laravel

Desde el backend Laravel ejecuta:

```bash
php artisan lrg:frontend:template --path=../frontend
```

La ruta es relativa a la raíz del proyecto Laravel, aunque Artisan se invoque
desde otro directorio. Por decisión del proyecto, el frontend debe ser una
carpeta hermana del backend. La carpeta debe existir y contener `package.json`,
`src`, `index.html`, React, React DOM, Vite y el plugin React declarados e
instalados, además de `node` accesible en PATH. Para TSX se requieren también TypeScript y los tipos de React y
React DOM declarados e instalados. Las dependencias se preparan previamente
por el desarrollador; el comando no ejecuta npm.

El instalador admite React 18–19, Vite 5–7 y una configuración Vite estática
reconocible con `defineConfig` y `@vitejs/plugin-react` o
`@vitejs/plugin-react-swc`. Comprueba versiones instaladas, declaraciones y
compatibilidad del plugin con Vite y los requisitos Node declarados por Vite,
el plugin y, cuando se usa SWC, `@swc/core`.
React y React DOM deben tener la misma versión instalada. Reconoce rangos npm simples de versiones
exactas, `^`, `~`, comparadores y alternativas `||`; si no puede verificarlos,
rechaza el proyecto. No se admiten instalaciones con enlaces en las rutas
examinadas. Las configuraciones personalizadas o dinámicas no se modifican y
se rechazan cuando no es posible verificar su compatibilidad.

El punto de entrada JSX o TSX se obtiene del único script de módulo de
`index.html`; debe ser un archivo directo de `src` y coincidir con una de las
formas de entrada sin personalizar reconocidas del proyecto inicial Vite. No
se reconoce cualquier aplicación que simplemente parezca un starter. Se
previsualizan todos los destinos y se solicita confirmación explícita: sin
interacción la instalación se cancela. Los archivos visuales van a `src/lrg/`;
el punto de entrada reconocido se reemplaza para montarlos. El instalador no
modifica `package.json`, configuraciones, `.env`, `index.html` ni otros
componentes, y rechaza cualquier archivo existente en un destino administrado.
Las carpetas y archivos adicionales del desarrollador se conservan.

El registro `frontend/.lrg/frontend-manifest.json` utiliza el contrato
`lrg.frontend.installation` 1.0, identifica la plantilla 1.0.0, el contrato de
metadatos y los archivos administrados con su hash SHA-256. El estado instalado
se determina al verificar el manifiesto y todos sus archivos contra la versión
distribuida; no se añade una propiedad ajena al contrato cerrado. Una segunda
ejecución idéntica no escribe archivos. Una edición de un archivo administrado
o del manifiesto se reporta como conflicto.
El manifiesto actual anuncia únicamente la versión 1.0 de metadatos aunque la
plantilla distribuida ya valida 2.0. No constituye por sí solo evidencia de que
una instalación anterior admita 2.0; el conector verifica además los archivos
exactos de la plantilla distribuida y valida el JSON con su validador real.

La instalación crea un diario recuperable y usa archivos temporales dentro del
frontend. Si ocurre un fallo ordinario intenta restaurar el starter y retirar
los archivos creados; si la recuperación también falla, informa el error y
conserva el diario. Tras una interrupción abrupta, la siguiente ejecución
intenta recuperar el estado anterior antes de preparar otra instalación. Si
algún archivo cambió o el diario no se puede validar, se detiene para revisión
manual. No es una garantía de atomicidad ante fallos de hardware, manipulación
del diario o cambios concurrentes externos; estos requieren inspección. No se
debe borrar el diario manualmente sin verificar los archivos y respaldos.
Se usa un mecanismo propio en lugar de `FileChangeTransaction`: este último no
dispone de diario persistente ni valida por sí mismo enlaces y límites de un
frontend externo.

Se probaron React 19/Vite 7 con JSX y React 18/Vite 5 y 6 con TSX. La
variante SWC también se probó con React 18/Vite 6. La compatibilidad de otras
combinaciones dentro de los rangos no equivale a una prueba exhaustiva.

Las pruebas del instalador pueden ejecutarse desde la raíz de LRG con Node en
PATH y, para la integración, las dependencias de la plantilla ya instaladas:

```bash
php tests/frontend_template.php
php tests/frontend_artisan_integration.php <autoload-laravel> <node_modules-de-la-plantilla> --typescript
```

La integración copia dependencias a un proyecto temporal aislado. La opción
`--typescript` instala dependencias npm **solo en ese fixture** para probar
TypeScript, Vite 5/6 y SWC; no instala ni modifica el proyecto del usuario.

## Procedimiento completo de desarrollo

Este ejemplo usa proyectos hermanos `backend` y `frontend`, sin autenticación:

1. Crea Laravel y React + Vite, y prepara las dependencias de cada proyecto.
   Usa versiones compatibles con los rangos y requisitos Node anteriores:

   ```bash
   composer create-project laravel/laravel backend
   npm create vite@7 frontend -- --template react
   cd frontend
   npm install
   cd ../backend
   ```

2. Configura externamente XAMPP, el VirtualHost y el archivo hosts. Configura
   también la conexión de base de datos de Laravel y establece en su `.env`,
   por ejemplo, `APP_URL=http://lrg-api.test`. LRG no modifica estas opciones.
3. Instala la versión de LRG que incluye ambos comandos:

   ```bash
   composer require franciscovillaquiran/laravel-relation-generator
   php artisan list
   php artisan lrg:frontend:template --path=../frontend
   ```

   Revisa los destinos y confirma la instalación interactiva.
4. Crea los modelos y migraciones de ejemplo:

   ```bash
   php artisan make:model Teacher -m
   php artisan make:model Student -m
   php artisan make:model Course -m
   php artisan make:migration create_course_student_table
   ```

   En los métodos `up` de las migraciones generadas, define las tablas con el
   subconjunto estático admitido por LRG. Por ejemplo:

   ```php
   Schema::create('teachers', function (Blueprint $table): void {
       $table->id();
       $table->string('name');
   });
   Schema::create('students', function (Blueprint $table): void {
       $table->id();
       $table->string('name');
   });
   Schema::create('courses', function (Blueprint $table): void {
       $table->id();
       $table->foreignId('teacher_id')->constrained('teachers')->restrictOnDelete();
       $table->string('title');
       $table->string('description')->nullable();
   });
   Schema::create('course_student', function (Blueprint $table): void {
       $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
       $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
       $table->primary(['course_id', 'student_id']);
   });
   ```

   Coloca cada `Schema::create` en su migración, conserva sus imports y usa
   `Schema::dropIfExists` correspondiente en cada `down`. La migración de la
   pivote debe ejecutarse después de las tablas que referencia.
5. Aplica las migraciones y genera el backend con LRG:

   ```bash
   php artisan migrate
   php artisan relation:make Teacher Course 1:N --crud --api
   php artisan relation:make Course Student N:M --crud --api
   php artisan route:list --path=api
   php artisan lrg:frontend:connect --path=../frontend
   ```

6. Inicia Apache con el VirtualHost previamente configurado y ejecuta
   `npm run dev` en `frontend`. Abre la URL que informa Vite. Crea un profesor
   y estudiantes, después crea un curso seleccionando el profesor y los
   estudiantes. Comprueba en `route:list` las rutas realmente registradas.
   Repite `lrg:frontend:connect` tras cambiar y preparar el backend, y reinicia
   Vite si cambia el proxy.

La aplicación de ejemplo solo publica los recursos LRG verificados; un modelo
sin rutas declaradas no se incluye automáticamente. Las columnas sensibles o
internas no se publican, y un campo obligatorio no publicable puede impedir la
conexión. Los CRUD generados no incorporan validaciones de negocio. La pivote
simple no recibe CRUD independiente.

## Conexión y resincronización

Con el índice, modelos, CRUD y rutas LRG preparados:

```bash
php artisan lrg:frontend:connect --path=../frontend
```

El comando vuelve a validar el frontend y el backend, lee `config('app.url')`,
verifica el contrato 2.0 con el validador React instalado y publica
`src/generated/lrg-schema.json`. Configura `fetch` con rutas relativas y un
proxy Vite hacia el origen Laravel; después reinicia Vite. El comando verifica
la configuración, **no** que el servidor responda. No instala dependencias ni
modifica CORS. El proxy es exclusivo del servidor de desarrollo: producción
necesita su propia infraestructura para servir las rutas relativas.

La conexión administra `src/generated/lrg-http.js`, `src/lrg-connected.jsx`,
`src/lrg-connected.d.ts`, `.lrg/vite-proxy.mjs`, el JSON, el punto de entrada y
la integración mínima de Vite. Sus hashes están en
`.lrg/frontend-connection.json`; el manifiesto original de instalación no se
reescribe. `.lrg/frontend-connect.lock` serializa conexiones concurrentes.
Una modificación local de cualquiera de estos archivos produce un
conflicto; los demás archivos no se eliminan. La repetición sin cambios no
reescribe archivos administrados. Se rechazan configuraciones Vite dinámicas o
personalizadas que el instalador no puede verificar, prefijos ambiguos y URLs
con credenciales, query o fragmento. Sin prefijo, el proxy solo intercepta
endpoints declarados y rechaza colisiones detectadas con rutas Vite o archivos
de `public`.
La verificación comprende los recursos API declarados por LRG; no puede
determinar si un modelo sin ruta declarada se pretendía publicar.

El cliente exige las respuestas JSON simples producidas por los CRUD LRG
verificados; no presupone formatos de controladores externos ni envía cookies.
Muestra los errores HTTP sin exponer errores internos. Las asociaciones N:M
en creación se envían solo cuando están declaradas y el usuario las modifica.
Los CRUD actuales no publican una lectura completa de asociaciones: por ello,
la edición y el reemplazo existentes permanecen en solo lectura aunque exista
un endpoint `replace`. No se inventa una ruta de lectura.

Para probar la conexión en un fixture aislado, con Node en PATH y dependencias
React/Vite ya disponibles, ejecutar desde la raíz de LRG:

```bash
php tests/full_generation_integration.php <autoload-laravel> --validate-frontend-connection <node_modules-react-vite>
```

La prueba inicia Laravel y Vite temporales, verifica el proxy con y sin
prefijo y comprueba resincronización, conflictos y rollback.

Los errores ordinarios de escritura intentan restaurar los archivos anteriores y
se informa cualquier fallo de rollback. La transacción no ofrece recuperación
automática frente a terminación abrupta o corte de energía; en ese caso se
requiere inspección manual de los archivos y respaldos `.lrg-backup-*`.

## Pruebas de regresión e integración

Desde la raíz del repositorio LRG, indica un autoload real de Laravel y un
directorio `node_modules` de un frontend React/Vite compatible:

```bash
php tests/run.php
php tests/frontend_template.php
php tests/frontend_artisan_integration.php <autoload-laravel> <node_modules-frontend>
php tests/api_routes_integration.php <autoload-laravel>
php tests/eloquent_integration.php <autoload-laravel>
php tests/full_generation_integration.php <autoload-laravel> --validate-frontend-contract --validate-frontend-connection <node_modules-frontend>
```

La integración crea proyectos temporales con SQLite y prueba los generadores,
restricciones 1:1, 1:N y N:M, contratos, comandos, resincronización, fallos y
una petición HTTP mediante Vite. Con Node instalado, ejecuta además `npm ci`,
`npm test`, `npm run lint` y `npm run build` en esta carpeta de plantilla.
Estas pruebas no sustituyen una comprobación manual con el VirtualHost real.
