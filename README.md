# Nominapp

[![Tests](https://github.com/nextup-py/nominapp/actions/workflows/tests.yml/badge.svg)](https://github.com/nextup-py/nominapp/actions/workflows/tests.yml)

Sistema de gestión de recursos humanos y nómina para Paraguay, construido con Laravel 12 y Filament 3.

## Stack

| | |
|---|---|
| Backend | Laravel 12, PHP 8.2+ |
| Panel admin | Filament 3.3 (todo el CRUD en `/admin`) |
| Frontend | Blade + Vite + Tailwind CSS v4, Vue solo en asistencia/reconocimiento facial |
| Testing | Pest |
| Cola / caché / sesión | driver `database` |
| Locale | Español (`es`), zona horaria `America/Asuncion`, moneda Guaraní (Gs.) |

## Características

- Gestión de empleados, sucursales, departamentos y cargos
- Contratos con ciclo de vida completo (renovación, terminación, liquidación)
- Nómina con pipeline de calculadoras (percepciones, horas extra, deducciones, préstamos, adelantos, bonificación familiar)
- Control de asistencia con reconocimiento facial, marcación offline vía PWA (terminal de sucursal y dispositivo personal)
- Vacaciones, aguinaldo y liquidación de haberes según legislación paraguaya (CLT)
- Préstamos, adelantos de salario y retiros de mercadería a crédito con descuento automático en cuotas
- Pagos bancarios masivos (formato Itaú)
- Permisos, licencias y amonestaciones
- Reportes en PDF y Excel

## Requisitos

- PHP ^8.2 (extensiones: PDO, mbstring, OpenSSL, JSON, BCMath, Ctype, Fileinfo, Tokenizer)
- Composer
- Node.js ≥ 18
- MySQL — el proyecto usa funciones y sintaxis específicas de MySQL (`TIMESTAMPDIFF`, `GROUP_CONCAT`, modo `ONLY_FULL_GROUP_BY`) en reportes y exports; no es compatible con PostgreSQL o SQLite sin adaptar esas queries

## Instalación

1. Clonar el repositorio e instalar dependencias:
   ```bash
   git clone <url-del-repositorio>
   cd nominapp
   composer install
   npm install
   ```

2. Copiar el archivo de entorno y generar la clave de aplicación:
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

3. Configurar `.env`:

   **Base de datos** (obligatorio):
   ```
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=nombre_base_datos
   DB_USERNAME=usuario
   DB_PASSWORD=contraseña
   ```

   **URL de la aplicación** (ajustar según el entorno):
   ```
   APP_URL=http://localhost:8000
   ```

   **Google Maps** (requerido para el mapa de sucursales):
   ```
   GOOGLE_MAPS_API_KEY=tu_clave_aqui
   ```

   **Administrador inicial** (usado por `ProductionSeeder`):
   ```
   ADMIN_NAME="Nombre Apellido"
   ADMIN_EMAIL=admin@ejemplo.com
   ADMIN_PASSWORD=contraseña_segura
   ```
   > Si `ADMIN_PASSWORD` se deja vacío, se genera una contraseña aleatoria y se muestra en consola.

   Cola de trabajos, caché y sesiones ya están configurados como `database` en `.env.example` — no requieren cambios.

4. Migrar, enlazar storage y sembrar datos:
   ```bash
   php artisan migrate
   php artisan storage:link
   ```

   Elegir el seeder según el entorno:

   | Entorno | Comando | Qué hace |
   |---|---|---|
   | Desarrollo | `php artisan db:seed` | Datos de demostración (empleados, nóminas, etc.) |
   | Producción / nuevo cliente | `php artisan db:seed --class=ProductionSeeder` | Usuario admin + deducciones obligatorias del sistema (`IPS001`, `PRE001`, `ADE001`, `MER001`, `LIC001`) |

5. Compilar assets:
   ```bash
   npm run build
   ```

## Desarrollo

Levantar servidor, cola y Vite con HMR en paralelo:
```bash
composer run dev
```

## Testing

```bash
composer run test                              # suite completa
php artisan test --filter=NombreDelTest        # un test puntual
php artisan test tests/Feature/AlgunTest.php   # un archivo puntual
```

Los tests corren contra `.env.testing` (BD `nominapp_testing`, cola `sync`, caché/sesión en memoria) — no requieren Redis ni un mailer real.

Antes de dar por cerrado un cambio:
```bash
vendor/bin/pint --dirty   # formateo
```

## Uso y producción

Acceder a la aplicación en la URL configurada en `APP_URL`.

Antes de desplegar:
```bash
php artisan optimize
php artisan filament:optimize
```

Configurar el scheduler en cron (ausencias, vencimientos de contrato, expiración de enrolamientos faciales, etc.):
```
* * * * * cd /ruta/al/proyecto && php artisan schedule:run >> /dev/null 2>&1
```

## Documentación adicional

Las convenciones de código, la arquitectura de módulos (nómina, préstamos, asistencia offline, etc.) y los mecanismos de deploy están documentados en [`CLAUDE.md`](CLAUDE.md). Documentos específicos:

- [`docs/marcacion-offline.md`](docs/marcacion-offline.md) — arquitectura de marcación offline (terminal y dispositivo personal)
- [`docs/runbook-terminal-revocacion-reprovision.md`](docs/runbook-terminal-revocacion-reprovision.md)
- [`docs/runbook-dispositivo-vinculacion-revocacion.md`](docs/runbook-dispositivo-vinculacion-revocacion.md)
