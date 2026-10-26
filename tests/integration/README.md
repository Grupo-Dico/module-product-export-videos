# Pruebas de integración

Estas pruebas complementan las unitarias con Magento real. Requieren un entorno de pruebas compatible, revisión previa de sus recursos y autorización para crear los fixtures y recursos temporales indicados. La adaptación del ejecutor no está certificada en el servidor de pruebas de Dico: allí se deben comprobar configuración, dependencias y resultados antes de considerar una ejecución válida.

La CI verifica calidad y pruebas unitarias sin instalar Magento; no ejecuta esta integración. No se ha completado la prueba de volumen. Los perfiles `all` y `bounded-recheck` excluyen siempre `catalog` y `dense-performance`, que permanecen disponibles únicamente como etapas individuales para una ejecución expresamente acordada.

## Requisitos del destino

- Contenedor Docker Linux ya existente y accesible; el runner no crea ni configura Magento. El usuario indicado debe poder leer la instalación, ejecutar PHP 8.3 y escribir en su `var/`, medios de fixtures y temporales propios. La raíz y `var/` deben ser directorios físicos, sin redirecciones; las rutas configuradas no admiten espacios.
- Magento 2.4.9, CatalogImportExport y ProductVideo habilitados, con `Bss_CustomProductAttributeExport` habilitado e instalado bajo `app/code/Bss/CustomProductAttributeExport`. Se conservan esas dependencias y ubicación; no se distribuyen sus fuentes.
- Base de datos compatible con la instalación y transporte de cola `db` para `queue` y `failures`. El preflight usa la conexión `default` de `env.php` mediante un DSN MySQL de host/nombre de base; no implementa opciones adicionales de puerto, socket o TLS. Revisar esa compatibilidad antes de ejecutar; no modificar la configuración compartida para acomodar el arnés.
- Tiendas existentes con ID `1` y código `df`, e ID `5` y código `oriente`. El oráculo de fixtures espera exactamente base (código vacío) y las cinco vistas `df`, `oriente`, `suroeste`, `exposhow`, `intro`; son 30 contextos para cinco productos. Se requiere `meta_title` con ámbito de vista de tienda. El runner no crea ni renombra tiendas.
- Muestras existentes seleccionables con y sin video. Los probes usan filtros SKU nativos acotados y requieren candidatos únicos con caracteres alfanuméricos/guion; la inyección de fallos necesita un grupo de entre dos y seis productos y la medición una muestra acotada. La falta de muestras es una precondición incumplida, no un resultado satisfactorio.
- En el equipo del operador: Bash, Docker CLI y Python 3.10 o posterior. La ejecución y observación de capacidad esperan Linux en el contenedor. No se inicia cron ni un consumidor global.

## Configuración explícita

Definir las cuatro variables con valores del destino revisado. No hay valores predeterminados del laboratorio:

```bash
export LCVX_MAGENTO_ROOT=/srv/magento
export LCVX_CONTAINER=magento-test
export LCVX_CONTAINER_USER=1000:1000
export LCVX_PHP_BINARY=/usr/bin/php8.3
./tests/integration/run.sh preflight
```

El ejemplo es ilustrativo: reemplazar los valores por los reales. La validación local rechaza variables ausentes o formatos inválidos antes de Docker. Dentro del contenedor, cada script valida la raíz, los archivos de instalación y `var/` antes de crear sus recursos. Las credenciales se leen únicamente desde la instalación; no se pasan en estas variables ni se guardan en Git.

Después de revisar el preflight y el alcance de escritura:

```bash
./tests/integration/run.sh all
```

`all` ejecuta preflight, lector, exportación directa, limpieza CSV, fixtures, cola controlada, formulario/ACL, inyección de fallos y medición acotada. `fixtures` crea cinco productos `LCVX-` mediante contratos de Magento y elimina sólo sus identidades y previews registrados. La cola usa recursos propios en proceso con rollback; no consume trabajos ajenos. Los ledgers se conservan para recuperación exacta si una limpieza queda incompleta.

También se admite `run.sh <etapa>`. Para recuperar únicamente un ledger conocido:

```bash
./tests/integration/run.sh recover IT-identificador-registrado
```

## Reutilización explícita de evidencia de fallos

`bounded-recheck` ejecuta las etapas acotadas, pero reutiliza un reporte previo de `failures` indicado por el operador. No incluye ni busca una corrida histórica del proyecto:

```bash
export LCVX_FAILURES_EVIDENCE=/ruta/privada/failures.json
./tests/integration/run.sh bounded-recheck
```

El archivo debe ser físico, regular, absoluto y contener un resultado PASS con limpieza completa. Antes de conectar al destino se exige igualdad exacta de conjuntos y hashes de todo `src/LeanCommerce/ProductVideoExport/`, `composer.json`, `failures.php`, `FailureHarness.php` y el helper compartido `runner-support.php`. El inventario debe haberse capturado durante esa ejecución y haberse cotejado al finalizar. El helper es obligatorio en ambos inventarios: tampoco se acepta omitirlo tanto en la evidencia como en el candidato. Un archivo ausente, reporte parcial, cambio, alta o baja de fuentes impide reutilizarlo; no se repite `failures` automáticamente. Para generar evidencia nueva, ejecutar expresamente `run.sh failures` en un entorno revisado y conservar el JSON resultante.

## Resultados y límites

Cada corrida crea un snapshot nuevo, verifica hashes antes y después y guarda informes en `internal/evidencia/integracion-AAAAMMDD/`, excluido de Git. Mantener esos resultados privados: contienen metadatos de instalación, tiendas, colas y fixtures. No añadir CSV del catálogo, credenciales ni logs crudos al repositorio.

El primer fallo detiene las etapas siguientes; limpieza incompleta impide exit 0. Una interrupción espera a que termine el proceso activo antes de limpiar su snapshot. Se elimina sólo el candidato propio y se conservan los ledgers. Un PASS cubre las etapas ejecutadas: no certifica navegador/login, descarga, apertura en Excel/Sheets, consumidor instalado permanente ni volumen. La medición acotada es informativa y no se extrapola al catálogo completo.

Las guardas del runner se comprueban con el runtime de calidad PHP 8.3, sin conexión al contenedor Magento ni a su base de datos:

```bash
./dev/tooling.sh --working-dir=tests/tooling exec -- python3 -B ../integration/runner_test.py
```

El contenedor efímero de tooling ejecuta Python y PHP sobre archivos sintéticos; no carga Magento ni ejecuta Docker desde las pruebas. No usar una instalación PHP local incompatible como sustituto de este runtime. Las unitarias del producto se ejecutan mediante el tooling documentado en [Desarrollo y pruebas](../../docs/desarrollo-y-pruebas.md).
