[English](README.md) · [Italiano](README.it.md) · **Español**

# AIManager

**AIManager permite trabajar con múltiples sistemas de inteligencia artificial desde un
único entorno: modelos residentes en el Mac y servicios externos gratuitos o de pago.**

Conserva localmente conversaciones, proyectos, documentos, instrucciones, contexto y
memoria. Puedes continuar el mismo trabajo con otra inteligencia artificial sin empezar
de nuevo ni depender de un solo proveedor. AIManager puede elegir la inteligencia
artificial más adecuada y utilizar otra cuando la principal no esté disponible.

## Requisitos

- macOS, para uso local por una sola persona;
- PHP 8.5 con SQLite, cURL y mbstring;
- Git;
- al menos un proveedor: una clave personal para un servicio externo o
  [LM Studio](https://lmstudio.ai/download) para utilizar modelos locales.

Las funciones de **Code** también requieren las extensiones PHP `pcntl` y `posix`. Los
servicios externos no necesitan un modelo local. Para LM Studio, la memoria y el espacio
necesarios dependen del modelo; un Mac mini M2 Pro con 16 GB de memoria unificada es una
configuración práctica de referencia para modelos locales pequeños.

## Descarga

```bash
git clone https://github.com/acaro76/AIManager.git
cd AIManager
```

## Instalación

```bash
bash bin/install.sh
```

El comando comprueba los requisitos y prepara la configuración local sin solicitar ni
mostrar claves.

## Inicio

```bash
bash bin/launch.sh
```

AIManager se abre en el navegador en <http://127.0.0.1:8000>.

## Primer uso

1. Abre **Provider**.
2. Elige LM Studio o un servicio externo.
3. Introduce tu clave solo si el servicio la necesita.
4. Activa el proveedor, ejecuta **Test** y pulsa **Guardar**.
5. Abre **Nuevo chat**.

AIManager detecta automáticamente los modelos disponibles en LM Studio. Las credenciales
se configuran desde la interfaz y permanecen en la configuración local.

## Qué puedes hacer

- conversaciones progresivas con selección y alternativa automática entre proveedores;
- proyectos, sesiones, memoria y continuidad del contexto;
- búsqueda web, archivos adjuntos y generación de imágenes;
- configuración y comprobación de proveedores desde la interfaz;
- trabajo asistido en carpetas autorizadas con **Code**, incluidas lecturas específicas,
  propuestas de cambios, comprobaciones controladas y Git local.

**Code no es una barrera de seguridad del sistema operativo.** Las operaciones que
modifican archivos requieren confirmación; no existe una línea de comandos general ni
una publicación Git implícita.

## Datos y control

- `.env` contiene las credenciales y permanece local;
- `storage/` contiene la base de datos, conversaciones, memorias, archivos adjuntos,
  registros y copias de seguridad;
- las carpetas abiertas en Code permanecen fuera de AIManager;
- `.env`, los datos de ejecución, las copias de seguridad y las carpetas de trabajo no
  deben publicarse en el repositorio.

Consulta la [guía de proveedores](docs/PROVIDERS.md), la
[guía de usuario](docs/USER_GUIDE.md) y las instrucciones de
[actualización y recuperación](docs/RELEASE.md). Para los límites de seguridad, consulta
[SECURITY.md](SECURITY.md).

## Licencia

AIManager se distribuye bajo la [Apache License 2.0](LICENSE).

---

Desarrollado por [Gennari Productions](https://gennari.es/) —
[Alessandro Gennari](https://gennari.es/alessandro-gennari.html), consultor de IA,
Las Palmas de Gran Canaria.
