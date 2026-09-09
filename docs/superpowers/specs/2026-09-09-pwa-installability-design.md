# Instalabilidad PWA — Diseño

**Sub-proyecto B** de la secuencia A→F→B→E→D→C (rediseño de marcación de asistencia). Sub-proyectos A (sistema de diseño unificado) y F (theming dinámico) ya mergeados a `main`.

## Objetivo

Permitir instalar `/marcar` (dispositivo personal del empleado) y `/terminal/{code}` (kiosko de sucursal) como aplicaciones independientes ("Agregar a pantalla de inicio" / instalación nativa vía Chrome/Edge), con soporte explícito para iOS/Safari (que no dispara `beforeinstallprompt`).

## Alcance

- Instalables: `/marcar` y `/terminal/{code}`.
- **Fuera de alcance** (no instalables — son flujos intrínsecamente online, ya establecido en A): `/vincular-dispositivo`, `/terminal/{code}/configurar`, `/registro-facial`.
- Sin proyecto de identidad de marca nuevo — se reutiliza el set de íconos ya generado en sub-proyecto A (192/512/maskable/favicon).

## Manifests

Dos manifests, ambos servidos como **rutas dinámicas** (no archivos estáticos) — necesario porque el `theme_color`/`background_color` deben reflejar el color primario configurado en sub-proyecto F (`GeneralSettings::$primary_color` vía `App\Support\ThemeResolver`), y el manifest de Terminal además necesita un `start_url` específico por sucursal.

### `/marcar/manifest.json`

```json
{
  "name": "Nominapp Marcación",
  "short_name": "Marcación",
  "start_url": "/marcar",
  "scope": "/marcar",
  "display": "standalone",
  "theme_color": "<hex del shade 600 de ThemeResolver::primaryColorCss()>",
  "background_color": "<hex del shade 50 de ThemeResolver::primaryColorCss()>",
  "icons": [ /* mismos 192/512/maskable de A */ ]
}
```

Sin dependencia de sucursal — un único endpoint sirve a cualquier empleado.

### `/terminal/{code}/manifest.json`

Mismo shape, con:
- `name`: `"Nominapp Terminal"`, `short_name`: `"Terminal"`.
- `start_url`: `/terminal/{code}` — la PWA instalada abre directo al kiosko de esa sucursal, sin navegación manual.
- `scope`: `/terminal/` — coincide con el scope ya registrado por el Service Worker en `terminal.blade.php` (`{ scope: '/terminal/' }`). Un `scope` más amplio que el `start_url` es válido y deseable: si el terminal se reaprovisiona (mismo registro `Terminal`, mismo `code`, según convención ya establecida — `claimSanctumToken()` reutiliza el registro), la PWA instalada sigue funcionando sin reinstalación.
- `theme_color`/`background_color`: mismos valores que `/marcar` (mismo `ThemeResolver`, una sola instalación → un solo color primario).

Ambas rutas devuelven `Content-Type: application/manifest+json`.

## Íconos

Se reutiliza el set completo de `public/icons/` ya generado en A (`icon-192.png`, `icon-512.png`, `icon-192-maskable.png`, `icon-512-maskable.png`) — mismo ícono para ambos manifests, sin trabajo de diseño nuevo. Coherente con la decisión ya tomada de no abrir un proyecto de identidad de marca separado para Terminal vs Marcación.

## Meta tags

**`<x-theme-vars />`** (componente existente de F) se extiende para emitir también:
```html
<meta name="theme-color" content="<hex del shade 600>">
```
Aprovecha que el componente ya resuelve el color primario vía `ThemeResolver` — sin plomería nueva. Se beneficia en las 5 vistas que ya lo incluyen (aunque solo `/marcar` y `/terminal` son instalables, el `theme-color` también tiñe la barra de direcciones en navegadores móviles normales, así que no molesta en las demás).

**Nuevo componente `<x-pwa-meta manifest-url="..." app-title="..." />`**, incluido solo en `mark.blade.php` y `terminal.blade.php` (lo que sí varía entre ambos modos):
```html
<link rel="manifest" href="{{ $manifestUrl }}">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="{{ $appTitle }}">
```
(`apple-touch-icon` ya lo provee `<x-favicon-links />`, no se duplica.)

