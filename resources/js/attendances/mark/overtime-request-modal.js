/**
 * @fileoverview Modal «Pedir horas extra» del celular (/marcar): el empleado pide autorización anticipada a su jefe
 * (o regulariza las horas extra de hoy) y ve el estado de sus pedidos recientes. Todo se pinta con `textContent`.
 * Requiere conexión: un pedido no tiene resolución offline.
 */

import { fetchOvertimeRequests, submitOvertimeRequest } from '../mobile-offline/sync.js';

/** Motivos de la lista del servidor, de objeto `{clave: etiqueta}` a la lista del selector. */
export function reasonsToOptions(reasons) {
    if (!reasons || typeof reasons !== 'object') return [];

    return Object.entries(reasons).map(([key, label]) => ({ key, label: String(label) }));
}

/**
 * Arma el cuerpo del pedido desde los valores del formulario; descarta lo vacío.
 * @param {{date: string, hours: string, reason: string, notes?: string, regularization?: boolean}} values
 */
export function buildRequestPayload(values) {
    const payload = {
        date_from: values.date,
        max_hours: Number(String(values.hours).replace(',', '.')),
        reason: values.reason,
    };
    const notes = (values.notes || '').trim();
    if (notes) payload.notes = notes;
    if (values.regularization) payload.regularization = true;

    return payload;
}

/** Fecha local de hoy como AAAA-MM-DD. */
export function todayLocal(now = new Date()) {
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');

    return `${now.getFullYear()}-${month}-${day}`;
}

/** Dibuja la lista de pedidos recientes. */
function renderRequests(listEl, requests) {
    listEl.replaceChildren(...requests.map((request) => {
        const li = document.createElement('li');
        li.className = 'my-events-item';

        const left = document.createElement('span');
        left.className = 'my-events-item-type';
        left.textContent = `${request.range} · hasta ${request.max_hours} h`;

        const right = document.createElement('span');
        right.className = 'my-events-item-time';
        right.textContent = request.status_label + (request.rejection_reason ? ` (${request.rejection_reason})` : '');

        li.append(left, right);

        return li;
    }));
}

/**
 * Abre el modal. Con `regularization` el pedido es por las horas extra de hoy ya hechas.
 * @param {{regularization?: boolean}} [options]
 */
export async function openOvertimeRequestModal({ regularization = false } = {}) {
    const modal = document.getElementById('overtimeRequestModal');
    const form = document.getElementById('overtimeRequestForm');
    if (!modal || !form) return;

    const notice = document.getElementById('overtimeRequestNotice');
    const message = document.getElementById('overtimeRequestMessage');
    const listEl = document.getElementById('overtimeRequestList');
    const reasonEl = document.getElementById('overtimeRequestReason');
    const dateEl = document.getElementById('overtimeRequestDate');

    form.dataset.regularization = regularization ? '1' : '0';
    if (notice) {
        notice.textContent = regularization
            ? 'Hoy hizo horas extra sin un pedido previo. Puede pedir que se aprueben.'
            : 'El pedido se envía a su jefe para su aprobación.';
    }
    if (message) message.textContent = '';
    if (dateEl) {
        dateEl.value = todayLocal();
        dateEl.min = regularization ? todayLocal() : todayLocal();
        dateEl.readOnly = regularization;
    }

    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('modal-open');

    try {
        const data = await fetchOvertimeRequests();
        if (reasonEl) {
            reasonEl.replaceChildren(...reasonsToOptions(data.reasons).map(({ key, label }) => {
                const option = document.createElement('option');
                option.value = key;
                option.textContent = label;

                return option;
            }));
        }
        if (listEl) renderRequests(listEl, data.requests || []);
    } catch (error) {
        if (message) message.textContent = 'No se pudo consultar: se necesita conexión.';
    }
}

/** Cierra el modal. */
export function closeOvertimeRequestModal() {
    const modal = document.getElementById('overtimeRequestModal');
    if (!modal) return;

    modal.classList.add('hidden');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('modal-open');
}

/** Envía el formulario del modal y muestra el resultado. */
export async function submitOvertimeRequestForm() {
    const form = document.getElementById('overtimeRequestForm');
    const message = document.getElementById('overtimeRequestMessage');
    const listEl = document.getElementById('overtimeRequestList');
    if (!form || !message) return;

    const payload = buildRequestPayload({
        date: document.getElementById('overtimeRequestDate')?.value || '',
        hours: document.getElementById('overtimeRequestHours')?.value || '',
        reason: document.getElementById('overtimeRequestReason')?.value || '',
        notes: document.getElementById('overtimeRequestNotes')?.value || '',
        regularization: form.dataset.regularization === '1',
    });

    message.textContent = 'Enviando...';

    try {
        const result = await submitOvertimeRequest(payload);
        message.textContent = result.message || (result.ok ? 'Pedido enviado.' : 'No se pudo enviar el pedido.');
        if (result.ok) {
            form.reset();
            const refreshed = await fetchOvertimeRequests();
            if (listEl) renderRequests(listEl, refreshed.requests || []);
        }
    } catch (error) {
        message.textContent = 'No se pudo enviar: se necesita conexión.';
    }
}

/**
 * Tras una salida con horas extra, ofrece regularizar si hoy no tiene un pedido aprobado. Nunca lanza.
 * @returns {Promise<void>}
 */
export async function offerOvertimeRegularization() {
    try {
        const data = await fetchOvertimeRequests();
        if (data.ok && !data.covered_today) await openOvertimeRequestModal({ regularization: true });
    } catch (error) {
        // Sin conexión no se ofrece nada: la salida ya quedó registrada.
    }
}
