# Actualizar Nominapp en un servidor con cPanel

Guía para quien actualiza una instalación sin automatización (hosting compartido o con cPanel, Node viejo, acceso por SSH). Cubre el flujo completo con los scripts de `scripts/cpanel/`, cómo volver atrás y los problemas que ya aparecieron en instalaciones reales.

> Los valores propios de cada instalación (ruta, binario de PHP, URL, usuario del servidor) **no se guardan en el repositorio**: van en un archivo de configuración dentro del servidor. En esta guía se escriben como `<RUTA_APP>`, `<PHP>`, `<USUARIO@SERVIDOR>`.

Para el checklist completo previo al día del deploy (avisos al cliente, limpiezas de datos, terminales) ver `docs/producto/checklist-deploy-cpanel.md`.

---

## Qué hay y cómo se reparten el trabajo

| Dónde | Qué | Para qué |
|-------|-----|----------|
| **PC del desarrollador** | `scripts/cpanel/Preparar-Build.ps1` | Compila los assets (Vite) y los sube al servidor. El servidor tiene Node viejo y no puede compilar |
| **Servidor** | `scripts/cpanel/deploy.sh` (se usa una copia en `~/deploy-nominapp.sh`) | `verificar`, `actualizar` y `restaurar` |
| **Servidor** | `~/deploy-nominapp.conf` | Los valores de esa instalación (modelo: `scripts/cpanel/deploy.conf.example`) |

---

## Primera vez en un servidor (una sola vez)

1. Entrar por SSH al servidor.

2. Bajar el script y el modelo de configuración desde el repositorio (no hace falta que el servidor ya los tenga):

```bash
cd <RUTA_APP>
git fetch origin main
git show origin/main:scripts/cpanel/deploy.sh > ~/deploy-nominapp.sh
git show origin/main:scripts/cpanel/deploy.conf.example > ~/deploy-nominapp.conf
chmod +x ~/deploy-nominapp.sh
```

3. Completar `~/deploy-nominapp.conf` (`nano ~/deploy-nominapp.conf`): `APP_DIR`, `PHP_BIN`, `APP_URL` y, si hace falta, el resto de las variables opcionales (están explicadas en el archivo).

4. Revisar el `.env` del servidor:
   - `DB_ENGINE=InnoDB` si el motor por defecto de MySQL es MyISAM (ver *Problemas conocidos*).
   - Las variables nuevas que pida el release (por ejemplo `GOOGLE_MAPS_API_KEY`).

5. Comprobar que el cron del programador existe y que **no** hay un cron de cola (nada usa `ShouldQueue`):

```bash
crontab -l
```

   Debe haber una sola línea con `artisan schedule:run` cada minuto.

6. Probar sin cambiar nada:

```bash
~/deploy-nominapp.sh verificar
```

Si lista problemas, resolverlos y repetir hasta que diga **Todo listo para actualizar**.

> El script se copia a un archivo temporal antes de ejecutarse: así `git pull` puede reemplazarlo sin que se mezclen dos versiones. Para tener la versión nueva del script en `~/deploy-nominapp.sh`, repetir el `git show` del paso 2 cuando cambie en `main`.

---

## Cada actualización

### 1. En tu PC: armar y subir el build

PowerShell no admite `&&`: un comando por vez. Desde la raíz del repositorio:

```powershell
git checkout main
git pull origin main
.\scripts\cpanel\Preparar-Build.ps1 -Servidor <USUARIO@SERVIDOR>
```

El script:
- verifica que estés en `main` y igual a `origin/main` (si no, se detiene);
- corre `npm ci` y `npm run build`;
- guarda el commit en `public\build\commit.txt`;
- comprime a `build.tar.gz` en tu carpeta de usuario y lo sube por `scp` a la carpeta personal del servidor (pide la contraseña de tu clave SSH).

Opciones: `-Puerto 22`, `-Rama main`, `-SinSubir` (solo arma el archivo, para subirlo a mano con el Administrador de archivos de cPanel).

Si el release **no cambia** JS, CSS ni vistas Blade, se puede omitir este paso y usar `--sin-build` en el servidor.

### 2. En el servidor: verificar y actualizar

```bash
~/deploy-nominapp.sh verificar
~/deploy-nominapp.sh actualizar
```

`actualizar` hace, con pausas de confirmación:

