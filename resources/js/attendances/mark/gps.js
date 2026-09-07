/**
 * =============================================================================
 * MARK.JS — GEOLOCALIZACIÓN (banner, mini-mapa, solicitud en segundo plano/manual)
 * =============================================================================
 *
 * @fileoverview Obtiene y muestra la ubicación GPS requerida para marcar
 * asistencia: banner inline de error, mini-mapa (Leaflet) con el pulso de
 * ubicación, contador regresivo durante la solicitud, y los dos flujos de
 * pedido (`requestGPSBackground()` al cargar la página / tras un error de
 * paso 2, `requestGPSManual()` como reintento explícito del usuario).
 * Extraído de mark.js como parte de su descomposición en módulos más chicos
 * — mismo comportamiento que el código original.
 *
 * El módulo no conoce `state` (el objeto de estado del wizard vive en
 * mark.js y lo usan muchas otras partes del archivo) — en su lugar,
 * `initGps()` recibe `onLocation` (mark.js hace `state.location = coords`) y
 * `checkEnableMark` como callbacks, cableados una sola vez junto con los dos
 * botones de reintento GPS (mini-mapa y banner inline).
 */

import L from 'leaflet';
import { markUserInteracted } from '../../shared/audio-feedback.js';
import { showErrorModal } from './error-modal.js';

/** @type {L.Map|null} */
let locationMap = null;

/** @type {L.Marker|null} */
let locationMarker = null;

/** @type {number|null} Último código de error de geolocalización (1=denegado, 2=no disponible, 3=timeout) */
let lastGpsErrorCode = null;

/** @type {((coords: {lat: number, lng: number}) => void)|null} */
let onLocationCallback = null;

/** @type {(() => void)|null} */
let checkEnableMarkCallback = null;

const pulseIcon = L.divIcon({
    className: '',
    html: '<div class="map-pulse-marker"><div class="map-pulse-ring"></div><div class="map-pulse-dot"></div></div>',
    iconSize: [28, 28],
    iconAnchor: [14, 14],
});

/** @returns {number|null} */
export function getLastGpsErrorCode() {
    return lastGpsErrorCode;
}

/** @param {string} message */
export function showGPSBanner(message) {
    const gpsBanner = document.getElementById("gpsBanner");
    const gpsBannerText = document.getElementById("gpsBannerText");
    if (!gpsBanner || !gpsBannerText) return;
    gpsBannerText.textContent = message;
    gpsBanner.classList.remove("hidden");
}

/** Oculta el banner inline de error GPS. */
export function hideGPSBanner() {
    const gpsBanner = document.getElementById("gpsBanner");
    if (gpsBanner) gpsBanner.classList.add("hidden");
}

/**
 * Muestra/actualiza el mini-mapa con el pulso de ubicación en las coordenadas dadas.
 * @param {number} lat
 * @param {number} lng
 */
export function showLocationMap(lat, lng) {
    const locationMapEl = document.getElementById("locationMap");
    const btnGeoRetry = document.getElementById("btnGeoRetry");
    const locationCoords = document.getElementById("locationCoords");

    if (!locationMapEl) return;
    locationMapEl.classList.remove("hidden");
    locationMapEl.removeAttribute("aria-hidden");
    if (btnGeoRetry) btnGeoRetry.classList.remove("hidden");
    // El mapa hace las coordenadas redundantes — ocultar el span
    if (locationCoords) locationCoords.classList.add("hidden");

    setTimeout(() => {
        if (!locationMap) {
            locationMap = L.map(locationMapEl, {
                zoomControl: false,
                attributionControl: false,
                dragging: false,
                scrollWheelZoom: false,
                doubleClickZoom: false,
                touchZoom: false,
            }).setView([lat, lng], 16);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
            }).addTo(locationMap);
            locationMarker = L.marker([lat, lng], { icon: pulseIcon }).addTo(locationMap);
            setTimeout(() => locationMap?.invalidateSize(), 50);
        } else {
            locationMap.setView([lat, lng], 16);
            locationMarker.setLatLng([lat, lng]);
            locationMap.invalidateSize();
        }
    }, 120);
}

/**
 * Muestra un contador regresivo en locationStatus durante la solicitud GPS.
 * @param {number} [totalSec] - Segundos totales del timeout GPS
 * @returns {function} Función para detener el contador
 */
export function startGPSProgress(totalSec = 10) {
    const locationStatus = document.getElementById("locationStatus");
    let remaining = totalSec;
    const dots = ["·", "··", "···"];
    let dotIdx = 0;

    const tick = () => {
        if (locationStatus) {
            locationStatus.textContent = `Obteniendo GPS ${dots[dotIdx % 3]} ${remaining}s`;
        }
        dotIdx++;
        remaining--;
    };
    tick(); // mostrar inmediatamente
    const id = setInterval(tick, 1000);
    return () => clearInterval(id);
}

/**
 * Solicita la ubicación GPS en segundo plano (al cargar la página, o como
 * reintento desde el modal de error de paso 2). No bloquea al usuario con un
 * modal propio — usa el banner inline (`showGPSBanner()`) si falla.
 * @param {() => void} [onSuccess]
 * @param {() => void} [onError]
 */
