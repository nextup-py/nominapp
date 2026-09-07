/**
 * =============================================================================
 * TERMINAL.JS — BÚSQUEDA MANUAL POR CI
 * =============================================================================
 *
 * @fileoverview Fallback cuando el reconocimiento facial falla varias veces
 * seguidas: el empleado se identifica por CI en vez de rostro. Extraído de
 * terminal.js como parte de su descomposición en módulos más chicos — mismo
 * comportamiento que el código original.
 *
 * NO reemplaza la verificación facial: elegir un candidato acá solo acota
 * identifyEmployee() a esa única persona (`terminalState.manualCandidate`,
 * que sigue viviendo en terminal.js) — la cámara sigue activa y el empleado
 * igual tiene que superar el umbral de distancia normal contra ese
 * descriptor específico antes de que se registre cualquier marcación.
 *
 * `initManualSearch()` cablea los listeners globales (botón abrir/cancelar,
 * click en backdrop, input de búsqueda) una sola vez y recibe de terminal.js
 * el callback `onSelect` — mutar `terminalState` y llamar a `updateStatus()`
 * son responsabilidad de terminal.js, no del módulo.
 */

import { getCachedEmployees } from '../terminal-offline/db.js';

const MAX_MANUAL_SEARCH_RESULTS = 8;

/** @type {((employee: object) => void)|null} */
let onSelectCallback = null;

/** Muestra el enlace "Buscar por CI" (aparece tras varios fallos de reconocimiento seguidos). */
export function showManualSearchLink() {
    const btnManualSearch = document.getElementById("btnManualSearch");
    if (btnManualSearch) btnManualSearch.classList.remove("hidden");
}

/** Oculta el enlace "Buscar por CI". */
export function hideManualSearchLink() {
    const btnManualSearch = document.getElementById("btnManualSearch");
    if (btnManualSearch) btnManualSearch.classList.add("hidden");
}

/** Abre el overlay de búsqueda manual, limpio y con foco en el input. */
export function openManualSearch() {
    const manualSearchOverlay = document.getElementById("manualSearchOverlay");
    const manualSearchInput = document.getElementById("manualSearchInput");

    if (!manualSearchOverlay) return;
    manualSearchOverlay.classList.remove("hidden");
    if (manualSearchInput) {
        manualSearchInput.value = "";
        manualSearchInput.focus();
    }
    renderManualSearchResults("");
}

/** Cierra el overlay de búsqueda manual. */
export function closeManualSearch() {
    const manualSearchOverlay = document.getElementById("manualSearchOverlay");
    if (manualSearchOverlay) manualSearchOverlay.classList.add("hidden");
}

/**
 * Filtra los empleados cacheados por CI y renderiza los resultados.
 * @param {string} query
 * @returns {Promise<void>}
 */
export async function renderManualSearchResults(query) {
    const manualSearchResults = document.getElementById("manualSearchResults");
    const manualSearchEmpty = document.getElementById("manualSearchEmpty");

    if (!manualSearchResults) return;
    manualSearchResults.innerHTML = "";

    const digits = (query || "").trim();
    if (digits.length < 2) {
        if (manualSearchEmpty) manualSearchEmpty.classList.add("hidden");
        return;
    }

    const candidates = await getCachedEmployees();
    const matches = candidates
        .filter((employee) => employee.ci && String(employee.ci).includes(digits))
        .slice(0, MAX_MANUAL_SEARCH_RESULTS);

    if (manualSearchEmpty) manualSearchEmpty.classList.toggle("hidden", matches.length > 0);

    matches.forEach((employee) => {
        const fullName = `${employee.first_name || ""} ${employee.last_name || ""}`.trim() || "Empleado";
        const item = document.createElement("button");
        item.type = "button";
        item.className = "manual-search-result";
        item.setAttribute("role", "option");
        item.innerHTML = `
            <img src="${employee.photo_thumbnail || "/images/default-avatar.png"}" alt="" aria-hidden="true">
            <span>
                <span class="manual-search-result-name">${fullName}</span><br>
                <span class="manual-search-result-ci">CI: ${employee.ci}</span>
            </span>
        `;
        item.addEventListener("click", () => selectManualCandidate(employee));
        manualSearchResults.appendChild(item);
    });
}

/**
 * Elige un candidato de la búsqueda manual: cierra el overlay y delega en
 * `onSelect` (mutar terminalState.manualCandidate/consecutiveFailures y
 * actualizar el texto de estado — responsabilidad de terminal.js).
 * @param {object} employee
 */
export function selectManualCandidate(employee) {
    hideManualSearchLink();
    closeManualSearch();
    onSelectCallback?.(employee);
}

/**
 * Cablea los listeners globales de la búsqueda manual (una sola vez).
 * @param {{onSelect?: (employee: object) => void}} callbacks
 */
export function initManualSearch({ onSelect } = {}) {
    onSelectCallback = onSelect ?? null;

    const btnManualSearch = document.getElementById("btnManualSearch");
    const btnManualSearchCancel = document.getElementById("btnManualSearchCancel");
    const manualSearchOverlay = document.getElementById("manualSearchOverlay");
    const manualSearchInput = document.getElementById("manualSearchInput");

    if (btnManualSearch) {
        btnManualSearch.addEventListener("click", openManualSearch);
    }
    if (btnManualSearchCancel) {
        btnManualSearchCancel.addEventListener("click", closeManualSearch);
    }
    if (manualSearchOverlay) {
        manualSearchOverlay.addEventListener("click", (event) => {
            if (event.target === manualSearchOverlay) closeManualSearch();
        });
    }
    if (manualSearchInput) {
        manualSearchInput.addEventListener("input", (event) => {
            renderManualSearchResults(event.target.value);
        });
    }
}
