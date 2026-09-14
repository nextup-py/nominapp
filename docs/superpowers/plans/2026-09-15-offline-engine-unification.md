# Sub-proyecto C: Unificación del motor offline (mobile/terminal) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Cubrir con tests de caracterización los 8 archivos del motor offline (terminal + mobile, hoy en cero tests) y extraer a `resources/js/attendances/offline-shared/` las 3 piezas mecánicamente idénticas entre ambos (`matcher.js`, el CRUD genérico de `db.js`, el chunking de `queue.js`), sin cambiar el modelo de dominio N-empleados-vs-1-empleado ni ningún comportamiento observable.

**Architecture:** Cada tarea de test escribe contra el código **actual, sin modificar**, usando `fake-indexeddb` para `db.js` y mocks (`vi.mock`) para las dependencias de `queue.js`/`sync.js` — mismo patrón dependency-injected ya establecido en el proyecto. Recién con las 8 suites pasando se extraen los 3 módulos compartidos, uno por uno, re-corriendo la MISMA suite de caracterización contra el código extraído como prueba de cero cambio de comportamiento.

**Tech Stack:** Vitest, `fake-indexeddb` (nueva dependencia de desarrollo), `idb`.

**Spec:** docs/superpowers/specs/2026-09-15-offline-engine-unification-design.md

## Global Constraints

- Cero cambio de comportamiento visible — este sub-proyecto es tests + refactor, no features.
- El modelo N-empleados (terminal) vs. 1-empleado-implícito (mobile) NO se unifica — solo el código mecánicamente idéntico.
- `sync.js` no se toca ni se extrae — las diferencias ahí son genuinas (endpoints, `TerminalAuthError`/`MobileAuthError`, forma de datos).
- `DB_VERSION` de terminal (2) y mobile (1) no se sincronizan — asimetría preexistente, se mantiene.
- Cada extracción debe ir precedida por sus tests de caracterización YA COMMITEADOS contra el código sin modificar — nunca se extrae sin la red de seguridad puesta primero.
- Tras cada extracción, la MISMA suite de caracterización (mismas aserciones) debe seguir pasando sin cambios — cualquier aserción que necesite cambiar es una señal de alarma a investigar, no un ajuste de rutina.
- Todo archivo nuevo lleva JSDoc completo y comentario de cabecera `@fileoverview`.
- Comandos de test: `npx vitest run <archivo>` (scoped), `npx vitest run` (suite completa), `php artisan test --compact` (no debería verse afectado — este sub-proyecto es JS puro).

---

### Task 1: `fake-indexeddb` + tests de caracterización de `terminal-offline/db.js`

**Files:**
- Modify: `package.json` (agregar `fake-indexeddb` a `devDependencies`)
- Test: `resources/js/attendances/terminal-offline/db.test.js`

**Interfaces:**
- Consumes: ninguno de tareas previas — primera tarea del plan.
- Produces: el patrón de setup con `fake-indexeddb` (`import 'fake-indexeddb/auto'` + `vi.resetModules()` + `import()` dinámico + `indexedDB.deleteDatabase(...)` en `afterEach`) que las Tareas 2 y 6 reutilizan.

- [ ] **Step 1: Instalar `fake-indexeddb`**

```bash
npm install --save-dev fake-indexeddb@^6.2.5
```

- [ ] **Step 2: Escribir los tests de caracterización**

```js
// resources/js/attendances/terminal-offline/db.test.js
/**
 * @fileoverview Tests de caracterización de terminal-offline/db.js contra su
 * implementación ACTUAL, sin modificar — red de seguridad para la extracción
 * de generic-db.js (sub-proyecto C). Usa fake-indexeddb para ejercitar el
 * schema y las operaciones reales de IndexedDB, no mocks.
 *
 * `getDb()` cachea la conexión en una variable de módulo (`dbPromise`) — para
 * que cada test arranque con una base limpia, se resetea el registro de
 * módulos de Vitest (`vi.resetModules()`) y se reimporta el módulo en cada
 * `beforeEach`, y se borra la base física en `afterEach`.
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import 'fake-indexeddb/auto';

let db;

beforeEach(async () => {
    vi.resetModules();
    db = await import('./db.js');
});

afterEach(async () => {
    await new Promise((resolve) => {
        const req = indexedDB.deleteDatabase('nominapp-terminal');
        req.onsuccess = resolve;
        req.onerror = resolve;
        req.onblocked = resolve;
    });
});

describe('getDb', () => {
    it('crea las 5 stores con los keyPath correctos', async () => {
        const database = await db.getDb();
        expect(Array.from(database.objectStoreNames).sort()).toEqual(
            ['employee_status_cache', 'employees_cache', 'outbound_events', 'sync_log', 'terminal_meta'].sort(),
        );
        expect(database.transaction('employees_cache').objectStore('employees_cache').keyPath).toBe('id');
        expect(database.transaction('outbound_events').objectStore('outbound_events').keyPath).toBe('client_event_id');
        expect(database.transaction('employee_status_cache').objectStore('employee_status_cache').keyPath).toBe('employee_id');
    });
});

describe('getMeta / setMeta', () => {
    it('hace round-trip de un valor', async () => {
        await db.setMeta('api_token', 'tok-123');
        expect(await db.getMeta('api_token')).toBe('tok-123');
    });

    it('retorna undefined para una key inexistente', async () => {
        expect(await db.getMeta('no_existe')).toBeUndefined();
    });
});

describe('migrateTokenFromLocalStorage', () => {
    afterEach(() => {
        localStorage.clear();
    });

    it('migra token/id/code desde localStorage y los borra', async () => {
        localStorage.setItem('nominapp_terminal_token', 'legacy-tok');
        localStorage.setItem('nominapp_terminal_id', '42');
        localStorage.setItem('nominapp_terminal_code', 'ABC123');

        await db.migrateTokenFromLocalStorage();

        expect(await db.getMeta('api_token')).toBe('legacy-tok');
        expect(await db.getMeta('terminal_id')).toBe(42);
        expect(await db.getMeta('terminal_code')).toBe('ABC123');
        expect(localStorage.getItem('nominapp_terminal_token')).toBeNull();
        expect(localStorage.getItem('nominapp_terminal_id')).toBeNull();
        expect(localStorage.getItem('nominapp_terminal_code')).toBeNull();
    });

    it('es no-op si no hay token legacy en localStorage', async () => {
        await db.migrateTokenFromLocalStorage();
        expect(await db.getMeta('api_token')).toBeUndefined();
    });
});

describe('clearTerminalState', () => {
    it('vacía terminal_meta, employees_cache, outbound_events y employee_status_cache', async () => {
        await db.setMeta('api_token', 'tok');
        await db.applyEmployeesDelta([{ id: 1, first_name: 'Juan', face_descriptor: Array(128).fill(0) }], []);
        await db.queueEvent({ client_event_id: 'e1', employee_id: 1, event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z', date: '2026-01-01' });
        await db.setEmployeeStatusCache(1, { last_event: 'check_in', last_event_time: '10:00', allowed_events: [] });

        await db.clearTerminalState();

        expect(await db.getMeta('api_token')).toBeUndefined();
        expect(await db.getCachedEmployees()).toEqual([]);
        expect(await db.getPendingEvents()).toEqual([]);
        expect(await db.getEmployeeStatusCache(1)).toBeUndefined();
    });
});

describe('logSync', () => {
    it('agrega una entrada con type/ok/detail/at', async () => {
        await db.logSync('heartbeat', true, null);
        const database = await db.getDb();
        const entries = await database.getAll('sync_log');
        expect(entries).toHaveLength(1);
        expect(entries[0]).toMatchObject({ type: 'heartbeat', ok: true, detail: null });
        expect(typeof entries[0].at).toBe('number');
    });

    it('recorta el historial a las últimas 50 entradas', async () => {
        for (let i = 0; i < 55; i++) {
            await db.logSync('heartbeat', true, `intento ${i}`);
        }
        const database = await db.getDb();
        const entries = await database.getAll('sync_log');
        expect(entries).toHaveLength(50);
        expect(entries[0].detail).toBe('intento 5'); // las primeras 5 se recortaron
        expect(entries[49].detail).toBe('intento 54');
    });
});

describe('caché de empleados', () => {
    it('applyEmployeesDelta hace upsert de los modificados y borra los tombstones', async () => {
        await db.applyEmployeesDelta([
            { id: 1, first_name: 'Juan', face_descriptor: Array(128).fill(0) },
            { id: 2, first_name: 'Ana', face_descriptor: Array(128).fill(0) },
        ], []);
        expect(await db.countCachedEmployees()).toBe(2);

        await db.applyEmployeesDelta([{ id: 1, first_name: 'Juan Actualizado', face_descriptor: Array(128).fill(0) }], [2]);

        const employees = await db.getCachedEmployees();
        expect(employees).toHaveLength(1);
        expect(employees[0].first_name).toBe('Juan Actualizado');
        expect(await db.getCachedEmployee(2)).toBeUndefined();
    });

    it('applyBreakFlags actualiza has_scheduled_break en empleados ya cacheados e ignora ids ausentes', async () => {
        await db.applyEmployeesDelta([{ id: 1, first_name: 'Juan', face_descriptor: Array(128).fill(0) }], []);

        await db.applyBreakFlags({ 1: false, 999: true }); // 999 no está cacheado, se ignora

        const employee = await db.getCachedEmployee(1);
        expect(employee.has_scheduled_break).toBe(false);
        expect(await db.getCachedEmployee(999)).toBeUndefined();
    });
});

describe('cola de eventos (outbound_events)', () => {
    it('queueEvent guarda el evento con status pending, attempts 0 y created_at', async () => {
        await db.queueEvent({ client_event_id: 'e1', employee_id: 1, event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z', date: '2026-01-01' });
        const [event] = await db.getPendingEvents();
        expect(event).toMatchObject({ client_event_id: 'e1', employee_id: 1, event_type: 'check_in', status: 'pending', attempts: 0 });
        expect(typeof event.created_at).toBe('number');
    });

    it('getPendingEvents excluye los marcados conflict', async () => {
        await db.queueEvent({ client_event_id: 'e1', employee_id: 1, event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z', date: '2026-01-01' });
        await db.markQueuedEventConflict('e1', 'rechazado');
        expect(await db.getPendingEvents()).toEqual([]);
    });

    it('getEventsForEmployeeOnDate filtra por empleado y fecha', async () => {
        await db.queueEvent({ client_event_id: 'e1', employee_id: 1, event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z', date: '2026-01-01' });
        await db.queueEvent({ client_event_id: 'e2', employee_id: 2, event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z', date: '2026-01-01' });
        await db.queueEvent({ client_event_id: 'e3', employee_id: 1, event_type: 'check_in', recorded_at: '2026-01-02T10:00:00Z', date: '2026-01-02' });

        const result = await db.getEventsForEmployeeOnDate(1, '2026-01-01');
        expect(result.map((e) => e.client_event_id)).toEqual(['e1']);
    });

    it('removeQueuedEvent elimina el evento del store', async () => {
        await db.queueEvent({ client_event_id: 'e1', employee_id: 1, event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z', date: '2026-01-01' });
        await db.removeQueuedEvent('e1');
        expect(await db.getPendingEvents()).toEqual([]);
    });

    it('markQueuedEventConflict marca status conflict con el mensaje del servidor', async () => {
        await db.queueEvent({ client_event_id: 'e1', employee_id: 1, event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z', date: '2026-01-01' });
        await db.markQueuedEventConflict('e1', 'Secuencia inválida');
        expect(await db.countConflictEvents()).toBe(1);
    });

    it('incrementQueuedEventAttempts suma 1 al contador existente', async () => {
        await db.queueEvent({ client_event_id: 'e1', employee_id: 1, event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z', date: '2026-01-01' });
        await db.incrementQueuedEventAttempts('e1');
        await db.incrementQueuedEventAttempts('e1');
        const [event] = await db.getPendingEvents();
        expect(event.attempts).toBe(2);
    });

    it('countPendingEvents y countConflictEvents cuentan correctamente', async () => {
        await db.queueEvent({ client_event_id: 'e1', employee_id: 1, event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z', date: '2026-01-01' });
        await db.queueEvent({ client_event_id: 'e2', employee_id: 1, event_type: 'check_out', recorded_at: '2026-01-01T18:00:00Z', date: '2026-01-01' });
        await db.markQueuedEventConflict('e2', 'rechazado');

        expect(await db.countPendingEvents()).toBe(1);
        expect(await db.countConflictEvents()).toBe(1);
    });
});

describe('employee_status_cache', () => {
    it('setEmployeeStatusCache / getEmployeeStatusCache hacen round-trip', async () => {
        await db.setEmployeeStatusCache(1, { last_event: 'check_in', last_event_time: '08:00', allowed_events: ['break_start', 'check_out'] });
        const cached = await db.getEmployeeStatusCache(1);
        expect(cached).toMatchObject({ employee_id: 1, last_event: 'check_in', last_event_time: '08:00', allowed_events: ['break_start', 'check_out'] });
        expect(typeof cached.cached_at).toBe('number');
    });
});
```