export function requestGPSBackground(onSuccess, onError) {
    const locationStatus = document.getElementById("locationStatus");
    const locationCoords = document.getElementById("locationCoords");

    if (!navigator.geolocation) {
        lastGpsErrorCode = null;
        if (locationStatus) locationStatus.textContent = "GPS no disponible en este dispositivo";
        onError?.();
        return;
    }

    const stopProgress = startGPSProgress(10);

    navigator.geolocation.getCurrentPosition(
        (pos) => {
            stopProgress();
            lastGpsErrorCode = null;
            hideGPSBanner();
            onLocationCallback?.({ lat: pos.coords.latitude, lng: pos.coords.longitude });
            if (locationStatus) locationStatus.textContent = "Ubicación obtenida";
            if (locationCoords) {
                locationCoords.textContent = `${pos.coords.latitude.toFixed(4)}, ${pos.coords.longitude.toFixed(4)}`;
            }
            showLocationMap(pos.coords.latitude, pos.coords.longitude);
            checkEnableMarkCallback?.();
            onSuccess?.();
        },
        (err) => {
            stopProgress();
            lastGpsErrorCode = err.code;
            const msgs = {
                1: "Ubicación denegada. Active el permiso en su navegador y toque Reintentar.",
                2: "No se pudo obtener la ubicación. Verifique que el GPS esté activo.",
                3: "Tiempo de espera agotado al obtener la ubicación.",
            };
            const msg = msgs[err.code] || "No se pudo obtener la ubicación.";
            if (locationStatus) locationStatus.textContent = msg;
            showGPSBanner(msg);
            console.warn("[Warn]", "GPS en segundo plano:", msg);
            onError?.();
        },
        { timeout: 10000, maximumAge: 60000, enableHighAccuracy: true }
    );
}

/** Solicita la ubicación GPS manualmente (botón del mini-mapa, o reintento desde el modal de error). */
export function requestGPSManual() {
    const locationStatus = document.getElementById("locationStatus");
    const locationCoords = document.getElementById("locationCoords");

    markUserInteracted();
    if (!navigator.geolocation) {
        const errorMsg = "Este dispositivo no puede obtener la ubicación GPS. Si el problema persiste, contacte a RRHH.";
        console.log("[Status]", errorMsg);
        showErrorModal("Ubicación no disponible", errorMsg);
        return;
    }

    console.log("[Status]", "Solicitando ubicación GPS...");
    const stopProgress = startGPSProgress(10);

    navigator.geolocation.getCurrentPosition(
        (pos) => {
            stopProgress();
            onLocationCallback?.({ lat: pos.coords.latitude, lng: pos.coords.longitude });
            if (locationStatus) locationStatus.textContent = "Ubicación obtenida";
            if (locationCoords) {
                locationCoords.textContent = `${pos.coords.latitude.toFixed(4)}, ${pos.coords.longitude.toFixed(4)}`;
            }
            showLocationMap(pos.coords.latitude, pos.coords.longitude);
            checkEnableMarkCallback?.();
            console.log("[Status]", "Ubicación obtenida correctamente");
        },
        (err) => {
            stopProgress();
            console.error("[Error]", "Error de geolocalización:", err);
            let errorMsg = "";

            switch (err.code) {
                case 1:
                    errorMsg = "Permiso de ubicación denegado. Habilite el GPS en su navegador e intente nuevamente.";
                    break;
                case 2:
                    errorMsg = "No se pudo obtener la ubicación. Verifique que el GPS esté activado e intente nuevamente.";
                    break;
                case 3:
                    errorMsg = "La obtención de ubicación tardó demasiado. Intente nuevamente.";
                    break;
                default:
                    errorMsg = "No se pudo obtener la ubicación. Por favor, intente nuevamente.";
                    break;
            }

            showErrorModal("Ubicación no disponible", errorMsg, requestGPSManual);
        },
        {
            timeout: 10000,
            maximumAge: 60000,
            enableHighAccuracy: true,
        }
    );
}

/**
 * Resetea la fila de ubicación (texto, coordenadas, mini-mapa) a su estado
 * inicial — mismo bloque que se repetía en returnToSplash(), resetSystem() y
 * transitionToStep1() de mark.js. No toca `state.location`: eso lo decide
 * quien llama, junto con el resto del reset de `state`.
 */
export function resetLocationUi() {
    const locationStatus = document.getElementById("locationStatus");
    const locationCoords = document.getElementById("locationCoords");
    const locationMapEl = document.getElementById("locationMap");
    const btnGeoRetry = document.getElementById("btnGeoRetry");

    if (locationStatus) locationStatus.textContent = "Solicitando ubicación...";
    if (locationCoords) { locationCoords.textContent = ""; locationCoords.classList.remove("hidden"); }
    if (locationMapEl) {
        locationMapEl.classList.add("hidden");
        locationMapEl.setAttribute("aria-hidden", "true");
    }
    if (btnGeoRetry) btnGeoRetry.classList.add("hidden");
    if (locationMap) {
        locationMap.remove();
        locationMap = null;
        locationMarker = null;
    }
}

/**
 * Cablea los botones de reintento GPS (mini-mapa y banner inline) una sola
 * vez y registra los callbacks que el módulo necesita del resto de mark.js.
 * @param {{onLocation?: (coords: {lat: number, lng: number}) => void, checkEnableMark?: () => void}} callbacks
 */
export function initGps({ onLocation, checkEnableMark } = {}) {
    onLocationCallback = onLocation ?? null;
    checkEnableMarkCallback = checkEnableMark ?? null;

    const btnGeoRetry = document.getElementById("btnGeoRetry");
    if (btnGeoRetry) {
        btnGeoRetry.addEventListener("click", () => requestGPSManual());
    }

    const gpsBannerRetry = document.getElementById("gpsBannerRetry");
    if (gpsBannerRetry) {
        gpsBannerRetry.addEventListener("click", () => {
            hideGPSBanner();
            requestGPSBackground();
        });
    }
}
