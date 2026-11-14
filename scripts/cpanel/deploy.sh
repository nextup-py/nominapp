#!/usr/bin/env bash
# deploy.sh - Actualiza (o restaura) Nominapp en un servidor con cPanel.
#
# Uso:   deploy.sh [verificar|actualizar|restaurar] [opciones]
#
#   verificar    Solo revisa que todo este listo; no cambia nada.
#   actualizar   Respaldo, mantenimiento, git pull, composer, build, migraciones, caches y vuelta en linea.
#   restaurar    Vuelve al codigo y al build anteriores (y a la base con --db).
#
# Opciones:
#   --yes        No hacer pausas de confirmacion (solo para quien ya sabe lo que hace).
#   --sin-build  No instalar un build nuevo (para releases sin cambios de JS/CSS/Blade).
#   --db         Con "restaurar": restaurar tambien la base desde el ultimo respaldo.
#   --help       Muestra esta ayuda.
#
# Configuracion: archivo de variables fuera del repositorio (por defecto ~/deploy-nominapp.conf, o la ruta en
# DEPLOY_CONF). Ver scripts/cpanel/deploy.conf.example y docs/deploy-cpanel.md.
#
# El archivo va en ASCII a proposito: las terminales de algunos hosting rompen las tildes.

set -Eeuo pipefail

# El script se copia a un temporal antes de empezar: "git pull" puede reemplazar este mismo archivo y bash lee
# los scripts a medida que los ejecuta, asi que sin la copia podria ejecutarse una mezcla de dos versiones.
if [ -z "${NOMINAPP_DEPLOY_COPY:-}" ]; then
    NOMINAPP_DEPLOY_TMP="$(mktemp "${TMPDIR:-/tmp}/nominapp-deploy.XXXXXX")"
    cp "$0" "$NOMINAPP_DEPLOY_TMP"
    NOMINAPP_DEPLOY_COPY=1 NOMINAPP_DEPLOY_TMP="$NOMINAPP_DEPLOY_TMP" NOMINAPP_DEPLOY_ORIG="$0" exec bash "$NOMINAPP_DEPLOY_TMP" "$@"
fi

MODE="actualizar"
ASSUME_YES=0
SKIP_BUILD=0
RESTORE_DB=0
STAGE="init"          # init -> A (reversible) -> B (con migraciones) -> C (verificacion)
SITE_DOWN=0
STARTED_AT="$(date +%Y%m%d-%H%M%S)"

# ---------------------------------------------------------------- utilidades

say()  { printf '\n== %s\n' "$*"; }
ok()   { printf '   [ok] %s\n' "$*"; }
warn() { printf '   [aviso] %s\n' "$*"; }
die()  { printf '\n[ERROR] %s\n' "$*" >&2; exit 1; }

cleanup_tmp() { if [ -n "${NOMINAPP_DEPLOY_TMP:-}" ]; then rm -f "$NOMINAPP_DEPLOY_TMP"; fi; }

# Cualquier salida con error (set -e, die, Ctrl+C) pasa por aca: si el sitio quedo en mantenimiento se recupera
# o se explica como seguir. Se usa EXIT y no ERR a proposito: ERR se hereda en las sustituciones $(...) y dispararia
# avisos falsos con comandos que fallan "a proposito" (por ejemplo un grep sin coincidencias).
on_exit() {
    local rc=$?
    trap - EXIT
    cleanup_tmp
    if [ "$rc" -ne 0 ]; then failure_notice "$rc"; fi
    exit "$rc"
}
trap on_exit EXIT
trap 'exit 130' INT TERM

confirm() {
    [ "$ASSUME_YES" -eq 1 ] && return 0
    local answer
    read -r -p "   $1 [s/N] " answer
    case "$answer" in s|S|si|SI|Si|y|Y) return 0 ;; *) return 1 ;; esac
}