- [ ] **Step 3: Correr los tests**

Run: `npx vitest run resources/js/attendances/terminal-offline/db.test.js`
Expected: PASS (todos los tests, contra el código actual sin modificar)

- [ ] **Step 4: Commit**

```bash
git add package.json package-lock.json resources/js/attendances/terminal-offline/db.test.js
git commit -m "test: add characterization tests for terminal-offline/db.js"
```

---

### Task 2: Tests de caracterización de `mobile-offline/db.js`

**Files:**
- Test: `resources/js/attendances/mobile-offline/db.test.js`

**Interfaces:**
- Consumes: `fake-indexeddb` (Tarea 1, ya instalado), mismo patrón de setup.
- Produces: nada consumido por tareas posteriores.

- [ ] **Step 1: Escribir los tests de caracterización**

```js
// resources/js/attendances/mobile-offline/db.test.js
/**
 * @fileoverview Tests de caracterización de mobile-offline/db.js contra su
 * implementación ACTUAL, sin modificar — mismo propósito y patrón de setup
 * que terminal-offline/db.test.js (sub-proyecto C).
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import 'fake-indexeddb/auto';

let db;

beforeEach(async () => {
    vi.resetModules();
    db = await import('./db.js');
});

afterEach(async () => {
    await new Promise((resolve) => {
        const req = indexedDB.deleteDatabase('nominapp-mobile');
        req.onsuccess = resolve;
        req.onerror = resolve;
        req.onblocked = resolve;
    });
});

describe('getDb', () => {
    it('crea las 4 stores con los keyPath correctos', async () => {
        const database = await db.getDb();
        expect(Array.from(database.objectStoreNames).sort()).toEqual(
            ['employee_status_cache', 'mobile_meta', 'outbound_events', 'sync_log'].sort(),
        );
        expect(database.transaction('outbound_events').objectStore('outbound_events').keyPath).toBe('client_event_id');
        expect(database.transaction('employee_status_cache').objectStore('employee_status_cache').keyPath).toBe('employee_id');
    });
});

describe('getMeta / setMeta', () => {
    it('hace round-trip de un valor', async () => {
        await db.setMeta('api_token', 'tok-123');
        expect(await db.getMeta('api_token')).toBe('tok-123');
    });

    it('retorna undefined para una key inexistente', async () => {
        expect(await db.getMeta('no_existe')).toBeUndefined();
    });
});

describe('migrateTokenFromLocalStorage', () => {
    afterEach(() => {
        localStorage.clear();
    });

    it('migra token/employee_id desde localStorage y los borra', async () => {
        localStorage.setItem('nominapp_mobile_token', 'legacy-tok');
        localStorage.setItem('nominapp_mobile_employee_id', '7');

        await db.migrateTokenFromLocalStorage();

        expect(await db.getMeta('api_token')).toBe('legacy-tok');
        expect(await db.getMeta('employee_id')).toBe(7);
        expect(localStorage.getItem('nominapp_mobile_token')).toBeNull();
        expect(localStorage.getItem('nominapp_mobile_employee_id')).toBeNull();
    });

    it('es no-op si no hay token legacy en localStorage', async () => {
        await db.migrateTokenFromLocalStorage();
        expect(await db.getMeta('api_token')).toBeUndefined();
    });
});

describe('resetDb', () => {
    it('vacía mobile_meta, outbound_events, employee_status_cache y sync_log', async () => {
        await db.setMeta('api_token', 'tok');
        await db.queueEvent({ client_event_id: 'e1', employee_id: 1, event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z', date: '2026-01-01' });
        await db.setEmployeeStatusCache(1, { last_event: 'check_in', last_event_time: '10:00', allowed_events: [] });
        await db.logSync('heartbeat', true);

        await db.resetDb();

        expect(await db.getMeta('api_token')).toBeUndefined();
        expect(await db.getPendingEvents()).toEqual([]);
        expect(await db.getEmployeeStatusCache(1)).toBeUndefined();
        const database = await db.getDb();
        expect(await database.getAll('sync_log')).toEqual([]);
    });
});

describe('logSync', () => {
    it('agrega una entrada con type/ok/detail/at', async () => {
        await db.logSync('heartbeat', true, null);
        const database = await db.getDb();
        const entries = await database.getAll('sync_log');
        expect(entries).toHaveLength(1);
        expect(entries[0]).toMatchObject({ type: 'heartbeat', ok: true, detail: null });
    });

    it('recorta el historial a las últimas 50 entradas', async () => {
        for (let i = 0; i < 55; i++) {
            await db.logSync('heartbeat', true, `intento ${i}`);
        }
        const database = await db.getDb();
        const entries = await database.getAll('sync_log');
        expect(entries).toHaveLength(50);
        expect(entries[0].detail).toBe('intento 5');
    });
});

describe('own_employee', () => {
    it('setOwnEmployee guarda el empleado y también employee_id', async () => {
        await db.setOwnEmployee({ id: 5, first_name: 'Juan', last_name: 'Pérez', ci: '1234567', face_descriptor: Array(128).fill(0) });

        const own = await db.getOwnEmployee();
        expect(own).toMatchObject({ id: 5, first_name: 'Juan' });
        expect(await db.getMeta('employee_id')).toBe(5);
    });

    it('getOwnEmployee retorna undefined si nunca se seteó', async () => {
        expect(await db.getOwnEmployee()).toBeUndefined();
    });
});

describe('cola de eventos (outbound_events)', () => {
    it('queueEvent guarda el evento con status pending, attempts 0, created_at y location', async () => {
        await db.queueEvent({ client_event_id: 'e1', employee_id: 5, event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z', date: '2026-01-01', location: { lat: -25.3, lng: -57.6 } });
        const [event] = await db.getPendingEvents();
        expect(event).toMatchObject({ client_event_id: 'e1', status: 'pending', attempts: 0, location: { lat: -25.3, lng: -57.6 } });
    });

    it('getPendingEvents excluye los marcados conflict', async () => {
        await db.queueEvent({ client_event_id: 'e1', employee_id: 5, event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z', date: '2026-01-01' });
        await db.markQueuedEventConflict('e1', 'rechazado');
        expect(await db.getPendingEvents()).toEqual([]);
    });

    it('getEventsOnDate filtra solo por fecha (sin employeeId — un único empleado por dispositivo)', async () => {
        await db.queueEvent({ client_event_id: 'e1', employee_id: 5, event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z', date: '2026-01-01' });
        await db.queueEvent({ client_event_id: 'e2', employee_id: 5, event_type: 'check_out', recorded_at: '2026-01-02T18:00:00Z', date: '2026-01-02' });

        const result = await db.getEventsOnDate('2026-01-01');
        expect(result.map((e) => e.client_event_id)).toEqual(['e1']);
    });

    it('removeQueuedEvent elimina el evento del store', async () => {
        await db.queueEvent({ client_event_id: 'e1', employee_id: 5, event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z', date: '2026-01-01' });
        await db.removeQueuedEvent('e1');
        expect(await db.getPendingEvents()).toEqual([]);
    });

    it('markQueuedEventConflict marca status conflict con el mensaje del servidor', async () => {
        await db.queueEvent({ client_event_id: 'e1', employee_id: 5, event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z', date: '2026-01-01' });
        await db.markQueuedEventConflict('e1', 'Secuencia inválida');
        expect(await db.countConflictEvents()).toBe(1);
    });

    it('incrementQueuedEventAttempts suma 1 al contador existente', async () => {
        await db.queueEvent({ client_event_id: 'e1', employee_id: 5, event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z', date: '2026-01-01' });
        await db.incrementQueuedEventAttempts('e1');
        const [event] = await db.getPendingEvents();
        expect(event.attempts).toBe(1);
    });

    it('countPendingEvents y countConflictEvents cuentan correctamente', async () => {
        await db.queueEvent({ client_event_id: 'e1', employee_id: 5, event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z', date: '2026-01-01' });
        await db.queueEvent({ client_event_id: 'e2', employee_id: 5, event_type: 'check_out', recorded_at: '2026-01-01T18:00:00Z', date: '2026-01-01' });
        await db.markQueuedEventConflict('e2', 'rechazado');

        expect(await db.countPendingEvents()).toBe(1);
        expect(await db.countConflictEvents()).toBe(1);
    });

    it('dismissConflictEvents elimina solo los eventos en conflicto', async () => {
        await db.queueEvent({ client_event_id: 'e1', employee_id: 5, event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z', date: '2026-01-01' });
        await db.queueEvent({ client_event_id: 'e2', employee_id: 5, event_type: 'check_out', recorded_at: '2026-01-01T18:00:00Z', date: '2026-01-01' });
        await db.markQueuedEventConflict('e2', 'rechazado');

        await db.dismissConflictEvents();

        expect(await db.countConflictEvents()).toBe(0);
        expect(await db.countPendingEvents()).toBe(1);
    });
});

describe('employee_status_cache', () => {
    it('setEmployeeStatusCache / getEmployeeStatusCache hacen round-trip', async () => {
        await db.setEmployeeStatusCache(5, { last_event: 'check_in', last_event_time: '08:00', allowed_events: ['break_start', 'check_out'] });
        const cached = await db.getEmployeeStatusCache(5);
        expect(cached).toMatchObject({ employee_id: 5, last_event: 'check_in', last_event_time: '08:00' });
    });
});
```

- [ ] **Step 2: Correr los tests**

Run: `npx vitest run resources/js/attendances/mobile-offline/db.test.js`
Expected: PASS

- [ ] **Step 3: Commit**

```bash
git add resources/js/attendances/mobile-offline/db.test.js
git commit -m "test: add characterization tests for mobile-offline/db.js"
```

---

### Task 3: Tests de caracterización de `matcher.js` (terminal + mobile)

**Files:**
- Test: `resources/js/attendances/terminal-offline/matcher.test.js`
- Test: `resources/js/attendances/mobile-offline/matcher.test.js`

**Interfaces:**
- Consumes: ninguno — módulo sin dependencias (lógica pura).
- Produces: el contenido de estos tests se reutiliza tal cual (mismas aserciones) en la Tarea 6, apuntando al módulo extraído.

- [ ] **Step 1: Escribir los tests (mismo contenido para ambos archivos, ya que matcher.js es idéntico)**

```js
// resources/js/attendances/terminal-offline/matcher.test.js
// (mismo contenido en resources/js/attendances/mobile-offline/matcher.test.js,
// con el import ajustado a su propio matcher.js — matcher.js es idéntico en
// ambas carpetas, ver spec del sub-proyecto C)
/**
 * @fileoverview Tests de caracterización de matcher.js contra su
 * implementación ACTUAL, sin modificar — red de seguridad para la extracción
 * a offline-shared/matcher.js (sub-proyecto C).
 */
import { describe, it, expect } from 'vitest';
import { euclideanDistance, identifyEmployee } from './matcher.js';

function descriptor(fillValue) {
    return Array(128).fill(fillValue);
}

describe('euclideanDistance', () => {
    it('retorna 0 para descriptores idénticos', () => {
        expect(euclideanDistance(descriptor(0.5), descriptor(0.5))).toBe(0);
    });

    it('calcula la distancia euclidiana real entre dos descriptores', () => {
        const a = [0, 0, 0];
        const b = [3, 4, 0];
        // sqrt(3^2 + 4^2 + 0^2) = 5, pero euclideanDistance siempre itera 128 posiciones
        // (a[i]??0 - b[i]??0) — con arrays cortos, las posiciones faltantes valen 0.
        expect(euclideanDistance(a, b)).toBe(5);
    });

    it('trata posiciones faltantes como 0', () => {
        expect(euclideanDistance([], [])).toBe(0);
    });
});

describe('identifyEmployee', () => {
    it('retorna no_candidates si la lista está vacía', () => {
        const result = identifyEmployee(descriptor(0), [], 0.5, 0.1);
        expect(result).toEqual({ employee: null, distance: Infinity, reason: 'no_candidates' });
    });

    it('retorna no_candidates si candidates es null', () => {
        const result = identifyEmployee(descriptor(0), null, 0.5, 0.1);
        expect(result.reason).toBe('no_candidates');
    });

    it('identifica al candidato más cercano si está dentro del umbral y supera el gap', () => {
        const candidates = [
            { id: 1, face_descriptor: descriptor(0) },
            { id: 2, face_descriptor: descriptor(1) },
        ];
        const result = identifyEmployee(descriptor(0), candidates, 0.5, 0.1);
        expect(result.employee.id).toBe(1);
        expect(result.distance).toBe(0);
        expect(result.reason).toBeNull();
    });

    it('retorna no_match si el mejor candidato supera el umbral', () => {
        const candidates = [{ id: 1, face_descriptor: descriptor(5) }];
        const result = identifyEmployee(descriptor(0), candidates, 0.5, 0.1);
        expect(result.employee).toBeNull();
        expect(result.reason).toBe('no_match');
    });

    it('retorna ambiguous si el gap con el segundo candidato es menor al mínimo', () => {
        const candidates = [
            { id: 1, face_descriptor: Array(128).fill(0).map((_, i) => (i === 0 ? 0.1 : 0)) },
            { id: 2, face_descriptor: Array(128).fill(0).map((_, i) => (i === 0 ? 0.15 : 0)) },
        ];
        // liveDescriptor a distancia ~0.1 del candidato 1 y ~0.15 del candidato 2 — gap chico
        const live = Array(128).fill(0);
        const result = identifyEmployee(live, candidates, 0.5, 0.5);
        expect(result.employee).toBeNull();
        expect(result.reason).toBe('ambiguous');
    });

    it('ignora candidatos con face_descriptor inválido (no array de 128)', () => {
        const candidates = [
            { id: 1, face_descriptor: [1, 2, 3] }, // longitud inválida
            { id: 2, face_descriptor: descriptor(0) },
        ];
        const result = identifyEmployee(descriptor(0), candidates, 0.5, 0.1);
        expect(result.employee.id).toBe(2);
    });

    it('con un único candidato, no evalúa el gap (secondBestDist queda Infinity)', () => {
        const candidates = [{ id: 1, face_descriptor: descriptor(0) }];
        const result = identifyEmployee(descriptor(0), candidates, 0.5, 0.01);
        expect(result.employee.id).toBe(1);
        expect(result.reason).toBeNull();
    });
});
```

