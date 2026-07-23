[English](README.md) · [Italiano](README.it.md) · **Español**

# AIManager

AIManager es un centro de IA local para macOS que reúne chat, proyectos, memoria, múltiples
proveedores y un entorno Code controlado. Los datos de la aplicación permanecen en tu máquina; cuando
eliges un proveedor en la nube, las peticiones y los adjuntos necesarios se envían a ese servicio
según sus propias condiciones.

El proyecto ha superado la validación técnica de su primera versión y es utilizable localmente. La
distribución actual es un archivo manual para macOS: no es una `.app` y no incluye instalador, firma,
notarización ni actualización automática.

## Funciones disponibles

- chat en streaming con enrutado y respaldo entre proveedores;
- proyectos, sesiones, memoria y continuidad del contexto;
- búsqueda web, adjuntos y generación de imágenes opcionales;
- configuración y prueba de credenciales desde la interfaz Provider;
- Code sobre una carpeta autorizada, con lecturas específicas, propuestas de cambio, verificaciones
  curadas, comandos de solo lectura, servidor PHP local y Git asistido hasta el commit local.

Code no es un sandbox del sistema operativo. Las operaciones que modifican archivos requieren
confirmación, y no existe ni una shell general ni un push de Git implícito.

## Requisitos

- macOS, uso local y de un solo usuario;
- PHP 8.5 con SQLite, cURL y mbstring;
- `pcntl` y `posix` para los comandos de Code y los procesos persistentes;
- al menos un proveedor de IA: tu propia clave en la nube, o LM Studio instalado por separado.

## Inicio rápido

```bash
cp .env.example .env
bin/launch.sh
```

AIManager se abre en `http://127.0.0.1:8000`. En el primer arranque:

1. entra en **Provider**;
2. elige LM Studio o un proveedor en la nube;
3. introduce endpoint, modelo y, si hace falta, tu clave;
4. activa el proveedor, ejecuta **Test** y después **Salva** (guardar);
5. abre **Nuova chat** (nuevo chat).

No hace falta escribir las claves a mano en `.env`: la interfaz Provider las guarda localmente.
Consulta [la guía de proveedores](docs/PROVIDERS.md) y [la guía de usuario](docs/USER_GUIDE.md) para
el recorrido completo. Instalación, actualización y rollback están descritos en
[RELEASE.md](docs/RELEASE.md).

> La interfaz de la aplicación y las guías detalladas de `docs/` están por ahora solo en italiano.
> La localización del producto es una dirección posterior al lanzamiento, no una promesa de esta
> versión.

## Datos y privacidad

- `.env` contiene las credenciales y es local;
- `storage/` contiene base de datos, conversaciones, memorias, adjuntos, registros y copias de
  seguridad;
- las carpetas abiertas en Code permanecen fuera de AIManager;
- `.env`, los datos de ejecución, las copias de seguridad y los workspaces nunca deben entrar en un
  commit ni en una release.

Para los límites y el reporte responsable de problemas consulta [SECURITY.md](SECURITY.md).

## Estado y contribuciones

La prioridad es hacer fiable el primer uso y validarlo con usuarios externos, no añadir funciones de
forma indiscriminada. Consulta la [hoja de ruta pública](docs/PUBLIC_ROADMAP.md).

Antes de proponer cambios lee [CONTRIBUTING.md](CONTRIBUTING.md).

## Licencia

AIManager se distribuye bajo la [Apache License 2.0](LICENSE).

---

Desarrollado por [Gennari Productions](https://gennari.es/) — [Alessandro Gennari](https://gennari.es/alessandro-gennari.html), AI Consultant, Las Palmas de Gran Canaria.