# Lee una variable del .env de la aplicacion sin mostrarla.
env_get() {
    local value
    value="$(grep -E "^$1=" "$APP_DIR/.env" 2>/dev/null | tail -n1 | cut -d= -f2-)" || true
    value="${value%\"}"; value="${value#\"}"; value="${value%\'}"; value="${value#\'}"
    printf '%s' "$value"
}

run() { printf '   + %s\n' "$*"; "$@"; }

usage() { sed -n '2,22p' "${NOMINAPP_DEPLOY_ORIG:-$0}" | sed 's/^# \{0,1\}//'; }

# ---------------------------------------------------------------- argumentos

for arg in "$@"; do
    case "$arg" in
        verificar|actualizar|restaurar) MODE="$arg" ;;
        --yes) ASSUME_YES=1 ;;
        --sin-build) SKIP_BUILD=1 ;;
        --db) RESTORE_DB=1 ;;
        --help|-h) usage; exit 0 ;;
        *) die "Argumento desconocido: $arg (usar --help)" ;;
    esac
done

# ---------------------------------------------------------------- configuracion

CONF="${DEPLOY_CONF:-$HOME/deploy-nominapp.conf}"
[ -f "$CONF" ] || die "No existe el archivo de configuracion: $CONF. Copiar scripts/cpanel/deploy.conf.example y completarlo."
# shellcheck disable=SC1090
. "$CONF"

: "${APP_DIR:?Falta APP_DIR en $CONF}"
: "${PHP_BIN:?Falta PHP_BIN en $CONF}"
BRANCH="${BRANCH:-main}"
BACKUP_DIR="${BACKUP_DIR:-$HOME/respaldos-deploy}"
BACKUP_KEEP="${BACKUP_KEEP:-5}"
STATE_DIR="${STATE_DIR:-$HOME/deploy-state}"
APP_URL="${APP_URL:-}"
COMPOSER_BIN="${COMPOSER_BIN:-$(command -v composer || true)}"
ALLOWED_LOCAL="${ALLOWED_LOCAL:-public/.htaccess public/.user.ini}"
BUILD_FILE="${BUILD_FILE:-}"

[ -d "$APP_DIR/.git" ] || die "APP_DIR no es un repositorio git: $APP_DIR"
cd "$APP_DIR"

if [ -z "$BUILD_FILE" ]; then
    for candidate in "$HOME/build.tar.gz" "$HOME/build.zip"; do
        [ -f "$candidate" ] && { BUILD_FILE="$candidate"; break; }
    done
fi

# ---------------------------------------------------------------- base de datos

db_args() {
    DB_ARGS=(-u"$(env_get DB_USERNAME)")
    local host port socket
    host="$(env_get DB_HOST)"; port="$(env_get DB_PORT)"; socket="$(env_get DB_SOCKET)"
    if [ -n "$socket" ]; then DB_ARGS+=(--socket="$socket")
    elif [ -n "$host" ] && [ "$host" != "localhost" ]; then
        DB_ARGS+=(-h "$host")
        if [ -n "$port" ]; then DB_ARGS+=(-P "$port"); fi
    fi
}

db_default_engine() {
    db_args
    MYSQL_PWD="$(env_get DB_PASSWORD)" mysql "${DB_ARGS[@]}" -N -e 'select @@default_storage_engine' 2>/dev/null || true
}

# ---------------------------------------------------------------- verificacion previa