Copiar este mismo contenido a `resources/js/attendances/mobile-offline/matcher.test.js` sin cambios (el import `from './matcher.js'` ya apunta al matcher.js de esa misma carpeta).

- [ ] **Step 2: Correr ambos**

Run: `npx vitest run resources/js/attendances/terminal-offline/matcher.test.js resources/js/attendances/mobile-offline/matcher.test.js`
Expected: PASS (16 tests — 8 por archivo)

- [ ] **Step 3: Commit**

```bash
git add resources/js/attendances/terminal-offline/matcher.test.js resources/js/attendances/mobile-offline/matcher.test.js
git commit -m "test: add characterization tests for matcher.js (terminal + mobile)"
```

---

### Task 4: Tests de caracterización de `queue.js` (terminal + mobile)

**Files:**
- Test: `resources/js/attendances/terminal-offline/queue.test.js`
- Test: `resources/js/attendances/mobile-offline/queue.test.js`

**Interfaces:**
- Consumes: ninguno de tareas previas (mockea `db.js`/`sync.js` directamente, no usa `fake-indexeddb`).
- Produces: nada consumido por tareas posteriores (queue.js no se extrae completo, solo su chunking en la Tarea 7).

- [ ] **Step 1: Escribir los tests de `terminal-offline/queue.test.js`**

```js
// resources/js/attendances/terminal-offline/queue.test.js
/**
 * @fileoverview Tests de caracterización de terminal-offline/queue.js contra
 * su implementación ACTUAL, sin modificar — red de seguridad para la
 * extracción del chunking de flushQueue() a offline-shared/submit-in-chunks.js
 * (sub-proyecto C). db.js y sync.js van mockeados (vi.mock).
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('./db.js', () => ({
    getMeta: vi.fn(),
    queueEvent: vi.fn(),
    getPendingEvents: vi.fn(),
    getEventsForEmployeeOnDate: vi.fn(),
    getCachedEmployee: vi.fn(),
    removeQueuedEvent: vi.fn(),
    markQueuedEventConflict: vi.fn(),
    incrementQueuedEventAttempts: vi.fn(),
    countPendingEvents: vi.fn(),
    countConflictEvents: vi.fn(),
    getEmployeeStatusCache: vi.fn(),
    setEmployeeStatusCache: vi.fn(),
}));
vi.mock('./sync.js', () => ({
    submitEvents: vi.fn(),
    fetchEmployeeStatus: vi.fn(),
}));

import {
    getMeta, queueEvent, getPendingEvents, getEventsForEmployeeOnDate, getCachedEmployee,
    removeQueuedEvent, markQueuedEventConflict, incrementQueuedEventAttempts,
    countPendingEvents, getEmployeeStatusCache, setEmployeeStatusCache,
} from './db.js';
import { submitEvents, fetchEmployeeStatus } from './sync.js';
import { allowedNextEventTypes, resolveEmployeeStatus, getEmployeeStatus, enqueueMark, flushQueue } from './queue.js';

beforeEach(() => {
    vi.clearAllMocks();
    getMeta.mockResolvedValue(0); // server_clock_offset_ms por defecto
});

describe('allowedNextEventTypes', () => {
    it('sin evento previo, solo permite check_in', () => {
        expect(allowedNextEventTypes(null)).toEqual(['check_in']);
    });
    it('tras check_in, permite break_start y check_out', () => {
        expect(allowedNextEventTypes('check_in')).toEqual(['break_start', 'check_out']);
    });
    it('tras break_start, solo permite break_end', () => {
        expect(allowedNextEventTypes('break_start')).toEqual(['break_end']);
    });
    it('tras check_out, no permite nada más', () => {
        expect(allowedNextEventTypes('check_out')).toEqual([]);
    });
    it('con hasScheduledBreak=false, filtra break_start', () => {
        expect(allowedNextEventTypes('check_in', false)).toEqual(['check_out']);
    });
    it('con hasScheduledBreak=false, break_end sigue permitido (nunca se filtra)', () => {
        expect(allowedNextEventTypes('break_start', false)).toEqual(['break_end']);
    });
});

describe('resolveEmployeeStatus', () => {
    it('usa el caché del servidor si no hay eventos locales de hoy ni de ayer', async () => {
        getEmployeeStatusCache.mockResolvedValue({ last_event: 'check_in', last_event_time: '08:00' });
        getEventsForEmployeeOnDate.mockResolvedValue([]);
        getCachedEmployee.mockResolvedValue({ has_scheduled_break: true });

        const result = await resolveEmployeeStatus(1);

        expect(result.last_event).toBe('check_in');
        expect(result.allowed_events).toEqual(['break_start', 'check_out']);
    });

    it('prioriza los eventos de HOY encolados localmente sobre el caché del servidor', async () => {
        getEmployeeStatusCache.mockResolvedValue({ last_event: 'check_in', last_event_time: '08:00' });
        getEventsForEmployeeOnDate.mockImplementation((employeeId, date) => {
            if (date === '1970-01-01') { // "hoy" con Date.now()=0 en el test
                return Promise.resolve([{ status: 'pending', event_type: 'break_start', recorded_at: '1970-01-01T09:00:00Z' }]);
            }
            return Promise.resolve([]);
        });
        getCachedEmployee.mockResolvedValue({ has_scheduled_break: true });
        vi.useFakeTimers().setSystemTime(new Date('1970-01-01T12:00:00Z'));

        const result = await resolveEmployeeStatus(1);

        expect(result.last_event).toBe('break_start');
        vi.useRealTimers();
    });

    it('sin caché ni eventos de hoy, revisa si ayer quedó una jornada abierta (no check_out)', async () => {
        getEmployeeStatusCache.mockResolvedValue(undefined);
        getEventsForEmployeeOnDate.mockImplementation((employeeId, date) => {
            if (date === '1970-01-01') return Promise.resolve([{ status: 'pending', event_type: 'check_in', recorded_at: '1970-01-01T22:00:00Z' }]);
            return Promise.resolve([]); // hoy (1970-01-02): nada
        });
        getCachedEmployee.mockResolvedValue({ has_scheduled_break: true });
        vi.useFakeTimers().setSystemTime(new Date('1970-01-02T01:00:00Z'));

        const result = await resolveEmployeeStatus(1);

        expect(result.last_event).toBe('check_in'); // jornada nocturna de ayer sigue abierta
        vi.useRealTimers();
    });
});

describe('getEmployeeStatus', () => {
    it('consulta en línea, cachea y retorna el resultado', async () => {
        fetchEmployeeStatus.mockResolvedValue({ last_event: 'check_in', last_event_time: '08:00', allowed_events: ['check_out'] });

        const result = await getEmployeeStatus(1);

        expect(setEmployeeStatusCache).toHaveBeenCalledWith(1, expect.objectContaining({ last_event: 'check_in' }));
        expect(result.last_event).toBe('check_in');
    });

    it('cae a resolveEmployeeStatus si la consulta en línea falla', async () => {
        fetchEmployeeStatus.mockRejectedValue(new Error('network down'));
        getEmployeeStatusCache.mockResolvedValue(undefined);
        getEventsForEmployeeOnDate.mockResolvedValue([]);
        getCachedEmployee.mockResolvedValue({ has_scheduled_break: true });

        const result = await getEmployeeStatus(1);

        expect(result.last_event).toBeNull();
        expect(setEmployeeStatusCache).not.toHaveBeenCalled();
    });
});

describe('enqueueMark', () => {
    it('encola el evento y refresca el caché de estado con el nuevo evento', async () => {
        getCachedEmployee.mockResolvedValue({ has_scheduled_break: true });

        const result = await enqueueMark(1, 'check_in');

        expect(queueEvent).toHaveBeenCalledWith(expect.objectContaining({ employee_id: 1, event_type: 'check_in' }));
        expect(setEmployeeStatusCache).toHaveBeenCalledWith(1, expect.objectContaining({ last_event: 'check_in', allowed_events: ['break_start', 'check_out'] }));
        expect(result).toHaveProperty('client_event_id');
        expect(result).toHaveProperty('recorded_at');
    });
});

describe('flushQueue', () => {
    it('sin eventos pendientes, retorna de inmediato sin llamar a submitEvents', async () => {
        getPendingEvents.mockResolvedValue([]);
        const result = await flushQueue();
        expect(result).toEqual({ synced: 0, conflicts: 0, stillPending: 0, results: [] });
        expect(submitEvents).not.toHaveBeenCalled();
    });

    it('sincroniza eventos y los elimina del store cuando el servidor confirma synced/duplicate', async () => {
        getPendingEvents.mockResolvedValue([
            { client_event_id: 'e1', employee_id: 1, event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z' },
        ]);
        submitEvents.mockResolvedValue([{ client_event_id: 'e1', status: 'synced' }]);
        countPendingEvents.mockResolvedValue(0);

        const result = await flushQueue();

        expect(removeQueuedEvent).toHaveBeenCalledWith('e1');
        expect(result.synced).toBe(1);
    });

    it('marca conflict los eventos que el servidor rechaza', async () => {
        getPendingEvents.mockResolvedValue([
            { client_event_id: 'e1', employee_id: 1, event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z' },
        ]);
        submitEvents.mockResolvedValue([{ client_event_id: 'e1', status: 'rejected', message: 'Secuencia inválida' }]);
        countPendingEvents.mockResolvedValue(0);

        const result = await flushQueue();

        expect(markQueuedEventConflict).toHaveBeenCalledWith('e1', 'Secuencia inválida');
        expect(result.conflicts).toBe(1);
    });

    it('si un lote falla por red, ese lote y los siguientes quedan pending (incrementa attempts)', async () => {
        getPendingEvents.mockResolvedValue([
            { client_event_id: 'e1', employee_id: 1, event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z' },
        ]);
        submitEvents.mockRejectedValue(new Error('network down'));
        countPendingEvents.mockResolvedValue(1);

        const result = await flushQueue();

        expect(incrementQueuedEventAttempts).toHaveBeenCalledWith('e1');
        expect(result.stillPending).toBe(1);
        expect(removeQueuedEvent).not.toHaveBeenCalled();
    });

    it('parte la cola en lotes de a lo sumo 200 eventos', async () => {
        const pending = Array.from({ length: 250 }, (_, i) => ({ client_event_id: `e${i}`, employee_id: 1, event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z' }));
        getPendingEvents.mockResolvedValue(pending);
        submitEvents.mockImplementation((batch) => Promise.resolve(batch.map((e) => ({ client_event_id: e.client_event_id, status: 'synced' }))));
        countPendingEvents.mockResolvedValue(0);

        const result = await flushQueue();

        expect(submitEvents).toHaveBeenCalledTimes(2); // 200 + 50
        expect(submitEvents.mock.calls[0][0]).toHaveLength(200);
        expect(submitEvents.mock.calls[1][0]).toHaveLength(50);
        expect(result.synced).toBe(250);
    });

    it('no corre dos flush en simultáneo — el segundo retorna de inmediato', async () => {
        let resolveFirst;
        getPendingEvents.mockResolvedValue([{ client_event_id: 'e1', employee_id: 1, event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z' }]);
        submitEvents.mockImplementation(() => new Promise((resolve) => { resolveFirst = resolve; }));
        countPendingEvents.mockResolvedValue(1);

        const firstCall = flushQueue();
        const secondCall = flushQueue(); // arranca mientras el primero sigue en curso

        const secondResult = await secondCall;
        expect(secondResult.results).toEqual([]); // el segundo no reintentó nada, solo devolvió el estado actual

        resolveFirst([{ client_event_id: 'e1', status: 'synced' }]);
        await firstCall;
    });
});
```

