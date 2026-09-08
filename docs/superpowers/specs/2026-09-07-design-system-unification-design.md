# Sub-proyecto A — Sistema de diseño unificado

Primer eslabón de una iniciativa mayor (secuencia **A → F → B → E → D → C**) de rediseño UX/UI + PWA instalable + unificación del motor offline para las vistas públicas de marcación de asistencia de Nominapp. Este documento cubre exclusivamente **A**. El resto de la secuencia (F: theming configurable desde Filament, B: instalabilidad PWA, E: rediseño visual/UX, D: reestructuración de `terminal.js`, C: unificación del motor offline mobile/terminal) queda fuera de alcance — cada uno tendrá su propia spec cuando llegue su turno.

## Contexto

El proyecto tiene 4 vistas públicas de marcación de asistencia (`/marcar`, `/terminal`, `/vincular-dispositivo`, `/registro-facial`) más una vista semi-pública (`/employees/{id}/capture-face`, autenticada). Una investigación previa (ver memoria de proyecto `project_pwa_marcacion_redesign.md`) encontró:

- **3 copias paralelas de tokens de diseño**, ya divergentes entre sí, duplicadas verbatim entre `resources/css/attendances/styles.css` (mark), `resources/css/attendances/terminal.css` (terminal) y `resources/css/shared/capture-face.css` (enrolamiento/captura admin).
- **Poppins cargado 3 veces desde Google Fonts** (`styles.css`, `terminal.css`, `capture-face.css`) con pesos ligeramente distintos, sin cachear por el service worker (dominio externo, fuera del chequeo de origen de `public/sw.js:99`) — rompe la tipografía en escenarios realmente offline.
- **Panel Filament ya usa Poppins también** — `AdminPanelProvider.php:31` tiene `->font('Poppins')`, sin `provider` explícito, por lo que usa el default de Filament (`BunnyFontProvider`, `Panel/Concerns/HasFont.php:12`) — carga Poppins desde Bunny Fonts (externo). El `--font-sans: 'Instrument Sans'` que existe en `app.css:9` es un token de Tailwind sin relación con el panel de Filament — solo lo consume `resources/views/welcome.blade.php`, la landing default de Laravel, que no tiene ninguna ruta que la sirva (confirmado por grep en `routes/web.php`) y queda fuera de alcance.
- **`favicon.ico` de 0 bytes** — roto, no solo ausente. No existe manifest.json ni íconos PWA en `public/`.
- **1102 líneas de CSS completamente muerto** (`resources/css/employees/capture-face.css`, `resources/css/enrollments/capture-face.css`) — no están en `vite.config.js` ni son importados por ningún blade (ambos wrappers fijan `$css = 'resources/css/shared/capture-face.css'` explícitamente).
- `device-link.blade.php` vive 100% fuera del sistema — estilos inline, `font-family: Arial`, paleta azul propia, sin relación con los tokens teal/Poppins del resto.
- El `primary` color de Filament ya está configurado como `Color::Teal` (`app/Providers/Filament/AdminPanelProvider.php:34`) — coincide por casualidad con el teal (`#0d9488`) que ya usan `styles.css`/`terminal.css`, así que no hay conflicto de paleta, solo de organización del código.

## Objetivo

Un único sistema de tokens de diseño (color, tipografía, spacing, radios, sombras, dark mode) consumido por las 4 vistas públicas **y** el panel Filament, con tipografía self-hosted (resolviendo el problema de offline), favicon/íconos PWA funcionales, y sin CSS muerto ni duplicación de definiciones.

**Explícitamente fuera de alcance de A:** rediseño de layout/UX de las pantallas (eso es E), manifest.json y lógica de instalación PWA (eso es B), cualquier configurabilidad de color/fuente desde el admin (eso es F). A establece los *valores por defecto* del sistema; F más adelante los hace overrideables en runtime — por eso A usa custom properties de Tailwind v4 (reasignables) y no valores hardcodeados dispersos, precisamente para no reñir con ese trabajo futuro.

## Arquitectura de tokens

Nuevo archivo `resources/css/shared/tokens.css`, con un bloque `@theme` de Tailwind v4 que define:

