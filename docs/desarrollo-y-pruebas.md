# Desarrollo y pruebas

## Repositorio fuente

El código, las pruebas y la documentación pertenecen a Grupo Dico. El repositorio es `Grupo-Dico/module-product-export-videos`; el paquete continúa siendo `grupodico/module-product-video-export` y registra `LeanCommerce_ProductVideoExport`. No hay una versión publicada ni una instalación autorizada en producción por el hecho de disponer de este código.

## Calidad local

Se necesita Git, Docker y una shell compatible con Bash. El runtime de calidad se construye con PHP 8.3.35, Composer y Python a partir de las imágenes fijadas por digest en `dev/php83/Dockerfile`. No necesita acceso a Magento, una base de datos ni credenciales del cliente.

Desde la raíz del checkout:

```bash
./dev/tooling.sh --working-dir=tests/tooling validate --strict
./dev/tooling.sh --working-dir=tests/tooling install --no-interaction --prefer-dist --no-progress
./dev/tooling.sh --working-dir=tests/tooling check
./dev/tooling.sh --working-dir=tests/tooling exec -- python3 -B ../integration/runner_test.py
```

`check` verifica el árbol de trabajo actual: formato Magento, reglas de estructura y seguridad, pruebas unitarias y contenido del paquete. Las herramientas usan su propio archivo de bloqueo. No se debe ejecutar `composer install` en la raíz para instalar estas herramientas: allí se declaran las dependencias de la aplicación Magento.

Las pruebas unitarias se ejecutan sin Magento, con dobles para las fronteras del framework. El segundo comando de pruebas valida las guardas del runner con entornos temporales y un ejecutable Docker simulado; no conecta al destino. PHPStan se ejecutaría si existiera `phpstan.neon.dist`; este repositorio aún no tiene esa configuración y la cadena informa su omisión. Las advertencias de PHPCS se muestran sin bloquear; los errores sí bloquean.

## Integración con Magento

El [runner de integración](../tests/integration/README.md) se entrega separado de la calidad local. Requiere un entorno Magento compatible y una revisión explícita de sus precondiciones, tiendas y datos; no está diseñado para ejecutarse automáticamente contra producción ni contra un servidor recién recibido.

Las pruebas de integración no se ejecutan en GitHub Actions. Algunas crean fixtures por contratos Magento y después eliminan únicamente sus recursos. Antes de ejecutarlas, el responsable del entorno debe revisar aislamiento, permisos, recuperación y cola; no deben usarse trabajos o productos comerciales para acomodar una prueba. Los resultados se generan localmente y se excluyen de Git.

La suite no incluye instalaciones, snapshots, credenciales, informes históricos o manifiestos de un laboratorio. Un resultado previo no sustituye las comprobaciones del destino. La compilación, operación del consumer, Admin, descarga y apertura del CSV se validan por separado mediante la [guía de instalación](guia-instalacion-y-operacion.md) y el [checklist](checklist-produccion.md).

## Integración continua y distribución

La CI usa el mismo runtime, archivo de bloqueo, comando `check` y regresiones locales del runner en pushes a `main` y en pull requests. Un checkout nuevo puede instalar sus herramientas desde el lock sin acceder al laboratorio ni a un repositorio privado de estándares.

El repositorio conserva pruebas, herramientas y documentación; la distribución Composer las excluye mediante `.gitattributes` y verifica esa exclusión al construir el paquete. No agregues evidencias, secretos o datos de catálogo al historial, aunque el repositorio sea privado.

Los comandos `release` y `bundle` quedan disponibles para una futura versión aprobada: exigen una versión coherente y un commit limpio. No son necesarios para validar esta publicación de código fuente, que no crea un tag ni un release.