preflight() {
    say "Verificacion previa"
    local problems=0

    "$PHP_BIN" -v >/dev/null 2>&1 && ok "PHP: $("$PHP_BIN" -r 'echo PHP_VERSION;')" || { warn "No se puede ejecutar PHP_BIN=$PHP_BIN"; problems=$((problems+1)); }
    [ -n "$COMPOSER_BIN" ] && ok "Composer: $COMPOSER_BIN" || { warn "No se encontro composer (definir COMPOSER_BIN en $CONF)"; problems=$((problems+1)); }
    command -v mysqldump >/dev/null 2>&1 && ok "mysqldump disponible" || { warn "Falta mysqldump"; problems=$((problems+1)); }
    [ -f .env ] && ok ".env presente" || { warn "No hay .env en $APP_DIR"; problems=$((problems+1)); }

    local dirty
    dirty="$(git status --porcelain -uno | awk '{print $2}')"
    local unexpected=""
    for f in $dirty; do
        case " $ALLOWED_LOCAL " in *" $f "*) ;; *) unexpected="$unexpected $f" ;; esac
    done
    if [ -n "$unexpected" ]; then
        warn "Hay cambios locales sin commitear que bloquearian el git pull:$unexpected"
        warn "Si ya estan resueltos en main: git checkout -- <archivo>. Si son propios del servidor, agregarlos a ALLOWED_LOCAL."
        problems=$((problems+1))
    else
        ok "Sin cambios locales inesperados"
    fi

    local default_engine env_engine
    default_engine="$(db_default_engine)"
    env_engine="$(env_get DB_ENGINE)"
    if [ -z "$default_engine" ]; then
        warn "No pude consultar el motor de tablas por defecto de MySQL (revisar credenciales del .env)"
        problems=$((problems+1))
    elif [ "$(printf '%s' "$default_engine" | tr '[:upper:]' '[:lower:]')" = "myisam" ] \
        && [ "$(printf '%s' "$env_engine" | tr '[:upper:]' '[:lower:]')" != "innodb" ]; then
        warn "El motor por defecto es MyISAM y el .env no tiene DB_ENGINE=InnoDB: las migraciones fallaran (Specified key was too long)."
        warn "Agregar DB_ENGINE=InnoDB al .env y volver a verificar."
        problems=$((problems+1))
    else
        ok "Motor de tablas: ${env_engine:-$default_engine}"
    fi

    local free_kb
    free_kb="$(df -Pk "$APP_DIR" | awk 'NR==2 {print $4}')"
    [ "${free_kb:-0}" -gt 512000 ] && ok "Espacio libre: $((free_kb/1024)) MB" || warn "Poco espacio libre ($((${free_kb:-0}/1024)) MB): el respaldo y el build pueden fallar"

    if [ "$SKIP_BUILD" -eq 1 ]; then
        ok "Sin build nuevo (--sin-build)"
    elif [ -n "$BUILD_FILE" ] && [ -f "$BUILD_FILE" ]; then
        case "$BUILD_FILE" in
            *.zip) command -v unzip >/dev/null 2>&1 && ok "Build a instalar: $BUILD_FILE" || { warn "Falta unzip para $BUILD_FILE"; problems=$((problems+1)); } ;;
            *) ok "Build a instalar: $BUILD_FILE" ;;
        esac
    else
        warn "No hay build para instalar (~/build.tar.gz o ~/build.zip). Subirlo con Preparar-Build.ps1, o usar --sin-build si el release no cambia JS/CSS/Blade."
        problems=$((problems+1))
    fi

    git fetch -q origin "$BRANCH" || { warn "git fetch fallo (red o Deploy Key)"; problems=$((problems+1)); }
    local pending
    pending="$(git log --oneline "HEAD..origin/$BRANCH" 2>/dev/null | wc -l | tr -d ' ')"
    ok "Commits por aplicar desde origin/$BRANCH: $pending"
    [ "$pending" -gt 0 ] && git log --oneline "HEAD..origin/$BRANCH" | head -15 | sed 's/^/        /'

    if [ "$problems" -gt 0 ]; then
        die "La verificacion encontro $problems problema(s). Resolverlos antes de actualizar."
    fi
    ok "Todo listo para actualizar"
}

# ---------------------------------------------------------------- respaldo y estado