En `terminal.blade.php`, `manifest-url` se resuelve con el `{code}` de la sucursal actual (`route('terminal.manifest', $terminal->code)` o equivalente).

## Service Worker

Las dos rutas de manifest **no se agregan** a `CACHE_FIRST_PATTERNS` ni `SHELL_PATTERNS` de `public/sw.js` — quedan sin interceptar (comportamiento normal del navegador), igual que `/vincular-dispositivo` y `/terminal/{code}/configurar` hoy. Razón: el manifest es dinámico (refleja el color actual de `GeneralSettings`); cachearlo agresivamente serviría un `theme_color` desactualizado tras un cambio en F. La instalación de la PWA se asume una acción online (mismo criterio ya aplicado a vinculación/aprovisionamiento) — una vez instalada, el navegador no vuelve a pedir el manifest en cada apertura de la app.

## Instalación — módulo compartido

Nuevo módulo `resources/js/shared/install-prompt.js`, parametrizado por modo (`'mark'` | `'terminal'`), con esta lógica:

- **Android/Chrome/Edge**: escucha el evento `beforeinstallprompt`, lo captura (`event.preventDefault()`) y expone una función para dispararlo bajo demanda (click del banner o del botón de respaldo).
- **iOS Safari**: no existe `beforeinstallprompt`. Detecta la plataforma (UA sniffing — no hay feature-detect confiable) y, si `navigator.standalone === false` (no instalada), muestra instrucciones estáticas ilustradas ("Tocá Compartir → Agregar a pantalla de inicio").
- **Ya instalada**: detecta `window.matchMedia('(display-mode: standalone)').matches` (Android/Chrome) o `navigator.standalone === true` (iOS) — no muestra nada.
- **Dismiss**: al cerrar el banner, guarda una key en `localStorage` (namespaced por modo, ej. `install-dismissed-mark` / `install-dismissed-terminal`) que **no expira** — el banner no vuelve a aparecer en ese dispositivo. Queda un botón de respaldo fijo (visible siempre, sin importar el estado de dismiss) para instalar más tarde.

### Integración en `/marcar`

Banner + botón de respaldo en el header, junto a los controles existentes (tema oscuro, sincronizar) — mismo patrón visual que esos elementos. El banner aparece si: no está instalada, no fue descartada antes, y la plataforma soporta alguna vía de instalación (nativa o instrucciones iOS).

### Integración en Terminal

**Sin banner recurrente** en `terminal.blade.php` — el kiosko no tiene a nadie mirando la pantalla buscando instalar la app después de la configuración inicial. En su lugar, el módulo se invoca **una sola vez**, dentro de `terminal-setup.blade.php`, inmediatamente después de que `statusEl` muestra éxito de vinculación (`btnClaim` completa el flujo) — el admin que está físicamente configurando el kiosko ve el prompt/instrucciones justo ahí y lo instala en el momento.

## Testing

- **Feature**: `GET /marcar/manifest.json` y `GET /terminal/{code}/manifest.json` devuelven `200`, `Content-Type: application/manifest+json`, y el JSON tiene `start_url`/`theme_color`/`name` correctos — incluyendo un caso con `GeneralSettings::$primary_color` distinto del default, para confirmar que el color se refleja.
- **Vitest**: `install-prompt.js` — detección de plataforma (Android/iOS/ya-instalado vía mocks de `navigator.userAgent`/`navigator.standalone`/`matchMedia`), captura y disparo de `beforeinstallprompt`, persistencia del dismiss en `localStorage` (namespaced por modo, no expira).
- Verificación manual en dispositivos reales (Android, iOS, tablet — ya disponibles) del flujo de instalación end-to-end en ambos modos — fuera de lo automatizable.

## Fuera de alcance (explícito)

- Identidad de marca/ícono distinto para Terminal vs Marcación — mismo set de A para ambos.
- `display: fullscreen` para Terminal — ambos usan `standalone`.
- Instalabilidad de `/vincular-dispositivo`, `/terminal/{code}/configurar`, `/registro-facial` — quedan como flujos online normales, sin manifest ni prompt de instalación.
- Reintento periódico del banner tras dismiss — el dismiss es permanente por dispositivo; el botón de respaldo es la única vía posterior.