- **Color** — paleta alineada 1:1 con `AdminPanelProvider->colors()`: `primary` (teal-600 `#0d9488` y su escala 50-950), `success` (green), `danger` (red), `info` (blue), más los neutros de superficie (`bg`, `surface`, `border`, `text`, `muted`, `subtle`) hoy repartidos en `--c-*` con nombres consistentes entre sí. Se corrigen las divergencias puntuales ya detectadas (ej. `--c-danger-l` presente en `styles.css` pero ausente en `terminal.css`).
- **Caso `warning` — discrepancia real a resolver explícitamente:** Filament tiene configurado `warning: Color::Yellow` (`AdminPanelProvider.php:38`), pero `styles.css`/`terminal.css` usan hoy un ámbar (`#d97706` ≈ Tailwind amber-600), no amarillo. Son colores visualmente distintos. Se resuelve a favor de **Filament** (`Color::Yellow`, escala completa) por el objetivo explícito de coherencia con el admin — el ámbar actual de las vistas de marcación se descarta, no se promueve a token.
- **Tipografía** — `--font-sans: 'Poppins', ui-sans-serif, system-ui, sans-serif, ...`. Poppins es la fuente real ya usada tanto por el panel Filament (`->font('Poppins')`) como por las 4 vistas de marcación — no hay migración de identidad tipográfica, solo consolidación de origen (self-hosted en vez de 4 requests externos distintos: 3 a Google Fonts + 1 a Bunny Fonts).
- **Spacing / radios / sombras** — se consolida el superset de `--sp-*`, `--r-*`, `--sh-*`, `--tr-*` que hoy existe repartido y parcialmente divergente entre `styles.css` y `terminal.css` (ej. `terminal.css` tiene `--sp-12`, `--r-2xl`, `--r-3xl`, `--sh-xl` que `styles.css` no tiene — se incluyen todos en el set único).
- **Dark mode** — un solo bloque `@media (prefers-color-scheme: dark)` + `html[data-theme="dark"]` en vez de las 4 copias actuales (2 en cada uno de los 2 archivos existentes).

Los 4 entrypoints CSS pasan a importar este archivo como primera línea y retienen solo sus estilos específicos de layout/componentes:
- `resources/css/app.css` (admin Filament)
- `resources/css/attendances/styles.css` (mark)
- `resources/css/attendances/terminal.css` (terminal)
- `resources/css/shared/capture-face.css` (enrolamiento + captura admin de rostro)

`device-link.blade.php` deja de tener CSS inline embebido en `<style>` y pasa a tener su propio entrypoint mínimo (`resources/css/attendances/device-link.css`, nuevo, agregado a `vite.config.js`) que también importa `tokens.css` — solo reemplaza colores/tipografía por las variables compartidas, sin tocar el layout/estructura actual de la página (eso es trabajo de E).

## Tipografía — Poppins self-hosted (todo el proyecto)

Se elimina el `@import url(...)` a Google Fonts en `styles.css`, `terminal.css`, `capture-face.css`. `welcome.blade.php` (Instrument Sans/Bunny) no se toca — está fuera de alcance, sin ruta que lo sirva. Se instala `@fontsource/poppins` (paquete npm que distribuye los `.woff2` de Poppins ya subseteados, sin depender de una descarga manual) con los pesos 400/500/600/700 — cubre el superset que las vistas actuales piden hoy, con la excepción del peso 900 que `terminal.css` pedía de más sin uso confirmado; si al implementar se encuentra un uso real de peso 900 en el CSS de componentes, se agrega ese peso también (`@fontsource/poppins` lo distribuye igual). `tokens.css` importa los CSS del paquete (`@import '@fontsource/poppins/400.css';` etc.), que declaran los `@font-face` con `font-display: swap` apuntando a los `.woff2` — Vite resuelve esos `url()` relativos a `node_modules` y los copia a `public/build/assets/` con hash de contenido, igual que cualquier otro asset.

Al servirse desde Vite (mismo origen que el resto del bundle), quedan cubiertos automáticamente por el patrón `CACHE_FIRST_PATTERNS` existente en `public/sw.js` (`/build/assets/*`), sin necesidad de tocar el service worker para este punto específico — resuelve el problema real de offline sin trabajo adicional en el SW más allá de lo descrito en la sección siguiente.

**Panel Filament — de Bunny Fonts a self-hosted:** Filament v3 soporta `LocalFontProvider` (`vendor/filament/filament/src/FontProviders/LocalFontProvider.php`) para servir una fuente propia en vez de ir a un CDN. Se crea un entrypoint adicional `resources/css/shared/fonts.css` (solo los `@import` de `@fontsource/poppins`, sin el resto de `tokens.css`) agregado a `vite.config.js`, y en `AdminPanelProvider.php:31` se cambia:

```php
// Antes
->font('Poppins')

// Después
->font('Poppins', url: \Illuminate\Support\Facades\Vite::asset('resources/css/shared/fonts.css'), provider: \Filament\FontProviders\LocalFontProvider::class)
```

`tokens.css` importa este mismo `fonts.css` (en vez de repetir los `@import` de fontsource) para no declarar los `@font-face` dos veces.

## Isotipo y producción de íconos

**Diseño aprobado** (definido visualmente durante el brainstorm, ver mockups en `.superpowers/brainstorm/1557-1788789746/content/` — directorio gitignorado, no persiste como asset): monograma "N" geométrico de trazo grueso en negativo, contenedor "rounded square" (no circular, no de trazo fino — variantes descartadas), fondo `#0d9488` (teal-600), símbolo `#f0fdfa` (teal-50).

