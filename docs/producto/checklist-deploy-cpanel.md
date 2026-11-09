# Checklist de deploy manual a un cliente con cPanel

Plantilla genérica para instalaciones sin automatización (hosting compartido, Node viejo, sin SSH desde la PC del desarrollador). Reemplazar los marcadores `<...>` por los valores de la instalación; esos valores **no se guardan en el repositorio**.

Variables: `<RUTA_APP>` = carpeta de la aplicación en el servidor, `<PHP>` = binario de PHP del hosting (por ejemplo `/opt/cpanel/ea-phpXX/root/usr/bin/php`).

## Antes del día

- [ ] Fecha y hora acordadas con el cliente y avisados los usuarios; evitar fechas de pago.
- [ ] Commit de `main` a desplegar acordado.
- [ ] Confirmación **escrita** del cliente para cualquier limpieza de datos (qué se borra y qué no, quién la da y cuándo).
- [ ] Decisiones pendientes del cliente resueltas (cuotas vencidas, préstamos sin entregar, adelantos).
- [ ] Si el release incluye scripts de limpieza: ensayo sobre una copia de la base.
- [ ] Responsable por sucursal con teléfono, y respaldo de RR.HH. para aprobar códigos.
- [ ] Terminales registrados por equipo; equipos preparados y probados (navegador actualizado, cámara, pantalla encendida).
- [ ] Texto del aviso de privacidad de cada empresa, si lo usan.
- [ ] Ensayo del flujo de vinculación en un entorno de pruebas con equipos reales.
- [ ] Revisar variables nuevas de `.env` (por ejemplo la clave de mapas) y cargarlas antes.

## 1. En la PC del desarrollador

PowerShell no admite `&&`: un comando por vez.

- [ ] `git checkout main`
- [ ] `git pull origin main` y verificar con `git log --oneline -1` el commit esperado.
- [ ] `npm ci`
- [ ] `npm run build`
- [ ] Comprimir el build: `Compress-Archive -Path public\build -DestinationPath $HOME\build.zip -Force`

## 2. En el servidor: respaldo y punto de restauración

- [ ] `cd <RUTA_APP>` y `PHP=<PHP>`
- [ ] `git status --short` (solo se esperan los archivos de configuración de PHP del hosting).
- [ ] `git rev-parse HEAD > ~/hash-anterior.txt`
- [ ] `rm -rf ~/build-anterior; cp -r public/build ~/build-anterior`
- [ ] Respaldo de la base justo antes de bajar el sitio, leyendo credenciales del `.env` sin mostrarlas:

```bash
DBN=$(grep -E '^DB_DATABASE=' .env | cut -d= -f2- | tr -d '"'"'")
DBU=$(grep -E '^DB_USERNAME=' .env | cut -d= -f2- | tr -d '"'"'")
export MYSQL_PWD=$(grep -E '^DB_PASSWORD=' .env | cut -d= -f2- | tr -d '"'"'")
mysqldump -u"$DBU" --single-transaction --routines "$DBN" > ~/respaldo-$(date +%Y%m%d-%H%M).sql
unset MYSQL_PWD
ls -lh ~/respaldo-*.sql
```

- [ ] Comprobar que el tamaño del respaldo es coherente con el de la base.

## 3. Actualización

- [ ] `$PHP artisan down`
- [ ] `git fetch origin main && git log --oneline -1 origin/main`
- [ ] `git pull origin main` y `git log --oneline -1`
- [ ] `$PHP $(which composer) install --no-dev --optimize-autoloader`
- [ ] Subir el build desde el **Administrador de archivos** de cPanel: subir `build.zip` a `public/`, borrar `public/build` y extraer el zip.
- [ ] Comprobar que existe `public/build/manifest.json`.
- [ ] `$PHP artisan migrate --force`
- [ ] Seeders si el release los requiere (`PermissionSeeder`, `BusinessActionPermissionSeeder`, `RoleSeeder`).
- [ ] Si hay migraciones que generan archivos de respaldo (por ejemplo CSV en `storage/app/`), anotar el nombre y entregarlo al cliente.
- [ ] Scripts de limpieza: solo con confirmación escrita y respaldo del mismo día; primero `--dry-run`, revisar los conteos con el cliente, luego la corrida real.
- [ ] `$PHP artisan livewire:publish --assets`
- [ ] `$PHP artisan filament:assets`
- [ ] `$PHP artisan optimize:clear`
- [ ] `$PHP artisan optimize`
- [ ] `$PHP artisan filament:optimize`
- [ ] `$PHP artisan queue:restart`
- [ ] `$PHP artisan up`