take_backup() {
    say "Respaldo de la base"
    mkdir -p "$BACKUP_DIR" "$STATE_DIR"
    BACKUP_FILE="$BACKUP_DIR/respaldo-$STARTED_AT.sql.gz"
    db_args
    MYSQL_PWD="$(env_get DB_PASSWORD)" mysqldump "${DB_ARGS[@]}" --single-transaction --routines "$(env_get DB_DATABASE)" | gzip > "$BACKUP_FILE"
    [ -s "$BACKUP_FILE" ] || die "El respaldo quedo vacio: $BACKUP_FILE"
    ok "$(ls -lh "$BACKUP_FILE" | awk '{print $5, $9}')"

    PREV_HASH="$(git rev-parse HEAD)"
    rm -rf "$STATE_DIR/build-anterior"
    [ -d public/build ] && cp -r public/build "$STATE_DIR/build-anterior"
    {
        echo "PREV_HASH=$PREV_HASH"
        echo "BACKUP_FILE=$BACKUP_FILE"
        echo "STARTED_AT=$STARTED_AT"
    } > "$STATE_DIR/ultimo.env"
    ok "Commit anterior guardado: ${PREV_HASH:0:9}"
}

rotate_backups() {
    # shellcheck disable=SC2012
    ls -1t "$BACKUP_DIR"/respaldo-*.sql.gz 2>/dev/null | tail -n +$((BACKUP_KEEP + 1)) | while read -r old; do rm -f "$old"; done
    ok "Respaldos conservados: los ultimos $BACKUP_KEEP"
}

# ---------------------------------------------------------------- build

install_build() {
    say "Instalacion del build"
    local tmp src
    tmp="$(mktemp -d "$HOME/build-tmp.XXXXXX")"
    case "$BUILD_FILE" in
        *.zip) unzip -q "$BUILD_FILE" -d "$tmp" ;;
        *) tar -xzf "$BUILD_FILE" -C "$tmp" ;;
    esac
    if [ -d "$tmp/build" ]; then src="$tmp/build"; else src="$tmp"; fi
    if [ ! -f "$src/manifest.json" ] && [ ! -f "$src/.vite/manifest.json" ]; then
        rm -rf "$tmp"
        die "El build no tiene manifest.json: $BUILD_FILE"
    fi
    rm -rf public/build
    mv "$src" public/build
    rm -rf "$tmp"
    ok "public/build instalado"

    local build_commit head_commit
    build_commit="$(cat public/build/commit.txt 2>/dev/null || true)"
    head_commit="$(git rev-parse HEAD)"
    if [ -z "$build_commit" ]; then
        warn "El build no trae commit.txt (no se puede comprobar que corresponda a este commit)"
    elif [ "$build_commit" != "$head_commit" ]; then
        warn "El build se genero desde ${build_commit:0:9} y el codigo desplegado es ${head_commit:0:9}"
        confirm "Continuar de todos modos?" || die "Cancelado: regenerar el build desde $BRANCH con Preparar-Build.ps1"
    else
        ok "El build corresponde al commit ${head_commit:0:9}"
    fi
}

# ---------------------------------------------------------------- recuperacion

revert_code() {
    [ -f "$STATE_DIR/ultimo.env" ] || return 1
    # shellcheck disable=SC1090
    . "$STATE_DIR/ultimo.env"
    git reset -q --hard "$PREV_HASH"
    if [ -d "$STATE_DIR/build-anterior" ]; then
        rm -rf public/build
        cp -r "$STATE_DIR/build-anterior" public/build
    fi
    "$PHP_BIN" "$COMPOSER_BIN" install --no-dev --optimize-autoloader --no-interaction >/dev/null 2>&1 || true
}