**Deliverables de producción**, todos generados a partir del SVG maestro, bajo `public/icons/`:
- `favicon.ico` (reemplaza el actual, roto/0 bytes)
- `favicon.svg`
- `apple-touch-icon.png` (180×180)
- `icon-192.png` / `icon-512.png` (variante **any** — diseño completo, uso en navegador/escritorio)
- `icon-192-maskable.png` / `icon-512-maskable.png` (variante **maskable** — símbolo recentrado dentro de la zona segura circular validada durante el brainstorm, teal a sangre completa, para el recorte adaptativo de íconos de Android)

Estos archivos se referencian desde el `<head>` de las 4 vistas públicas y del layout de Filament (favicon), y quedan listos para que B (instalabilidad PWA) los use en los manifests sin trabajo de diseño adicional en ese momento.

## Service worker — ajustes de caché

En `public/sw.js`:
- Se agrega `/icons/*` a `CACHE_FIRST_PATTERNS` (para que el ícono esté disponible offline en el launcher tras instalar, aunque la instalación en sí sea trabajo de B).
- Se agrega `/images/*` a `CACHE_FIRST_PATTERNS` (hoy `default-avatar.png`, usado como fallback de foto en `terminal.js:619`, queda fuera del cache-first).
- Se incrementa `CACHE_VERSION` de `'nominapp-attendance-v1'` a `'nominapp-attendance-v2'` para invalidar el caché viejo en los terminales ya desplegados en producción. Acorde a lo decidido: no se requiere una migración de cero-pérdida estricta para eventos en cola de IndexedDB (que vive fuera de Cache API, no se ve afectada por este bump) — alcanza con desplegar en una ventana de bajo uso y monitorear después.

## Limpieza de código muerto

Se eliminan por completo `resources/css/employees/capture-face.css` y `resources/css/enrollments/capture-face.css` (1102 líneas totales) — confirmado que ningún blade ni `vite.config.js` los referencia; ambos wrappers (`employees/capture-face.blade.php`, `enrollments/capture-face.blade.php`) ya fijan `$css = 'resources/css/shared/capture-face.css'` explícitamente.

## Testing / verificación

Sin lógica de negocio nueva (cambio de CSS, assets estáticos y fuentes) — no se agregan tests Pest. Verificación manual:
1. Lighthouse (Chrome DevTools) sobre las 4 vistas públicas + `/admin` — chequeo de performance/best-practices, y que no haya requests bloqueantes a dominios externos de fuentes.
2. Confirmar visualmente en cada una de las 4 vistas + `/admin` que la tipografía y colores se ven coherentes entre sí (mismo teal, misma fuente).
3. Confirmar que el favicon aparece correctamente en la pestaña del navegador en las 5 superficies.
4. En dispositivo real (celular + tablet, ya disponibles): confirmar que `/marcar` y `/terminal` cargan y se ven correctamente tras el cambio de fuente/tokens, sin regresión visual evidente.
5. Con conexión cortada tras una carga previa (DevTools → Network → Offline, o modo avión real en el dispositivo): confirmar que la tipografía Poppins se sigue viendo (no cae a system font), validando que el self-hosting resolvió el problema original.

## Archivos tocados (resumen)

**Nuevos:**
- `resources/css/shared/tokens.css`
- `resources/css/shared/fonts.css`
- `resources/css/attendances/device-link.css`
- `public/icons/*` (favicon + set de íconos)

**Modificados:**
- `resources/css/app.css`, `resources/css/attendances/styles.css`, `resources/css/attendances/terminal.css`, `resources/css/shared/capture-face.css` (import de tokens.css, retención solo de estilos específicos)
- `resources/views/attendances/device-link.blade.php` (remueve `<style>` inline, agrega `@vite` del nuevo CSS)
- `public/sw.js` (`CACHE_FIRST_PATTERNS` + bump de `CACHE_VERSION`)
- `vite.config.js` (nuevos entrypoints `shared/fonts.css` y `attendances/device-link.css`)
- `app/Providers/Filament/AdminPanelProvider.php` (`->font()` con `LocalFontProvider`, referencia al nuevo favicon)
- `package.json` (nueva dependencia npm `@fontsource/poppins`)

**No se toca:** `resources/views/welcome.blade.php` (fuera de alcance, sin ruta que lo sirva).

**Eliminados:**
- `resources/css/employees/capture-face.css`
- `resources/css/enrollments/capture-face.css`

## Riesgo y despliegue

Bajo riesgo — no toca lógica de marcación de asistencia ni el motor offline (matcher/queue/db). El único punto que requiere atención en producción es el bump de `CACHE_VERSION` en `public/sw.js`, que invalida el caché de shell/assets en los terminales ya desplegados; se despliega en ventana de bajo uso según lo acordado, sin necesidad de mecanismo de rollback especial más allá del revert de código estándar ya definido para toda la iniciativa.