- [ ] **Step 2: Escribir los tests de `mobile-offline/queue.test.js`**

```js
// resources/js/attendances/mobile-offline/queue.test.js
/**
 * @fileoverview Tests de caracterización de mobile-offline/queue.js contra su
 * implementación ACTUAL, sin modificar — mismo propósito que
 * terminal-offline/queue.test.js (sub-proyecto C).
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('./db.js', () => ({
    getMeta: vi.fn(),
    queueEvent: vi.fn(),
    getPendingEvents: vi.fn(),
    getEventsOnDate: vi.fn(),
    getOwnEmployee: vi.fn(),
    removeQueuedEvent: vi.fn(),
    markQueuedEventConflict: vi.fn(),
    incrementQueuedEventAttempts: vi.fn(),
    countPendingEvents: vi.fn(),
    countConflictEvents: vi.fn(),
    dismissConflictEvents: vi.fn(),
    getEmployeeStatusCache: vi.fn(),
    setEmployeeStatusCache: vi.fn(),
}));
vi.mock('./sync.js', () => {
    class MobileAuthError extends Error {}
    return { submitEvents: vi.fn(), fetchStatus: vi.fn(), MobileAuthError };
});

import {
    getMeta, queueEvent, getPendingEvents, getEventsOnDate, getOwnEmployee,
    removeQueuedEvent, markQueuedEventConflict, incrementQueuedEventAttempts,
    countPendingEvents, getEmployeeStatusCache, setEmployeeStatusCache,
} from './db.js';
import { submitEvents, fetchStatus, MobileAuthError } from './sync.js';
import { allowedNextEventTypes, resolveOwnStatus, getOwnStatus, enqueueMark, flushQueue } from './queue.js';

beforeEach(() => {
    vi.clearAllMocks();
    getMeta.mockImplementation((key) => Promise.resolve(key === 'server_clock_offset_ms' ? 0 : key === 'employee_id' ? 5 : undefined));
});

describe('allowedNextEventTypes', () => {
    it('sin evento previo, solo permite check_in', () => {
        expect(allowedNextEventTypes(null)).toEqual(['check_in']);
    });
    it('con hasScheduledBreak=false, filtra break_start', () => {
        expect(allowedNextEventTypes('check_in', false)).toEqual(['check_out']);
    });
});

describe('resolveOwnStatus', () => {
    it('usa el caché del servidor si no hay eventos locales de hoy ni de ayer', async () => {
        getEmployeeStatusCache.mockResolvedValue({ last_event: 'check_in', last_event_time: '08:00' });
        getEventsOnDate.mockResolvedValue([]);
        getOwnEmployee.mockResolvedValue({ has_scheduled_break: true });

        const result = await resolveOwnStatus();

        expect(result.last_event).toBe('check_in');
    });

    it('sin employeeId en meta, no intenta leer el caché de estado (getEmployeeStatusCache no se llama)', async () => {
        getMeta.mockImplementation((key) => Promise.resolve(key === 'employee_id' ? undefined : 0));
        getEventsOnDate.mockResolvedValue([]);
        getOwnEmployee.mockResolvedValue(undefined);

        await resolveOwnStatus();

        expect(getEmployeeStatusCache).not.toHaveBeenCalled();
    });
});

describe('getOwnStatus', () => {
    it('consulta en línea, cachea (si hay employeeId) y retorna el resultado', async () => {
        fetchStatus.mockResolvedValue({ last_event: 'check_in', last_event_time: '08:00', allowed_events: ['check_out'] });

        const result = await getOwnStatus();

        expect(setEmployeeStatusCache).toHaveBeenCalledWith(5, expect.objectContaining({ last_event: 'check_in' }));
        expect(result.last_event).toBe('check_in');
    });

    it('propaga MobileAuthError sin caer a resolución local (token revocado)', async () => {
        fetchStatus.mockRejectedValue(new MobileAuthError('revocado'));

        await expect(getOwnStatus()).rejects.toThrow(MobileAuthError);
        expect(getEventsOnDate).not.toHaveBeenCalled();
    });

    it('cae a resolveOwnStatus para cualquier otro error (sin red)', async () => {
        fetchStatus.mockRejectedValue(new Error('network down'));
        getEmployeeStatusCache.mockResolvedValue(undefined);
        getEventsOnDate.mockResolvedValue([]);
        getOwnEmployee.mockResolvedValue({ has_scheduled_break: true });

        const result = await getOwnStatus();

        expect(result.last_event).toBeNull();
    });
});

describe('enqueueMark', () => {
    it('encola el evento (con employeeId resuelto de meta y location) y refresca el caché de estado', async () => {
        getOwnEmployee.mockResolvedValue({ has_scheduled_break: true });

        const result = await enqueueMark('check_in', { lat: -25.3, lng: -57.6 });

        expect(queueEvent).toHaveBeenCalledWith(expect.objectContaining({ employee_id: 5, event_type: 'check_in', location: { lat: -25.3, lng: -57.6 } }));
        expect(setEmployeeStatusCache).toHaveBeenCalledWith(5, expect.objectContaining({ last_event: 'check_in' }));
        expect(result).toHaveProperty('client_event_id');
    });

    it('sin employeeId en meta, no intenta refrescar el caché de estado', async () => {
        getMeta.mockImplementation((key) => Promise.resolve(key === 'employee_id' ? undefined : 0));

        await enqueueMark('check_in');

        expect(setEmployeeStatusCache).not.toHaveBeenCalled();
    });
});

describe('flushQueue', () => {
    it('sin eventos pendientes, retorna de inmediato', async () => {
        getPendingEvents.mockResolvedValue([]);
        const result = await flushQueue();
        expect(result).toEqual({ synced: 0, conflicts: 0, stillPending: 0, results: [] });
    });

    it('sincroniza eventos exitosos', async () => {
        getPendingEvents.mockResolvedValue([{ client_event_id: 'e1', event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z', location: null }]);
        submitEvents.mockResolvedValue([{ client_event_id: 'e1', status: 'synced' }]);
        countPendingEvents.mockResolvedValue(0);

        const result = await flushQueue();

        expect(removeQueuedEvent).toHaveBeenCalledWith('e1');
        expect(result.synced).toBe(1);
    });

    it('propaga MobileAuthError inmediatamente (no la trata como fallo de red reintentable)', async () => {
        getPendingEvents.mockResolvedValue([{ client_event_id: 'e1', event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z', location: null }]);
        submitEvents.mockRejectedValue(new MobileAuthError('revocado'));

        await expect(flushQueue()).rejects.toThrow(MobileAuthError);
        expect(incrementQueuedEventAttempts).not.toHaveBeenCalled();
    });

    it('fallo de red normal: incrementa attempts y deja pending', async () => {
        getPendingEvents.mockResolvedValue([{ client_event_id: 'e1', event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z', location: null }]);
        submitEvents.mockRejectedValue(new Error('network down'));
        countPendingEvents.mockResolvedValue(1);

        const result = await flushQueue();

        expect(incrementQueuedEventAttempts).toHaveBeenCalledWith('e1');
        expect(result.stillPending).toBe(1);
    });

    it('parte la cola en lotes de a lo sumo 200 eventos', async () => {
        const pending = Array.from({ length: 250 }, (_, i) => ({ client_event_id: `e${i}`, event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z', location: null }));
        getPendingEvents.mockResolvedValue(pending);
        submitEvents.mockImplementation((batch) => Promise.resolve(batch.map((e) => ({ client_event_id: e.client_event_id, status: 'synced' }))));
        countPendingEvents.mockResolvedValue(0);

        const result = await flushQueue();

        expect(submitEvents).toHaveBeenCalledTimes(2);
        expect(submitEvents.mock.calls[0][0]).toHaveLength(200);
        expect(result.synced).toBe(250);
    });
});
```

- [ ] **Step 3: Correr ambos**