failure_notice() {
    local code="$1"
    printf '\nEl proceso se detuvo (codigo %s, etapa %s).\n' "$code" "$STAGE" >&2
    if [ "$MODE" = "actualizar" ] && [ "$SITE_DOWN" -eq 1 ]; then
        SITE_DOWN=0
        if [ "$STAGE" = "A" ]; then
            printf 'Aun no se toco la base: vuelvo al codigo anterior y subo el sitio.\n' >&2
            if revert_code; then
                "$PHP_BIN" artisan optimize:clear >/dev/null 2>&1 || true
                "$PHP_BIN" artisan up || true
                printf 'Sitio en linea con la version anterior. Revisar el error y volver a intentar.\n' >&2
            else
                printf 'No pude volver atras solo. El sitio sigue en mantenimiento: ejecutar "deploy.sh restaurar".\n' >&2
            fi
        else
            printf 'El sitio queda EN MANTENIMIENTO a proposito (la base pudo cambiar).\n' >&2
            printf 'Opciones: corregir el error y repetir el paso, o volver atras con:\n' >&2
            printf '   deploy.sh restaurar            (solo el codigo)\n' >&2
            printf '   deploy.sh restaurar --db       (codigo y base; pierde lo registrado desde el respaldo)\n' >&2
        fi
    fi
}

# ---------------------------------------------------------------- actualizar

do_update() {
    preflight
    take_backup
    local log_file log_lines
    log_file="$(ls -t storage/logs/laravel*.log 2>/dev/null | head -1 || true)"
    log_lines=0; [ -n "$log_file" ] && log_lines="$(wc -l < "$log_file")"

    say "Resumen"
    echo "   Commit actual:  $(git log --oneline -1)"
    echo "   Se aplicara:    $(git log --oneline -1 "origin/$BRANCH")"
    echo "   Respaldo:       $BACKUP_FILE"
    confirm "Poner el sitio en mantenimiento y actualizar?" || die "Cancelado. No se cambio nada (el respaldo ya quedo hecho)."

    say "Mantenimiento"
    run "$PHP_BIN" artisan down
    SITE_DOWN=1

    STAGE="A"
    say "Codigo"
    run git pull --ff-only origin "$BRANCH"
    run "$PHP_BIN" "$COMPOSER_BIN" install --no-dev --optimize-autoloader --no-interaction
    if [ "$SKIP_BUILD" -eq 0 ]; then install_build; fi

    STAGE="B"
    say "Migraciones"
    run "$PHP_BIN" artisan config:clear
    "$PHP_BIN" artisan migrate:status --pending 2>/dev/null | sed 's/^/   /' || true
    confirm "Ejecutar las migraciones?" || die "Cancelado antes de migrar. El sitio sigue en mantenimiento: restaurar o reintentar."
    run "$PHP_BIN" artisan migrate --force

    say "Caches y assets"
    run "$PHP_BIN" artisan livewire:publish --assets
    run "$PHP_BIN" artisan filament:assets
    run "$PHP_BIN" artisan optimize:clear
    run "$PHP_BIN" artisan optimize
    run "$PHP_BIN" artisan filament:optimize
    run "$PHP_BIN" artisan queue:restart

    if [ "$ASSUME_YES" -eq 0 ]; then
        say "Pausa antes de volver en linea"
        echo "   Si este release pide seeders u otros comandos (por ejemplo PermissionSeeder), ejecutarlos ahora"
        echo "   en otra terminal. El sitio sigue en mantenimiento."
        read -r -p "   Enter para volver en linea... " _
    fi

    say "En linea"
    run "$PHP_BIN" artisan up
    SITE_DOWN=0

    STAGE="C"
    post_checks "$log_file" "$log_lines"
    rotate_backups

    say "Listo"
    echo "   Version:   $(git log --oneline -1)"
    echo "   Respaldo:  $BACKUP_FILE"
    echo "   Volver atras: ${NOMINAPP_DEPLOY_ORIG:-deploy.sh} restaurar   (o restaurar --db si cambio el esquema)"
}

# ---------------------------------------------------------------- verificaciones posteriores