## 4. Verificación inmediata

- [ ] El panel abre y se puede iniciar sesión.
- [ ] Los listados principales (empleados, contratos) cargan.
- [ ] Los terminales registrados aparecen como **Sin vincular** si todavía no se vincularon.
- [ ] `tail -n 20 storage/logs/cron.log` muestra el programador corriendo.
- [ ] Sin errores nuevos en `storage/logs/laravel-AAAA-MM-DD.log`.
- [ ] `public/.htaccess` y `public/.user.ini` conservan la configuración de PHP del hosting.

## 5. Proceso de cola

- [ ] Agregar al cron del servidor: `* * * * * cd <RUTA_APP> && <PHP> artisan queue:work --stop-when-empty --max-time=55 >> storage/logs/queue.log 2>&1`
- [ ] A los 2 minutos, comprobar que la tabla `jobs` quedó en 0.

## 6. Configuración posterior (misma noche, antes del primer uso)

- [ ] Empresas: completar los datos obligatorios (tipo societario, representante legal y su cédula).
- [ ] Aviso de privacidad: cargar texto y enlace opcional en cada empresa.
- [ ] Roles: asignar RR.HH. a quienes aprueban vinculaciones y limitar al resto.
- [ ] Habilitar marcación por celular solo a quienes corresponda.
- [ ] Datos de hijos: RR.HH. completa e importa la plantilla.
- [ ] Marcar como entregados los préstamos ya entregados, antes de la primera nómina oficial.
- [ ] Terminales: **Asistencias → Terminales** → abrir cada uno → **Generar enlace de configuración** (vigencia de 24 h) y copiar la URL de inmediato (se muestra una sola vez). Enviarla por mensaje privado solo al responsable de esa sucursal y anotar qué enlace corresponde a qué equipo.

## 7. Primer día de uso

- [ ] Los responsables llegan antes del horario de marcación: la primera carga descarga los modelos de reconocimiento y necesita internet estable.
- [ ] Vincular equipo por equipo; si un enlace no sirve, generar uno nuevo (invalida el anterior) o usar el código con la aprobación de RR.HH.
- [ ] Quien no pueda marcar anota nombre y hora real en papel; la carga posterior se hace con **Corregir marcaciones**.
- [ ] Al terminar: revisar marcaciones por sucursal y **Fallos de marcación**.

## 8. Si hay que volver atrás

Antes de que la gente empiece a marcar con la versión nueva. Si ya se corrió una limpieza de datos, volver atrás implica restaurar la base con el respaldo del mismo día.

- [ ] `$PHP artisan down`
- [ ] Restaurar la base con el `.sql` del paso 2 (terminal con `mysql` o phpMyAdmin).
- [ ] `git reset --hard $(cat ~/hash-anterior.txt)`
- [ ] `rm -rf public/build; cp -r ~/build-anterior public/build`
- [ ] `$PHP artisan optimize:clear` y `$PHP artisan up`
- [ ] Avisar al cliente.

## 9. Después

- [ ] Conservar el respaldo, `hash-anterior.txt` y `build-anterior` al menos 7 días.
- [ ] Borrar archivos sueltos del servidor que no se necesiten; decidir si algún respaldo viejo se guarda fuera del servidor.
- [ ] Revisar la bandeja **Por resolver** y programar la limpieza de datos acordada.