Run: `npx vitest run resources/js/attendances/terminal-offline/queue.test.js resources/js/attendances/mobile-offline/queue.test.js`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add resources/js/attendances/terminal-offline/queue.test.js resources/js/attendances/mobile-offline/queue.test.js
git commit -m "test: add characterization tests for queue.js (terminal + mobile)"
```

---

### Task 5: Tests de caracterización de `sync.js` (terminal + mobile)

**Files:**
- Test: `resources/js/attendances/terminal-offline/sync.test.js`
- Test: `resources/js/attendances/mobile-offline/sync.test.js`

**Interfaces:**
- Consumes: ninguno de tareas previas (mockea `db.js` y `fetch` global).
- Produces: nada consumido por tareas posteriores — `sync.js` no se extrae (fuera de alcance).

- [ ] **Step 1: Escribir los tests de `terminal-offline/sync.test.js`**

```js
// resources/js/attendances/terminal-offline/sync.test.js
/**
 * @fileoverview Tests de caracterización de terminal-offline/sync.js contra
 * su implementación ACTUAL, sin modificar. sync.js NO se extrae en el
 * sub-proyecto C (diferencias genuinas entre terminal/mobile) — estos tests
 * documentan y protegen su comportamiento actual igual, como parte de la
 * cobertura general acordada en la spec.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('./db.js', () => ({
    getMeta: vi.fn(),
    setMeta: vi.fn(),
    applyEmployeesDelta: vi.fn(),
    applyBreakFlags: vi.fn(),
    logSync: vi.fn(),
    countPendingEvents: vi.fn().mockResolvedValue(0),
    countConflictEvents: vi.fn().mockResolvedValue(0),
}));

import { getMeta, setMeta, applyEmployeesDelta, applyBreakFlags, logSync } from './db.js';
import { apiFetch, syncEmployees, heartbeat, getFaceConfig, fetchEmployeeStatus, submitEvents, TerminalAuthError } from './sync.js';

beforeEach(() => {
    vi.clearAllMocks();
    globalThis.fetch = vi.fn();
});

describe('apiFetch', () => {
    it('lanza TerminalAuthError si no hay token guardado', async () => {
        getMeta.mockResolvedValue(undefined);
        await expect(apiFetch('/heartbeat')).rejects.toThrow(TerminalAuthError);
        expect(fetch).not.toHaveBeenCalled();
    });

    it('llama a fetch con el header Authorization Bearer', async () => {
        getMeta.mockResolvedValue('tok-123');
        fetch.mockResolvedValue({ status: 200, json: () => Promise.resolve({ ok: true }) });

        await apiFetch('/heartbeat');

        expect(fetch).toHaveBeenCalledWith('/api/v1/terminal/heartbeat', expect.objectContaining({
            headers: expect.objectContaining({ Authorization: 'Bearer tok-123' }),
        }));
    });

    it('lanza TerminalAuthError en 401/403', async () => {
        getMeta.mockResolvedValue('tok-123');
        fetch.mockResolvedValue({ status: 401, json: () => Promise.resolve({}) });

        await expect(apiFetch('/heartbeat')).rejects.toThrow(TerminalAuthError);
    });
});

describe('syncEmployees', () => {
    it('aplica el delta y actualiza los cursores de sync', async () => {
        getMeta.mockImplementation((key) => Promise.resolve(key === 'api_token' ? 'tok' : undefined));
        fetch.mockResolvedValue({
            status: 200,
            json: () => Promise.resolve({ ok: true, employees: [{ id: 1 }], tombstones: [2], break_flags: { 1: true }, sync_version: 'v2' }),
        });

        const result = await syncEmployees();

        expect(applyEmployeesDelta).toHaveBeenCalledWith([{ id: 1 }], [2]);
        expect(applyBreakFlags).toHaveBeenCalledWith({ 1: true });
        expect(setMeta).toHaveBeenCalledWith('last_employee_sync_version', 'v2');
        expect(result).toEqual({ employees: 1, tombstones: 1 });
        expect(logSync).toHaveBeenCalledWith('employees_sync', true, expect.any(String));
    });

    it('registra el fallo en logSync y relanza el error', async () => {
        getMeta.mockResolvedValue('tok');
        fetch.mockResolvedValue({ status: 200, json: () => Promise.resolve({ ok: false, message: 'Error de sync' }) });

        await expect(syncEmployees()).rejects.toThrow('Error de sync');
        expect(logSync).toHaveBeenCalledWith('employees_sync', false, 'Error de sync');
    });
});

describe('heartbeat', () => {
    it('envía pending_events/conflict_events y guarda config/offset', async () => {
        getMeta.mockResolvedValue('tok');
        fetch.mockResolvedValue({
            status: 200,
            json: () => Promise.resolve({ ok: true, config: { face_threshold: 0.5, face_min_confidence_gap: 0.1 }, server_time: '2026-01-01T00:00:00Z' }),
        });

        await heartbeat();

        expect(setMeta).toHaveBeenCalledWith('face_threshold', 0.5);
        expect(setMeta).toHaveBeenCalledWith('face_min_confidence_gap', 0.1);
        expect(setMeta).toHaveBeenCalledWith('last_heartbeat_at', expect.any(Number));
        expect(logSync).toHaveBeenCalledWith('heartbeat', true);
    });
});

describe('getFaceConfig', () => {
    it('retorna null/null si nunca hubo heartbeat exitoso', async () => {
        getMeta.mockResolvedValue(undefined);
        expect(await getFaceConfig()).toEqual({ threshold: null, minGap: null });
    });

    it('retorna los valores cacheados', async () => {
        getMeta.mockImplementation((key) => Promise.resolve(key === 'face_threshold' ? 0.5 : key === 'face_min_confidence_gap' ? 0.1 : undefined));
        expect(await getFaceConfig()).toEqual({ threshold: 0.5, minGap: 0.1 });
    });
});

describe('fetchEmployeeStatus', () => {
    it('consulta /employees/{id}/status', async () => {
        getMeta.mockResolvedValue('tok');
        fetch.mockResolvedValue({ status: 200, json: () => Promise.resolve({ ok: true, last_event: 'check_in', allowed_events: ['check_out'] }) });

        const result = await fetchEmployeeStatus(7);

        expect(fetch).toHaveBeenCalledWith('/api/v1/terminal/employees/7/status', expect.anything());
        expect(result.last_event).toBe('check_in');
    });
});

describe('submitEvents', () => {
    it('envía los eventos y retorna data.results', async () => {
        getMeta.mockResolvedValue('tok');
        fetch.mockResolvedValue({ status: 200, json: () => Promise.resolve({ ok: true, results: [{ client_event_id: 'e1', status: 'synced' }] }) });

        const result = await submitEvents([{ client_event_id: 'e1', employee_id: 1, event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z' }]);

        expect(result).toEqual([{ client_event_id: 'e1', status: 'synced' }]);
    });
});
```

- [ ] **Step 2: Escribir los tests de `mobile-offline/sync.test.js`**

```js
// resources/js/attendances/mobile-offline/sync.test.js
/**
 * @fileoverview Tests de caracterización de mobile-offline/sync.js contra su
 * implementación ACTUAL, sin modificar — mismo propósito que
 * terminal-offline/sync.test.js (sub-proyecto C).
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('./db.js', () => ({
    getMeta: vi.fn(),
    setMeta: vi.fn(),
    setOwnEmployee: vi.fn(),
    logSync: vi.fn(),
}));

import { getMeta, setMeta, setOwnEmployee, logSync } from './db.js';
import { apiFetch, heartbeat, getFaceConfig, fetchStatus, unlinkDevice, submitEvents, MobileAuthError } from './sync.js';

beforeEach(() => {
    vi.clearAllMocks();
    globalThis.fetch = vi.fn();
});

describe('apiFetch', () => {
    it('lanza MobileAuthError si no hay token guardado', async () => {
        getMeta.mockResolvedValue(undefined);
        await expect(apiFetch('/heartbeat')).rejects.toThrow(MobileAuthError);
    });

    it('lanza MobileAuthError en 401/403', async () => {
        getMeta.mockResolvedValue('tok');
        fetch.mockResolvedValue({ status: 403, json: () => Promise.resolve({}) });
        await expect(apiFetch('/heartbeat')).rejects.toThrow(MobileAuthError);
    });
});

describe('heartbeat', () => {
    it('guarda el empleado propio, config y offset', async () => {
        getMeta.mockResolvedValue('tok');
        fetch.mockResolvedValue({
            status: 200,
            json: () => Promise.resolve({
                ok: true,
                employee: { id: 5, first_name: 'Juan' },
                config: { face_threshold: 0.5, face_min_confidence_gap: 0.1 },
                server_time: '2026-01-01T00:00:00Z',
            }),
        });

        await heartbeat();

        expect(setOwnEmployee).toHaveBeenCalledWith({ id: 5, first_name: 'Juan' });
        expect(setMeta).toHaveBeenCalledWith('face_threshold', 0.5);
        expect(logSync).toHaveBeenCalledWith('heartbeat', true);
    });

    it('registra el fallo en logSync y relanza', async () => {
        getMeta.mockResolvedValue('tok');
        fetch.mockResolvedValue({ status: 200, json: () => Promise.resolve({ ok: false, message: 'Error en heartbeat' }) });

        await expect(heartbeat()).rejects.toThrow('Error en heartbeat');
        expect(logSync).toHaveBeenCalledWith('heartbeat', false, 'Error en heartbeat');
    });
});

describe('getFaceConfig', () => {
    it('retorna los valores cacheados', async () => {
        getMeta.mockImplementation((key) => Promise.resolve(key === 'face_threshold' ? 0.5 : key === 'face_min_confidence_gap' ? 0.1 : undefined));
        expect(await getFaceConfig()).toEqual({ threshold: 0.5, minGap: 0.1 });
    });
});

describe('fetchStatus', () => {
    it('consulta /status sin employeeId (implícito en el token)', async () => {
        getMeta.mockResolvedValue('tok');
        fetch.mockResolvedValue({ status: 200, json: () => Promise.resolve({ ok: true, last_event: 'check_in', allowed_events: ['check_out'] }) });

        const result = await fetchStatus();

        expect(fetch).toHaveBeenCalledWith('/api/v1/mobile/status', expect.anything());
        expect(result.last_event).toBe('check_in');
    });
});

describe('unlinkDevice', () => {
    it('llama a POST /unlink', async () => {
        getMeta.mockResolvedValue('tok');
        fetch.mockResolvedValue({ status: 200, json: () => Promise.resolve({ ok: true }) });

        await unlinkDevice();

        expect(fetch).toHaveBeenCalledWith('/api/v1/mobile/unlink', expect.objectContaining({ method: 'POST' }));
    });

    it('lanza error si el servidor responde ok:false', async () => {
        getMeta.mockResolvedValue('tok');
        fetch.mockResolvedValue({ status: 200, json: () => Promise.resolve({ ok: false, message: 'No se pudo desvincular' }) });

        await expect(unlinkDevice()).rejects.toThrow('No se pudo desvincular');
    });
});

describe('submitEvents', () => {
    it('envía los eventos (sin employee_id, implícito) y retorna data.results', async () => {
        getMeta.mockResolvedValue('tok');
        fetch.mockResolvedValue({ status: 200, json: () => Promise.resolve({ ok: true, results: [{ client_event_id: 'e1', status: 'synced' }] }) });

        const result = await submitEvents([{ client_event_id: 'e1', event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z' }]);

        expect(result).toEqual([{ client_event_id: 'e1', status: 'synced' }]);
    });
});
```

- [ ] **Step 3: Correr ambos**

Run: `npx vitest run resources/js/attendances/terminal-offline/sync.test.js resources/js/attendances/mobile-offline/sync.test.js`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add resources/js/attendances/terminal-offline/sync.test.js resources/js/attendances/mobile-offline/sync.test.js
git commit -m "test: add characterization tests for sync.js (terminal + mobile)"
```

---

### Task 6: Extraer `offline-shared/matcher.js`

**Files:**
- Create: `resources/js/attendances/offline-shared/matcher.js`
- Move: `resources/js/attendances/terminal-offline/matcher.test.js` → `resources/js/attendances/offline-shared/matcher.test.js`
- Delete: `resources/js/attendances/terminal-offline/matcher.js`
- Delete: `resources/js/attendances/mobile-offline/matcher.js`
- Delete: `resources/js/attendances/mobile-offline/matcher.test.js`
- Modify: `resources/js/attendances/terminal/identification-flow.js` (import de `matchDescriptor`)
- Modify: `resources/js/attendances/mobile-offline/queue.js` — **no**, `queue.js` no importa matcher directamente; el import de matcher vive en el equivalente mobile de `identification-flow.js`. Verificar y ajustar el archivo real que lo importe (buscar `from '../mobile-offline/matcher.js'` o `from './matcher.js'` dentro de `mobile-offline/`).

**Interfaces:**
- Consumes: los tests de la Tarea 3 (se mueven, mismas aserciones).
- Produces: `export function euclideanDistance(a, b)`, `export function identifyEmployee(liveDescriptor, candidates, threshold, minGap)` — mismas firmas, consumido por `identification-flow.js` (terminal) y el módulo equivalente de mobile.

- [ ] **Step 1: Buscar todos los import de matcher.js**

```bash
grep -rn "from.*matcher" resources/js/attendances/ --include="*.js" | grep -v ".test.js"
```

Anotar cada archivo que importa `matchDescriptor`/`identifyEmployee` desde `terminal-offline/matcher.js` o `mobile-offline/matcher.js` — se actualizan en el Step 4.

- [ ] **Step 2: Crear el módulo compartido**

```js
// resources/js/attendances/offline-shared/matcher.js
/**
 * =============================================================================
 * MATCHING FACIAL CLIENT-SIDE (compartido terminal/mobile)
 * =============================================================================
 *
 * @fileoverview Port a JS de la lógica de identificación por distancia
 *               euclidiana de AttendanceFaceMarkController::identifyEmployeeByDescriptor()
 *               (backend), para poder correr el matching sin depender del
 *               servidor. Mismo algoritmo, mismos criterios de umbral/gap —
 *               la config (threshold/minGap) se sincroniza desde
 *               GeneralSettings vía el endpoint de heartbeat (ver sync.js).
 *
 *               Compartido entre terminal-offline/ (candidates normalmente
 *               son N empleados de la sucursal) y mobile-offline/ (candidates
 *               normalmente es un único empleado, el dueño del dispositivo) —
 *               el algoritmo es idéntico en ambos casos: con un solo
 *               candidato, el gap de confianza simplemente no se evalúa (no
 *               hay segundo candidato contra el cual compararlo).
 */

/**
 * Distancia euclidiana entre dos descriptores faciales (arrays de 128 floats).
 * @param {number[]} a
 * @param {number[]} b
 * @returns {number}
 */
export function euclideanDistance(a, b) {
    let sum = 0;
    for (let i = 0; i < 128; i++) {
        const diff = (a[i] ?? 0) - (b[i] ?? 0);
        sum += diff * diff;
    }
    return Math.sqrt(sum);
}

/**
 * Identifica al candidato más cercano a un descriptor "en vivo" dentro de la
 * caché local de empleados, aplicando el mismo criterio de umbral + gap de
 * confianza que el backend.
 *
 * @param {number[]} liveDescriptor - Descriptor capturado en el momento.
 * @param {Array<{id: number, first_name: string, last_name: string, ci: string|null, face_descriptor: number[]}>} candidates - Empleados cacheados (uno o varios, según terminal/mobile).
 * @param {number} threshold - Distancia máxima para aceptar un match (face_threshold).
 * @param {number} minGap - Diferencia mínima requerida con el segundo candidato (face_min_confidence_gap).
 * @returns {{employee: object|null, distance: number, reason: 'no_match'|'ambiguous'|'no_candidates'|null}}
 */