post_checks() {
    local log_file="$1" log_lines="$2"
    say "Verificacion posterior"

    if [ -f public/build/manifest.json ] || [ -f public/build/.vite/manifest.json ]; then ok "Build presente"; else warn "No hay manifest.json en public/build"; fi

    if [ -n "$APP_URL" ] && command -v curl >/dev/null 2>&1; then
        local code
        code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$APP_URL/up" || true)"
        [ "$code" = "200" ] && ok "$APP_URL/up responde 200" || warn "$APP_URL/up respondio '$code'"
    else
        warn "APP_URL no definido en $CONF: no se probo la respuesta HTTP"
    fi

    if [ -f storage/logs/cron.log ]; then
        local age
        age="$(( $(date +%s) - $(stat -c %Y storage/logs/cron.log) ))"
        [ "$age" -lt 600 ] && ok "El programador corrio hace $((age/60)) min" || warn "storage/logs/cron.log no se actualiza hace $((age/60)) min (revisar el cron del programador)"
    else
        warn "No existe storage/logs/cron.log"
    fi

    local new_log new_errors=0
    new_log="$(ls -t storage/logs/laravel*.log 2>/dev/null | head -1 || true)"
    if [ -n "$new_log" ]; then
        if [ "$new_log" = "$log_file" ]; then
            new_errors="$(tail -n +$((log_lines + 1)) "$new_log" | grep -c '\.ERROR' || true)"
        else
            new_errors="$(grep -c '\.ERROR' "$new_log" || true)"
        fi
        if [ "$new_errors" -eq 0 ]; then ok "Sin errores nuevos en el log"
        else warn "$new_errors error(es) nuevo(s) en $new_log (las horas del log usan APP_TIMEZONE, no la del servidor):"; \
            tail -n +$((log_lines + 1)) "$new_log" | grep '\.ERROR' | tail -3 | cut -c1-200 | sed 's/^/        /'; fi
    fi
    echo "   Falta la prueba manual: abrir el panel y recorrer los listados con pestanas (una consulta que falla solo se ve al usarla)."
}

# ---------------------------------------------------------------- restaurar

do_restore() {
    [ -f "$STATE_DIR/ultimo.env" ] || die "No hay estado guardado en $STATE_DIR (no se hizo ninguna actualizacion con este script)."
    # shellcheck disable=SC1090
    . "$STATE_DIR/ultimo.env"
    say "Restaurar"
    echo "   Volver al commit: ${PREV_HASH:0:9}  (actual: $(git rev-parse --short HEAD))"
    echo "   Build anterior:   $([ -d "$STATE_DIR/build-anterior" ] && echo si || echo no)"
    if [ "$RESTORE_DB" -eq 1 ]; then
        echo "   Base:             $BACKUP_FILE"
        warn "Restaurar la base pierde TODO lo registrado desde el respaldo (marcaciones, solicitudes, etc.)."
    fi
    confirm "Continuar?" || die "Cancelado."

    run "$PHP_BIN" artisan down || true
    revert_code || die "No pude restaurar el codigo."
    ok "Codigo y build anteriores restaurados"

    if [ "$RESTORE_DB" -eq 1 ]; then
        [ -s "$BACKUP_FILE" ] || die "No existe el respaldo $BACKUP_FILE"
        local typed
        read -r -p "   Escribir RESTAURAR para reemplazar la base: " typed
        [ "$typed" = "RESTAURAR" ] || die "Cancelado; el codigo ya fue restaurado, la base no."
        db_args
        gunzip -c "$BACKUP_FILE" | MYSQL_PWD="$(env_get DB_PASSWORD)" mysql "${DB_ARGS[@]}" "$(env_get DB_DATABASE)"
        ok "Base restaurada desde $BACKUP_FILE"
    fi

    run "$PHP_BIN" artisan optimize:clear
    run "$PHP_BIN" artisan optimize
    run "$PHP_BIN" artisan filament:optimize
    run "$PHP_BIN" artisan queue:restart
    run "$PHP_BIN" artisan up
    say "Listo: version ${PREV_HASH:0:9} en linea"
}

# ---------------------------------------------------------------- principal

case "$MODE" in
    verificar)  preflight ;;
    actualizar) do_update ;;
    restaurar)  do_restore ;;
esac