| Paso | Qué hace |
|------|----------|
| Verificación | La misma de `verificar`; se detiene si algo está mal |
| Respaldo | Vuelca la base comprimida en `~/respaldos-deploy/` y guarda el commit y el build actuales para poder volver |
| Mantenimiento | `artisan down` |
| Código | `git pull --ff-only`, `composer install --no-dev` e instala el build (avisa si el build se generó desde otro commit) |
| Migraciones | Muestra las pendientes, pide confirmar y corre `migrate --force` |
| Caches | `livewire:publish`, `filament:assets`, `optimize`, `filament:optimize`, `queue:restart` |
| Pausa | Momento para correr seeders u otros comandos que pida el release (por ejemplo `PermissionSeeder`) antes de volver en línea |
| En línea | `artisan up` |
| Comprobaciones | Build presente, `APP_URL/up` responde 200, el programador corrió hace poco, errores nuevos en el log |
| Limpieza | Conserva solo los últimos respaldos (`BACKUP_KEEP`, 5 por defecto) |

Opciones: `--yes` (sin pausas; solo para quien ya conoce el flujo), `--sin-build`.

### 3. Prueba manual (el script no la reemplaza)

- Abrir el panel e iniciar sesión.
- Recorrer los listados con pestañas (por ejemplo Contratos → Vencidos) y **ordenar por una columna**: una consulta que falla solo se ve al usarla.
- Revisar los terminales: los ya vinculados deben aparecer **En línea** en unos minutos y se recargan solos con la versión nueva.
- Si el release trae una migración que genera un archivo (por ejemplo un CSV en `storage/app/private/`), anotar el nombre y entregarlo al cliente.

---

## Si algo falla

| Dónde falla | Qué hace el script | Qué hacer |
|-------------|--------------------|-----------|
| **Verificación** | No cambia nada | Resolver lo que indica y repetir |
| **Código o build** (antes de tocar la base) | Vuelve solo al commit y al build anteriores y sube el sitio | Leer el error, corregir y reintentar |
| **Migraciones o caches** (la base pudo cambiar) | Deja el sitio **en mantenimiento a propósito** | Corregir el error y repetir el paso a mano, o volver atrás (ver abajo) |
| **Después de volver en línea** | Solo avisa | Decidir si se vuelve atrás |

### Volver atrás

```bash
~/deploy-nominapp.sh restaurar          # solo el código y el build anteriores
~/deploy-nominapp.sh restaurar --db     # además la base del respaldo
```

- `restaurar` sin `--db` alcanza cuando las migraciones fueron solo aditivas (tablas o columnas nuevas): el código anterior las ignora.
- `restaurar --db` **pierde todo lo registrado desde el respaldo** (marcaciones, solicitudes, aprobaciones). Pide escribir `RESTAURAR` para confirmar. Usarlo solo si una migración dejó la base inconsistente.
- Los respaldos quedan en `~/respaldos-deploy/` (`.sql.gz`). Restauración manual: `gunzip -c <archivo> | mysql ...`.

---

## Problemas conocidos

| Síntoma | Causa | Solución |
|---------|-------|----------|
| La migración falla con *Specified key was too long* y deja una tabla a medias | El motor por defecto del servidor es MyISAM | `DB_ENGINE=InnoDB` en el `.env` **antes** de migrar (`verificar` lo comprueba). Si ya falló: borrar la tabla que quedó a medias antes de reintentar (MySQL no deshace el `create table`) |
| `git pull` se niega a continuar | Hay archivos modificados a mano en el servidor | `verificar` los lista. Si el cambio ya está resuelto en `main`: `git checkout -- <archivo>`. Si es propio del servidor, agregarlo a `ALLOWED_LOCAL` |
| `npm run build` falla en el servidor con *Out of memory* | Node viejo y poca memoria | Por eso el build se compila en tu PC (`Preparar-Build.ps1`) |
| El script avisa que el build es de otro commit | Se generó desde otra versión de `main` | Repetir `Preparar-Build.ps1` después de `git pull` |
| Las horas del log no coinciden con las del servidor | El log usa `APP_TIMEZONE`; `date`, `ls` y `uptime` usan la zona del servidor | Comparar siempre con la hora del log |
| El programador no corre | Falta o está mal el cron de `schedule:run` | `crontab -l` y `tail storage/logs/cron.log` |
| Errores del proceso entre `git pull` y `composer install` | El programador corre mientras el código y las dependencias no coinciden | Es esperable durante la actualización con el sitio en mantenimiento |
| En PowerShell un comando con `&&` falla | PowerShell no admite `&&` | Un comando por línea |

---

## Qué no hace el script

- No corre seeders ni scripts de limpieza de datos: usar la **pausa** previa a volver en línea. Las limpiezas solo con confirmación escrita del cliente, respaldo del mismo día y `--dry-run` primero.
- No avisa a los usuarios ni coordina el horario: hacerlo antes (ver el checklist).
- No instala el cron del programador ni edita el `.env`.
- No cambia la versión de PHP ni instala Node.