export function identifyEmployee(liveDescriptor, candidates, threshold, minGap) {
    if (!candidates || candidates.length === 0) {
        return { employee: null, distance: Infinity, reason: 'no_candidates' };
    }

    let best = null;
    let bestDist = Infinity;
    let secondBestDist = Infinity;

    for (const candidate of candidates) {
        if (!Array.isArray(candidate.face_descriptor) || candidate.face_descriptor.length !== 128) continue;

        const dist = euclideanDistance(liveDescriptor, candidate.face_descriptor);

        if (dist < bestDist) {
            secondBestDist = bestDist;
            bestDist = dist;
            best = candidate;
        } else if (dist < secondBestDist) {
            secondBestDist = dist;
        }
    }

    if (!best || bestDist > threshold) {
        return { employee: null, distance: bestDist, reason: 'no_match' };
    }

    if (secondBestDist !== Infinity) {
        const gap = secondBestDist - bestDist;
        if (gap < minGap) {
            return { employee: null, distance: bestDist, reason: 'ambiguous' };
        }
    }

    return { employee: best, distance: bestDist, reason: null };
}
```

- [ ] **Step 3: Mover el test, eliminar los duplicados**

```bash
git mv resources/js/attendances/terminal-offline/matcher.test.js resources/js/attendances/offline-shared/matcher.test.js
git rm resources/js/attendances/terminal-offline/matcher.js
git rm resources/js/attendances/mobile-offline/matcher.js
git rm resources/js/attendances/mobile-offline/matcher.test.js
```

Editar `resources/js/attendances/offline-shared/matcher.test.js`: el `import { euclideanDistance, identifyEmployee } from './matcher.js';` ya apunta correctamente al vecino en la misma carpeta (`offline-shared/matcher.js`) — no requiere cambios adicionales más allá de mover el archivo.

- [ ] **Step 4: Actualizar los imports en los consumidores (según lo encontrado en el Step 1)**

En `resources/js/attendances/terminal/identification-flow.js`, cambiar:
```js
import { identifyEmployee as matchDescriptor } from '../terminal-offline/matcher.js';
```
por:
```js
import { identifyEmployee as matchDescriptor } from '../offline-shared/matcher.js';
```

En el archivo equivalente de mobile que haga el import (identificado en el Step 1 — probablemente `resources/js/attendances/mark.js` o similar), cambiar el import de `'./mobile-offline/matcher.js'` (o el path relativo correspondiente) a `'./offline-shared/matcher.js'`.

- [ ] **Step 5: Correr el test movido y el suite completo**

Run: `npx vitest run resources/js/attendances/offline-shared/matcher.test.js`
Expected: PASS (8 tests, mismas aserciones que antes de mover)

Run: `npx vitest run`
Expected: PASS — confirma que ningún import roto quedó colgando.

- [ ] **Step 6: `npm run build`**

Run: `npm run build`
Expected: build exitoso, sin errores de resolución de módulos (confirma que `identification-flow.js` y el consumidor mobile resuelven el nuevo path).

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "refactor: extract matcher.js to offline-shared/ (identical between terminal and mobile)"
```

---

### Task 7: Extraer `offline-shared/generic-db.js`

**Files:**
- Create: `resources/js/attendances/offline-shared/generic-db.js`
- Modify: `resources/js/attendances/terminal-offline/db.js` (delega las funciones genéricas)
- Modify: `resources/js/attendances/mobile-offline/db.js` (delega las funciones genéricas)

**Interfaces:**
- Consumes: los tests de las Tareas 1 y 2 (se re-ejecutan sin cambios, contra el código ya refactorizado).
- Produces: `generic-db.js` exporta funciones que reciben el handle `db` (`IDBPDatabase`) como primer parámetro — consumido únicamente por `terminal-offline/db.js` y `mobile-offline/db.js`, ningún otro archivo del proyecto importa `generic-db.js` directamente.

- [ ] **Step 1: Crear el módulo genérico**

```js
// resources/js/attendances/offline-shared/generic-db.js
/**
 * =============================================================================
 * INDEXEDDB — CRUD GENÉRICO COMPARTIDO (compartido terminal/mobile)
 * =============================================================================
 *
 * @fileoverview Operaciones de IndexedDB mecánicamente idénticas entre
 * terminal-offline/db.js y mobile-offline/db.js: metadata key/value, cola de
 * eventos (`outbound_events`), caché de estado por empleado
 * (`employee_status_cache`), y log de sincronización (`sync_log`). No abre su
 * propia conexión — recibe el handle `db` ya abierto como primer parámetro,
 * así cada `db.js` mantiene su propio `getDb()` con su propio nombre/versión/
 * schema (incluido lo específico de cada uno: `employees_cache` en terminal,
 * `own_employee` en `mobile_meta` en mobile — eso NO se unifica, ver spec del
 * sub-proyecto C).
 *
 * Los nombres de store `outbound_events`, `employee_status_cache` y
 * `sync_log` son literales fijos porque ambas bases usan los mismos nombres
 * — el store de metadata SÍ varía (`terminal_meta` vs `mobile_meta`), así que
 * `getMeta`/`setMeta` reciben el nombre de store como parámetro.
 */

/**
 * @param {import('idb').IDBPDatabase} db
 * @param {string} storeName
 * @param {string} key
 * @returns {Promise<any>}
 */
export async function getMeta(db, storeName, key) {
    return db.get(storeName, key);
}

/**
 * @param {import('idb').IDBPDatabase} db
 * @param {string} storeName
 * @param {string} key
 * @param {any} value
 */
export async function setMeta(db, storeName, key, value) {
    return db.put(storeName, value, key);
}

/**
 * Registra una entrada en `sync_log`, recortando el historial a las últimas 50.
 * @param {import('idb').IDBPDatabase} db
 * @param {string} type
 * @param {boolean} ok
 * @param {string|null} [detail]
 */
export async function logSync(db, type, ok, detail = null) {
    await db.add('sync_log', { type, ok, detail, at: Date.now() });

    const allKeys = await db.getAllKeys('sync_log');
    if (allKeys.length > 50) {
        const tx = db.transaction('sync_log', 'readwrite');
        for (const key of allKeys.slice(0, allKeys.length - 50)) {
            await tx.store.delete(key);
        }
        await tx.done;
    }
}

/**
 * Encola una marcación capturada localmente.
 * @param {import('idb').IDBPDatabase} db
 * @param {object} event
 */
export async function queueEvent(db, event) {
    await db.put('outbound_events', { ...event, status: 'pending', attempts: 0, created_at: Date.now() });
}

/** @param {import('idb').IDBPDatabase} db @returns {Promise<Array<object>>} Eventos pendientes de sincronizar. */
export async function getPendingEvents(db) {
    const all = await db.getAll('outbound_events');
    return all.filter((event) => event.status === 'pending');
}

/** @param {import('idb').IDBPDatabase} db @param {string} clientEventId */
export async function removeQueuedEvent(db, clientEventId) {
    await db.delete('outbound_events', clientEventId);
}

/** @param {import('idb').IDBPDatabase} db @param {string} clientEventId @param {string} [message] */
export async function markQueuedEventConflict(db, clientEventId, message) {
    const event = await db.get('outbound_events', clientEventId);
    if (!event) return;
    await db.put('outbound_events', { ...event, status: 'conflict', server_message: message ?? null });
}

/** @param {import('idb').IDBPDatabase} db @param {string} clientEventId */
export async function incrementQueuedEventAttempts(db, clientEventId) {
    const event = await db.get('outbound_events', clientEventId);
    if (!event) return;
    await db.put('outbound_events', { ...event, attempts: (event.attempts ?? 0) + 1 });
}

/** @param {import('idb').IDBPDatabase} db @returns {Promise<number>} */
export async function countPendingEvents(db) {
    return (await getPendingEvents(db)).length;
}

/** @param {import('idb').IDBPDatabase} db @returns {Promise<number>} */
export async function countConflictEvents(db) {
    const all = await db.getAll('outbound_events');
    return all.filter((event) => event.status === 'conflict').length;
}

/** @param {import('idb').IDBPDatabase} db Elimina del store los eventos en conflicto. */
export async function dismissConflictEvents(db) {
    const all = await db.getAll('outbound_events');
    const tx = db.transaction('outbound_events', 'readwrite');
    for (const event of all) {
        if (event.status === 'conflict') await tx.store.delete(event.client_event_id);
    }
    await tx.done;
}

/**
 * @param {import('idb').IDBPDatabase} db
 * @param {number} employeeId
 * @param {{last_event: string|null, last_event_time: string|null, allowed_events: string[]}} status
 */
export async function setEmployeeStatusCache(db, employeeId, status) {
    await db.put('employee_status_cache', { employee_id: employeeId, ...status, cached_at: Date.now() });
}

/** @param {import('idb').IDBPDatabase} db @param {number} employeeId @returns {Promise<object|undefined>} */
export async function getEmployeeStatusCache(db, employeeId) {
    return db.get('employee_status_cache', employeeId);
}
```

- [ ] **Step 2: Correr los tests de caracterización TODAVÍA contra el código sin modificar (confirmar baseline verde antes de tocar db.js)**

Run: `npx vitest run resources/js/attendances/terminal-offline/db.test.js resources/js/attendances/mobile-offline/db.test.js`
Expected: PASS

- [ ] **Step 3: Reescribir `terminal-offline/db.js` para delegar al módulo genérico**

```js
// resources/js/attendances/terminal-offline/db.js
/**
 * =============================================================================
 * INDEXEDDB — CACHÉ LOCAL DEL TERMINAL
 * =============================================================================
 *
 * @fileoverview Wrapper delgado sobre `idb` para la base local del terminal.
 * El CRUD genérico (metadata, cola de eventos, caché de estado, sync log)
 * vive en `offline-shared/generic-db.js` — acá solo el schema propio
 * (incluye `employees_cache`, que mobile no tiene) y las funciones
 * específicas de la caché de empleados (N candidatos por sucursal).
 *
 * Stores:
 * - terminal_meta          — key/value: api_token, terminal_id/code/branch_id,
 *                             cursores de sync, config de reconocimiento facial.
 * - employees_cache        — keyPath 'id': empleados activos con descriptor
 *                             facial, scopeados a la sucursal del terminal.
 *                             Incluye `has_scheduled_break` (ver
 *                             applyBreakFlags()), usado para filtrar "Inicio
 *                             de descanso" en la resolución local sin red.
 * - outbound_events        — keyPath 'client_event_id': cola de marcaciones
 *                             capturadas localmente. `status`: 'pending'
 *                             (por sincronizar) | 'conflict' (el servidor la
 *                             rechazó — no se reintenta, queda para revisión).
 *                             Las sincronizadas con éxito se eliminan del store.
 * - employee_status_cache  — keyPath 'employee_id': último estado de marcación
 *                             conocido del servidor (last_event/allowed_events)
 *                             por empleado, para poder resolver localmente qué
 *                             botones mostrar cuando no hay red (ver queue.js).
 * - sync_log               — autoIncrement: historial breve de intentos de
 *                             sync, para diagnóstico en pantalla.
 */

import { openDB } from 'idb';
import * as genericDb from '../offline-shared/generic-db.js';

const DB_NAME = 'nominapp-terminal';
const DB_VERSION = 2;
const META_STORE = 'terminal_meta';

/** @type {Promise<import('idb').IDBPDatabase>|null} */
let dbPromise = null;

/** @returns {Promise<import('idb').IDBPDatabase>} */
export function getDb() {
    if (!dbPromise) {
        dbPromise = openDB(DB_NAME, DB_VERSION, {
            upgrade(db) {
                if (!db.objectStoreNames.contains('terminal_meta')) {
                    db.createObjectStore('terminal_meta');
                }
                if (!db.objectStoreNames.contains('employees_cache')) {
                    db.createObjectStore('employees_cache', { keyPath: 'id' });
                }
                if (!db.objectStoreNames.contains('outbound_events')) {
                    db.createObjectStore('outbound_events', { keyPath: 'client_event_id' });
                }
                if (!db.objectStoreNames.contains('employee_status_cache')) {
                    db.createObjectStore('employee_status_cache', { keyPath: 'employee_id' });
                }
                if (!db.objectStoreNames.contains('sync_log')) {
                    db.createObjectStore('sync_log', { keyPath: 'id', autoIncrement: true });
                }
            },
        });
    }
    return dbPromise;
}

/** @param {string} key @returns {Promise<any>} */
export async function getMeta(key) {
    return genericDb.getMeta(await getDb(), META_STORE, key);
}

/** @param {string} key @param {any} value */
export async function setMeta(key, value) {
    return genericDb.setMeta(await getDb(), META_STORE, key, value);
}

/**
 * Migración única del token guardado en localStorage por la pantalla de
 * configuración (terminal-setup.blade.php) hacia IndexedDB. Se puede llamar
 * en cada carga: es un no-op si ya no queda nada en localStorage.
 * @returns {Promise<void>}
 */
export async function migrateTokenFromLocalStorage() {
    const legacyToken = localStorage.getItem('nominapp_terminal_token');
    if (!legacyToken) return;

    const legacyId = localStorage.getItem('nominapp_terminal_id');
    const legacyCode = localStorage.getItem('nominapp_terminal_code');
    await setMeta('api_token', legacyToken);
    if (legacyId) await setMeta('terminal_id', Number(legacyId));
    if (legacyCode) await setMeta('terminal_code', legacyCode);

    localStorage.removeItem('nominapp_terminal_token');
    localStorage.removeItem('nominapp_terminal_id');
    localStorage.removeItem('nominapp_terminal_code');
}

/**
 * Limpia todo el estado local ligado a la identidad de un terminal (token,
 * empleados cacheados, cola de eventos, estado por empleado) — se usa cuando
 * `initializeOfflineSync()` detecta que el `terminal_id`/`terminal_code`
 * guardado no coincide con el de la página actual (`window.terminalData`):
 * significa que este navegador ya reclamó un token de sincronización para
 * OTRO terminal en algún momento (esta base de IndexedDB, `nominapp-terminal`,
 * es única por navegador, no está separada por terminal), y sin esta
 * limpieza seguiría autenticando/sincronizando en silencio como el terminal
 * viejo en cualquier `/terminal/{code}` que se abra desde este dispositivo.
 * @returns {Promise<void>}
 */
export async function clearTerminalState() {
    const db = await getDb();
    await Promise.all([
        db.clear('terminal_meta'),
        db.clear('employees_cache'),
        db.clear('outbound_events'),
        db.clear('employee_status_cache'),
    ]);
}

/** @param {string} type @param {boolean} ok @param {string|null} [detail] */
export async function logSync(type, ok, detail = null) {
    return genericDb.logSync(await getDb(), type, ok, detail);
}

/** @returns {Promise<Array<object>>} */
export async function getCachedEmployees() {
    const db = await getDb();
    return db.getAll('employees_cache');
}

/** @returns {Promise<number>} Cantidad de empleados cacheados — 0 indica que el terminal nunca sincronizó (o quedó sin candidatos). */
export async function countCachedEmployees() {
    return (await getCachedEmployees()).length;
}

/** @param {number} employeeId @returns {Promise<object|undefined>} */
export async function getCachedEmployee(employeeId) {
    const db = await getDb();
    return db.get('employees_cache', employeeId);
}

/**
 * Aplica un delta de sincronización de empleados: upsert de los modificados,
 * borrado de los tombstones.
 * @param {Array<object>} employees
 * @param {Array<number>} tombstones
 */
export async function applyEmployeesDelta(employees, tombstones) {
    const db = await getDb();
    const tx = db.transaction('employees_cache', 'readwrite');
    for (const employee of employees) {
        await tx.store.put(employee);
    }
    for (const id of tombstones) {
        await tx.store.delete(id);
    }
    await tx.done;
}

/**
 * Aplica el mapa `has_scheduled_break` (id → bool) a los empleados YA
 * cacheados — a diferencia de `applyEmployeesDelta()`, esto se recalcula
 * completo en cada sync, no solo para los que cambiaron. Un id que todavía no
 * está en la caché se ignora silenciosamente.
 * @param {Record<number, boolean>} breakFlags
 */
export async function applyBreakFlags(breakFlags) {
    const db = await getDb();
    const tx = db.transaction('employees_cache', 'readwrite');
    for (const [idStr, hasScheduledBreak] of Object.entries(breakFlags || {})) {
        const id = Number(idStr);
        const employee = await tx.store.get(id);
        if (employee) {
            await tx.store.put({ ...employee, has_scheduled_break: hasScheduledBreak });
        }
    }
    await tx.done;
}

// =========================================================================
// COLA DE EVENTOS OFFLINE (outbound_events) — delegado a generic-db.js
// =========================================================================

/**
 * Encola una marcación capturada localmente. `date` es la fecha local del
 * dispositivo (YYYY-MM-DD) al momento de la captura.
 * @param {{client_event_id: string, employee_id: number, event_type: string, recorded_at: string, date: string}} event
 */
export async function queueEvent(event) {
    return genericDb.queueEvent(await getDb(), event);
}

/** @returns {Promise<Array<object>>} Eventos pendientes de sincronizar. */
export async function getPendingEvents() {
    return genericDb.getPendingEvents(await getDb());
}

/**
 * Eventos (pendientes o en conflicto) de un empleado para la fecha local
 * indicada — la única función de cola que sigue siendo específica del
 * terminal (filtra por employeeId, que mobile no necesita).
 * @param {number} employeeId
 * @param {string} date - YYYY-MM-DD local.
 */
export async function getEventsForEmployeeOnDate(employeeId, date) {
    const db = await getDb();
    const all = await db.getAll('outbound_events');
    return all.filter((event) => event.employee_id === employeeId && event.date === date);
}

/** @param {string} clientEventId */
export async function removeQueuedEvent(clientEventId) {
    return genericDb.removeQueuedEvent(await getDb(), clientEventId);
}

/** @param {string} clientEventId @param {string} [message] */
export async function markQueuedEventConflict(clientEventId, message) {
    return genericDb.markQueuedEventConflict(await getDb(), clientEventId, message);
}

/** @param {string} clientEventId */
export async function incrementQueuedEventAttempts(clientEventId) {
    return genericDb.incrementQueuedEventAttempts(await getDb(), clientEventId);
}

/** @returns {Promise<number>} */
export async function countPendingEvents() {
    return genericDb.countPendingEvents(await getDb());
}

/** @returns {Promise<number>} */
export async function countConflictEvents() {
    return genericDb.countConflictEvents(await getDb());
}

// =========================================================================
// CACHÉ DE ESTADO POR EMPLEADO (employee_status_cache) — delegado
// =========================================================================

/**
 * @param {number} employeeId
 * @param {{last_event: string|null, last_event_time: string|null, allowed_events: string[]}} status
 */
export async function setEmployeeStatusCache(employeeId, status) {
    return genericDb.setEmployeeStatusCache(await getDb(), employeeId, status);
}

/** @param {number} employeeId @returns {Promise<object|undefined>} */
export async function getEmployeeStatusCache(employeeId) {
    return genericDb.getEmployeeStatusCache(await getDb(), employeeId);
}
```

- [ ] **Step 4: Reescribir `mobile-offline/db.js` para delegar al módulo genérico**

```js
// resources/js/attendances/mobile-offline/db.js
/**
 * =============================================================================
 * INDEXEDDB — CACHÉ LOCAL DEL DISPOSITIVO PERSONAL
 * =============================================================================
 *
 * @fileoverview Wrapper delgado sobre `idb` para la base local del
 * dispositivo. El CRUD genérico (metadata, cola de eventos, caché de estado,
 * sync log) vive en `offline-shared/generic-db.js` — acá solo el schema
 * propio y lo específico del empleado único (`own_employee`, sin store
 * `employees_cache` — el dispositivo cachea un solo descriptor).
 *
 * Stores:
 * - mobile_meta            — key/value: api_token, own_employee (id/nombre/CI/
 *                             descriptor/has_scheduled_break), config de
 *                             reconocimiento facial, offset de reloj,
 *                             timestamps de sync.
 * - outbound_events        — keyPath 'client_event_id': cola de marcaciones
 *                             capturadas localmente.
 * - employee_status_cache  — keyPath 'employee_id': último estado de
 *                             marcación conocido del servidor, un único registro.
 * - sync_log               — autoIncrement: historial breve de intentos de
 *                             sync, para diagnóstico en pantalla.
 */

import { openDB } from 'idb';
import * as genericDb from '../offline-shared/generic-db.js';

const DB_NAME = 'nominapp-mobile';
const DB_VERSION = 1;
const META_STORE = 'mobile_meta';

/** @type {Promise<import('idb').IDBPDatabase>|null} */
let dbPromise = null;

/** @returns {Promise<import('idb').IDBPDatabase>} */
export function getDb() {
    if (!dbPromise) {
        dbPromise = openDB(DB_NAME, DB_VERSION, {
            upgrade(db) {
                if (!db.objectStoreNames.contains('mobile_meta')) {
                    db.createObjectStore('mobile_meta');
                }
                if (!db.objectStoreNames.contains('outbound_events')) {
                    db.createObjectStore('outbound_events', { keyPath: 'client_event_id' });
                }
                if (!db.objectStoreNames.contains('employee_status_cache')) {
                    db.createObjectStore('employee_status_cache', { keyPath: 'employee_id' });
                }
                if (!db.objectStoreNames.contains('sync_log')) {
                    db.createObjectStore('sync_log', { keyPath: 'id', autoIncrement: true });
                }
            },
        });
    }
    return dbPromise;
}

/** @param {string} key @returns {Promise<any>} */
export async function getMeta(key) {
    return genericDb.getMeta(await getDb(), META_STORE, key);
}

/** @param {string} key @param {any} value */
export async function setMeta(key, value) {
    return genericDb.setMeta(await getDb(), META_STORE, key, value);
}

/**
 * Migración única del token guardado en localStorage por la pantalla de
 * vinculación (device-link.blade.php) hacia IndexedDB.
 * @returns {Promise<void>}
 */
export async function migrateTokenFromLocalStorage() {
    const legacyToken = localStorage.getItem('nominapp_mobile_token');
    if (!legacyToken) return;

    const legacyEmployeeId = localStorage.getItem('nominapp_mobile_employee_id');
    await setMeta('api_token', legacyToken);
    if (legacyEmployeeId) await setMeta('employee_id', Number(legacyEmployeeId));

    localStorage.removeItem('nominapp_mobile_token');
    localStorage.removeItem('nominapp_mobile_employee_id');
}

/** @param {string} type @param {boolean} ok @param {string|null} [detail] */
export async function logSync(type, ok, detail = null) {
    return genericDb.logSync(await getDb(), type, ok, detail);
}

/**
 * Vacía por completo la caché local del dispositivo — llamado tras una
 * auto-desvinculación exitosa (ver `unlinkDevice()` en `sync.js`).
 * @returns {Promise<void>}
 */
export async function resetDb() {
    const db = await getDb();
    await Promise.all(
        ['mobile_meta', 'outbound_events', 'employee_status_cache', 'sync_log'].map((store) => db.clear(store))
    );
}

/**
 * Empleado dueño del dispositivo, con su descriptor facial cacheado.
 * @returns {Promise<{id: number, first_name: string, last_name: string, ci: string|null, face_descriptor: number[]}|undefined>}
 */
export async function getOwnEmployee() {
    return getMeta('own_employee');
}

/**
 * Actualiza el empleado propio cacheado (llamado tras cada heartbeat exitoso).
 * @param {{id: number, first_name: string, last_name: string, ci: string|null, face_descriptor: number[]}} employee
 */
export async function setOwnEmployee(employee) {
    await setMeta('own_employee', employee);
    await setMeta('employee_id', employee.id);
}

// =========================================================================
// COLA DE EVENTOS OFFLINE (outbound_events) — delegado a generic-db.js
// =========================================================================

/**
 * @param {{client_event_id: string, employee_id: number, event_type: string, recorded_at: string, date: string, location?: object|null}} event
 */
export async function queueEvent(event) {
    return genericDb.queueEvent(await getDb(), event);
}

/** @returns {Promise<Array<object>>} */
export async function getPendingEvents() {
    return genericDb.getPendingEvents(await getDb());
}

/**
 * Eventos (pendientes o en conflicto) para la fecha local indicada — sin
 * employeeId, a diferencia del terminal (un único empleado por dispositivo).
 * @param {string} date - YYYY-MM-DD local.
 */
export async function getEventsOnDate(date) {
    const db = await getDb();
    const all = await db.getAll('outbound_events');
    return all.filter((event) => event.date === date);
}

/** @param {string} clientEventId */
export async function removeQueuedEvent(clientEventId) {
    return genericDb.removeQueuedEvent(await getDb(), clientEventId);
}

/** @param {string} clientEventId @param {string} [message] */
export async function markQueuedEventConflict(clientEventId, message) {
    return genericDb.markQueuedEventConflict(await getDb(), clientEventId, message);
}

/** @param {string} clientEventId */
export async function incrementQueuedEventAttempts(clientEventId) {
    return genericDb.incrementQueuedEventAttempts(await getDb(), clientEventId);
}

/** @returns {Promise<number>} */
export async function countPendingEvents() {
    return genericDb.countPendingEvents(await getDb());
}

/** @returns {Promise<number>} */
export async function countConflictEvents() {
    return genericDb.countConflictEvents(await getDb());
}

/** Elimina del store local los eventos en conflicto. */
export async function dismissConflictEvents() {
    return genericDb.dismissConflictEvents(await getDb());
}

// =========================================================================
// CACHÉ DE ESTADO (employee_status_cache) — delegado
// =========================================================================

/**
 * @param {number} employeeId
 * @param {{last_event: string|null, last_event_time: string|null, allowed_events: string[]}} status
 */
export async function setEmployeeStatusCache(employeeId, status) {
    return genericDb.setEmployeeStatusCache(await getDb(), employeeId, status);
}

/** @param {number} employeeId @returns {Promise<object|undefined>} */
export async function getEmployeeStatusCache(employeeId) {
    return genericDb.getEmployeeStatusCache(await getDb(), employeeId);
}
```

- [ ] **Step 5: Re-correr la MISMA suite de caracterización, sin cambiar ninguna aserción**

Run: `npx vitest run resources/js/attendances/terminal-offline/db.test.js resources/js/attendances/mobile-offline/db.test.js`
Expected: PASS — mismas aserciones que en el Step 2, ahora contra el código delegado. Si alguna falla, es una señal de comportamiento cambiado — investigar antes de continuar, no ajustar la aserción.

- [ ] **Step 6: Correr también `queue.js`/`sync.js` (dependen de `db.js`) y el suite completo**

Run: `npx vitest run resources/js/attendances/terminal-offline/queue.test.js resources/js/attendances/mobile-offline/queue.test.js resources/js/attendances/terminal-offline/sync.test.js resources/js/attendances/mobile-offline/sync.test.js`
Expected: PASS (estos mockean `db.js` directamente, así que no deberían verse afectados — confirma que la interfaz pública de `db.js` no cambió)

Run: `npx vitest run`
Expected: PASS completo.

- [ ] **Step 7: `npm run build`**

Run: `npm run build`
Expected: build exitoso.

- [ ] **Step 8: Commit**

```bash
git add resources/js/attendances/offline-shared/generic-db.js resources/js/attendances/terminal-offline/db.js resources/js/attendances/mobile-offline/db.js
git commit -m "refactor: extract generic CRUD from db.js to offline-shared/generic-db.js"
```

---

### Task 8: Extraer `offline-shared/submit-in-chunks.js`

**Files:**
- Create: `resources/js/attendances/offline-shared/submit-in-chunks.js`
- Modify: `resources/js/attendances/terminal-offline/queue.js` (usa el helper en `flushQueue`)
- Modify: `resources/js/attendances/mobile-offline/queue.js` (usa el helper en `flushQueue`)

**Interfaces:**
- Consumes: los tests de la Tarea 4 (se re-ejecutan sin cambios).
- Produces: `export async function submitInChunks(events, submitFn, chunkSize = 200)` → `Promise<{synced: number, conflicts: number, results: Array<object>}>` — recibe también los callbacks de persistencia (`onSynced`, `onConflict`, `onBatchFailed`) para no acoplarse a ningún `db.js` en particular.

- [ ] **Step 1: Crear el helper compartido**

```js
// resources/js/attendances/offline-shared/submit-in-chunks.js
/**
 * =============================================================================
 * ENVÍO DE EVENTOS EN LOTES (compartido terminal/mobile)
 * =============================================================================
 *
 * @fileoverview El loop de `flushQueue()` que parte la cola en lotes de a lo
 * sumo `chunkSize` (por defecto 200, debe coincidir con el límite del
 * servidor — ver `TerminalEventSyncController`/`MobileEventSyncController`:
 * `'events' => [..., 'max:200']`). Sin este chunking, una cola con más de
 * 200 eventos pendientes se rechaza entera (422) y queda atascada
 * indefinidamente (confirmado con una prueba de carga de 250 eventos en el
 * terminal antes de este fix). Mecánicamente idéntico entre terminal y
 * mobile — solo cambia qué campos lleva cada evento (`employee_id`/`location`),
 * que resuelve el caller vía `submitFn`.
 *
 * No conoce `db.js` de ninguna de las dos carpetas — recibe callbacks para
 * persistir el resultado de cada evento, así el caller decide qué guardar
 * (removeQueuedEvent/markQueuedEventConflict/incrementQueuedEventAttempts,
 * cada uno según su propio `db.js`).
 */

/**
 * No decide qué hacer cuando un lote falla — ni siquiera sabe que
 * `MobileAuthError` existe. Si `submitFn` rechaza, `submitInChunks` relanza
 * el mismo error tal cual, pero le agrega `partialResults` (lo ya
 * sincronizado/en-conflicto de lotes previos) y `remainingEvents` (el lote
 * que falló + los que ni se intentaron) — cada `queue.js` decide en su
 * propio `catch` qué hacer con `remainingEvents` (ej. incrementar
 * `attempts`), porque terminal y mobile difieren ahí: terminal SIEMPRE
 * incrementa `attempts` en cualquier fallo; mobile NO lo hace cuando el
 * error es `MobileAuthError` (token revocado no es un fallo de red
 * reintentable, no tiene sentido contarlo como intento). Ver Steps 3 y 4.
 * @param {Array<object>} events - Eventos pendientes a enviar.
 * @param {(batch: Array<object>) => Promise<Array<{client_event_id: string, status: string, message?: string}>>} submitFn - Envía un lote, retorna los resultados del servidor.
 * @param {{onSynced: (clientEventId: string) => Promise<void>, onConflict: (clientEventId: string, message?: string) => Promise<void>}} callbacks
 * @param {number} [chunkSize]
 * @returns {Promise<{synced: number, conflicts: number, results: Array<object>}>}
 */
export async function submitInChunks(events, submitFn, { onSynced, onConflict }, chunkSize = 200) {
    let synced = 0;
    let conflicts = 0;
    const allResults = [];

    for (let offset = 0; offset < events.length; offset += chunkSize) {
        const batch = events.slice(offset, offset + chunkSize);

        let results;
        try {
            results = await submitFn(batch);
        } catch (error) {
            // El caller decide qué hacer con remainingEvents (ej. incrementar attempts) — acá
            // no se toca nada más que relanzar con el contexto acumulado hasta ahora.
            throw Object.assign(error, {
                partialResults: { synced, conflicts, results: allResults },
                remainingEvents: events.slice(offset),
            });
        }

        for (const result of results) {
            if (result.status === 'synced' || result.status === 'duplicate') {
                await onSynced(result.client_event_id);
                synced++;
            } else {
                await onConflict(result.client_event_id, result.message);
                conflicts++;
            }
        }
        allResults.push(...results);
    }

    return { synced, conflicts, results: allResults };
}
```

- [ ] **Step 2: Correr los tests de caracterización TODAVÍA contra el código sin modificar (confirmar baseline verde antes de tocar queue.js)**

Run: `npx vitest run resources/js/attendances/terminal-offline/queue.test.js resources/js/attendances/mobile-offline/queue.test.js`
Expected: PASS

- [ ] **Step 3: Reescribir `flushQueue()` en `terminal-offline/queue.js`**

Reemplazar únicamente la función `flushQueue` (dejar `allowedNextEventTypes`, `correctedNow`, `localDateString`, `previousLocalDateString`, `resolveEmployeeStatus`, `getEmployeeStatus`, `enqueueMark`, `flushInProgress`, `MAX_BATCH_SIZE` y el `export { countPendingEvents, countConflictEvents };` final tal cual):

```js
// Agregar al bloque de imports existente (junto a los de './db.js' y './sync.js'):
import { submitInChunks } from '../offline-shared/submit-in-chunks.js';

// Reemplazar la función flushQueue completa por:
export async function flushQueue() {
    if (flushInProgress) return { synced: 0, conflicts: 0, stillPending: await countPendingEvents(), results: [] };
    flushInProgress = true;

    try {
        const pending = await getPendingEvents();
        if (pending.length === 0) return { synced: 0, conflicts: 0, stillPending: 0, results: [] };

        try {
            const { synced, conflicts, results } = await submitInChunks(
                pending,
                (batch) => submitEvents(batch.map(({ client_event_id, employee_id, event_type, recorded_at }) => ({
                    client_event_id,
                    employee_id,
                    event_type,
                    recorded_at,
                }))),
                { onSynced: removeQueuedEvent, onConflict: markQueuedEventConflict },
                MAX_BATCH_SIZE,
            );
            return { synced, conflicts, stillPending: await countPendingEvents(), results };
        } catch (error) {
            // Terminal: cualquier fallo de lote (sin red, servidor caído) se absorbe acá —
            // incrementa attempts en los eventos que quedaron sin enviar y se reintenta en el
            // próximo ciclo. Mismo comportamiento que el código original.
            for (const event of error.remainingEvents ?? []) await incrementQueuedEventAttempts(event.client_event_id);
            console.warn(`flushQueue: no se pudo sincronizar el lote (${(error.remainingEvents ?? []).length} eventos restantes):`, error.message);
            const partial = error.partialResults ?? { synced: 0, conflicts: 0, results: [] };
            return { synced: partial.synced, conflicts: partial.conflicts, stillPending: await countPendingEvents(), results: partial.results };
        }
    } finally {
        flushInProgress = false;
    }
}
```

- [ ] **Step 4: Reescribir `flushQueue()` en `mobile-offline/queue.js`**

Reemplazar únicamente la función `flushQueue` (dejar el resto del archivo tal cual):

```js
// Agregar al bloque de imports existente:
import { submitInChunks } from '../offline-shared/submit-in-chunks.js';

// Reemplazar la función flushQueue completa por:
export async function flushQueue() {
    if (flushInProgress) return { synced: 0, conflicts: 0, stillPending: await countPendingEvents(), results: [] };
    flushInProgress = true;

    try {
        const pending = await getPendingEvents();
        if (pending.length === 0) return { synced: 0, conflicts: 0, stillPending: 0, results: [] };

        try {
            const { synced, conflicts, results } = await submitInChunks(
                pending,
                (batch) => submitEvents(batch.map(({ client_event_id, event_type, recorded_at, location }) => ({
                    client_event_id,
                    event_type,
                    recorded_at,
                    location: location ?? undefined,
                }))),
                { onSynced: removeQueuedEvent, onConflict: markQueuedEventConflict },
                MAX_BATCH_SIZE,
            );
            return { synced, conflicts, stillPending: await countPendingEvents(), results };
        } catch (error) {
            // Token revocado — no es un fallo de red recuperable, el caller (mark.js) debe
            // mandar al empleado a re-vincular el dispositivo. NO se incrementa attempts acá —
            // no tiene sentido contar como "intento fallido" algo que no es un problema de red,
            // mismo comportamiento que el código original.
            if (error instanceof MobileAuthError) throw error;

            for (const event of error.remainingEvents ?? []) await incrementQueuedEventAttempts(event.client_event_id);
            console.warn(`flushQueue: no se pudo sincronizar el lote (${(error.remainingEvents ?? []).length} eventos restantes):`, error.message);
            const partial = error.partialResults ?? { synced: 0, conflicts: 0, results: [] };
            return { synced: partial.synced, conflicts: partial.conflicts, stillPending: await countPendingEvents(), results: partial.results };
        }
    } finally {
        flushInProgress = false;
    }
}
```

- [ ] **Step 5: Re-correr la MISMA suite de caracterización, sin cambiar ninguna aserción**

Run: `npx vitest run resources/js/attendances/terminal-offline/queue.test.js resources/js/attendances/mobile-offline/queue.test.js`
Expected: PASS — mismas aserciones que en el Step 2, sin ningún ajuste. El test de `MobileAuthError` en `mobile-offline/queue.test.js` (`propaga MobileAuthError inmediatamente...`) espera `incrementQueuedEventAttempts` NO llamado — con este diseño (chequeo de `MobileAuthError` ANTES de tocar `remainingEvents`), eso se preserva exactamente. El test de chunking (lotes de 200/50) y el de fallo de red normal (incrementa attempts, no relanza en terminal) también deben seguir pasando sin cambios.

- [ ] **Step 6: Suite completo y build**

Run: `npx vitest run`
Expected: PASS

Run: `npm run build`
Expected: build exitoso.

- [ ] **Step 7: Commit**

```bash
git add resources/js/attendances/offline-shared/submit-in-chunks.js resources/js/attendances/terminal-offline/queue.js resources/js/attendances/mobile-offline/queue.js
git commit -m "refactor: extract flushQueue chunking to offline-shared/submit-in-chunks.js"
```

---

### Task 9: Verificación final y humo manual

**Files:** ninguno — tarea de verificación, sin cambios de código.

**Interfaces:** ninguna — última tarea del plan.

- [ ] **Step 1: Suite completo de Vitest**

Run: `npx vitest run`
Expected: PASS completo (los tests de las Tareas 1-5 más cualquier test preexistente del proyecto).

- [ ] **Step 2: Suite completo de Pest**

Run: `php artisan test --compact`
Expected: PASS completo — este sub-proyecto es JS puro, no debería haber ningún cambio ni siquiera indirecto en el backend.

- [ ] **Step 3: `npm run build`**

Run: `npm run build`
Expected: build exitoso, sin errores de resolución de módulos.

- [ ] **Step 4: Verificación manual de humo**

En un entorno con un terminal de prueba provisionado y un dispositivo (o navegador) vinculado vía `/vincular-dispositivo`:
1. `/marcar` (mobile): marcar una asistencia con red — confirma que se registra online.
2. `/marcar`: desconectar la red, marcar de nuevo — confirma que se encola localmente y se sincroniza al reconectar.
3. Terminal de prueba: identificar un empleado y marcar, online y offline, igual que en los pasos 1-2.
4. Terminal de prueba: verificar que "Mis marcaciones"/estado del día siguen reflejando correctamente lo marcado localmente antes de sincronizar.

Si algo se comporta distinto a antes de este sub-proyecto, es un defecto a corregir antes de cerrar — la garantía central de este plan es cero cambio de comportamiento.

- [ ] **Step 5: Commit final (si hubiera algún ajuste de la verificación manual)**

Si el Step 4 no encontró nada que corregir, no hay commit en este paso — el plan queda cerrado en el commit de la Tarea 8.
