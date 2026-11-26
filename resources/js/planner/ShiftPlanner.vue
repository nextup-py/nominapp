<template>
  <div class="sp-root">

    <!-- ══════════════ TOOLBAR ══════════════ -->
    <div class="sp-toolbar">
      <div class="sp-filters">
        <select v-model="filters.company_id" class="sp-select" @change="onCompanyChange">
          <option :value="null">Todas las empresas</option>
          <option v-for="c in initData.companies" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>

        <select v-model="filters.branch_id" class="sp-select">
          <option :value="null">Todas las sucursales</option>
          <option v-for="b in filteredBranches" :key="b.id" :value="b.id">{{ b.name }}</option>
        </select>

        <button class="sp-btn sp-btn-primary" @click="loadData" :disabled="loading">
          <span v-if="loading">Cargando…</span>
          <span v-else>Aplicar</span>
        </button>
      </div>

      <div class="sp-nav">
        <button class="sp-btn sp-btn-help" @click="helpOpen = true" title="Ayuda">?</button>
        <button class="sp-btn" @click="navigate(-1)" :disabled="loading">‹ Anterior</button>

        <div class="sp-nav-label">
          <strong>{{ navLabel }}</strong>
        </div>

        <button class="sp-btn" @click="navigate(1)" :disabled="loading">Siguiente ›</button>
        <button class="sp-btn sp-btn-today" @click="goToToday" :disabled="loading">Hoy</button>

        <select v-model="viewDays" class="sp-select sp-select-sm" @change="loadData">
          <option :value="7">Semana (7 días)</option>
          <option :value="14">2 Semanas</option>
          <option :value="30">Mes (30 días)</option>
        </select>
      </div>
    </div>

    <!-- ══════════════ LEYENDA ══════════════ -->
    <div class="sp-legend" v-if="availableShifts.length">
      <span class="sp-legend-label">Turnos:</span>
      <span
        v-for="s in availableShifts"
        :key="s.id"
        class="sp-legend-item"
        :style="{ backgroundColor: s.color, color: contrastColor(s.color) }"
      >
        {{ s.name }}
      </span>
      <span class="sp-legend-item sp-legend-override">● Override</span>
    </div>

    <!-- ══════════════ GRID ══════════════ -->
    <div class="sp-grid-wrapper" v-if="!loading && employees.length">
      <table class="sp-grid">
        <thead>
          <tr>
            <th class="sp-col-employee">Empleado</th>
            <th
              v-for="day in days"
              :key="day.date"
              class="sp-col-day"
              :class="{ 'sp-today': day.isToday, 'sp-weekend': day.isWeekend }"
            >
              <div class="sp-day-head">
                <span class="sp-day-name">{{ day.dayName }}</span>
                <span class="sp-day-num" :class="{ 'sp-today-num': day.isToday }">{{ day.dayNum }}</span>
                <span class="sp-month-label" v-if="day.showMonth">{{ day.monthName }}</span>
              </div>
            </th>
            <th class="sp-col-hours" title="Horas netas planificadas en el rango visible">Horas</th>
          </tr>
        </thead>

        <tbody>
          <tr v-for="emp in employees" :key="emp.id" class="sp-row">
            <!-- Employee info cell -->
            <td class="sp-employee-cell">
              <div class="sp-employee-info">
                <img v-if="emp.photo" :src="emp.photo" class="sp-avatar-img" :alt="emp.name">
                <div v-else class="sp-avatar-initials">{{ emp.initials }}</div>
                <div class="sp-employee-text">
                  <div class="sp-employee-name">{{ emp.name }}</div>
                  <div class="sp-employee-branch">{{ emp.branch }}</div>
                </div>
              </div>
            </td>

            <!-- Shift cells -->
            <td
              v-for="day in days"
              :key="day.date"
              class="sp-shift-cell"
              :class="{
                'sp-today-col': day.isToday,
                'sp-weekend-col': day.isWeekend,
                'sp-drag-over': dragTarget?.employeeId === emp.id && dragTarget?.date === day.date,
              }"
              @click="openModal(emp, day)"
              @dragover.prevent="onDragOver(emp.id, day.date)"
              @dragleave="dragTarget = null"
              @drop.prevent="onDrop(emp, day)"
            >
              <div
                v-if="getShift(emp.id, day.date)"
                class="sp-shift-badge"
                :class="{ 'sp-badge-override': getShift(emp.id, day.date).is_override, 'sp-badge-dayoff': getShift(emp.id, day.date).is_day_off }"
                :style="{ backgroundColor: getShift(emp.id, day.date).color, color: contrastColor(getShift(emp.id, day.date).color) }"
                              :draggable="canEdit"
                @dragstart.stop="onDragStart(emp, day)"
                @click.stop="openModal(emp, day)"
              >
                <span class="sp-badge-name">{{ getShift(emp.id, day.date).name }}</span>
                <span v-if="!getShift(emp.id, day.date).is_day_off" class="sp-badge-time">
                  {{ formatTime(getShift(emp.id, day.date).start) }}–{{ formatTime(getShift(emp.id, day.date).end) }}
                </span>
                <span v-if="getShift(emp.id, day.date).is_override" class="sp-override-dot" title="Turno puntual">●</span>
                <span v-if="getShift(emp.id, day.date).is_active === false" class="sp-inactive-tag" title="Turno inactivo: sigue asignado, pero ya no se ofrece">inactivo</span>
              </div>
              <div v-else-if="canEdit" class="sp-shift-empty" @click.stop="openModal(emp, day)">+</div>
              <div v-else class="sp-shift-empty sp-shift-empty-readonly">—</div>
            </td>
            <td class="sp-hours-cell">{{ formatHours(employeeMinutes(emp.id)) }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Sin datos -->
    <div class="sp-empty" v-if="!loading && !employees.length">
      <p>No hay empleados con rotación para los filtros seleccionados.</p>
    </div>

    <!-- Loading -->
    <div class="sp-loading" v-if="loading">
      <div class="sp-spinner"></div>
      <p>Cargando planificación…</p>
    </div>

    <!-- ══════════════ MODAL ══════════════ -->
    <teleport to="body">
      <div v-if="modal.open" class="sp-modal-overlay" @click.self="closeModal">
        <div class="sp-modal">
          <div class="sp-modal-header">
            <h3>{{ modal.employee?.name }}</h3>
            <p class="sp-modal-date">{{ modal.day?.label }}</p>
            <button class="sp-modal-close" @click="closeModal">✕</button>
          </div>

          <div class="sp-modal-body">
            <!-- Turno actual -->
            <div class="sp-modal-current" v-if="modal.currentShift">
              <span class="sp-modal-current-label">Turno actual:</span>
              <span
                class="sp-modal-current-badge"
                :style="{ backgroundColor: modal.currentShift.color, color: contrastColor(modal.currentShift.color) }"
              >
                {{ modal.currentShift.name }}
              </span>
              <span v-if="modal.currentShift.is_override" class="sp-modal-override-tag">turno puntual</span>
              <span v-if="modal.currentShift.is_active === false" class="sp-modal-override-tag">inactivo</span>
            </div>
            <div class="sp-modal-current" v-else>
              <span class="sp-modal-current-label">Sin turno asignado</span>
            </div>

            <!-- Selector de turno -->
            <div v-if="modal.error" class="sp-modal-error">{{ modal.error }}</div>

            <div v-if="!canEdit" class="sp-modal-readonly">No tiene permiso para modificar los turnos.</div>

            <div v-if="canEdit" class="sp-modal-section-title">Asignar turno:</div>
            <div v-if="canEdit" class="sp-shift-options">
              <button
                v-for="s in modalShifts"
                :key="s.id"
                class="sp-shift-option"
                :class="{ 'sp-shift-selected': modal.selectedShift?.id === s.id }"
                :style="{ borderColor: s.color }"
                @click="modal.selectedShift = s"
              >
                <span class="sp-option-dot" :style="{ backgroundColor: s.color }"></span>
                <span class="sp-option-name">{{ s.name }}</span>
                <span class="sp-option-time" v-if="!s.is_day_off">{{ formatTime(s.start) }}–{{ formatTime(s.end) }}</span>
                <span class="sp-option-time" v-else>Franco</span>
              </button>
            </div>

            <!-- Motivo y notas (solo si seleccionó un turno distinto al actual) -->
            <template v-if="canEdit && modal.selectedShift">
              <div class="sp-modal-section-title">Motivo del cambio:</div>
              <select v-model="modal.reasonType" class="sp-select sp-select-full">
                <option value="cambio_turno">Cambio de turno</option>
                <option value="guardia_extra">Guardia extra</option>
                <option value="permiso">Permiso</option>
                <option value="reposo">Reposo médico</option>
                <option value="otro">Otro</option>
              </select>

              <input
                v-model="modal.notes"
                class="sp-input"
                placeholder="Notas opcionales (ej: cubre a García)"
                maxlength="150"
              >
            </template>
          </div>

          <div class="sp-modal-footer">
            <button
              v-if="canEdit && modal.currentShift?.is_override"
              class="sp-btn sp-btn-danger"
              @click="removeOverride"
              :disabled="modal.saving"
            >
              Restaurar ciclo
            </button>

            <div class="sp-modal-footer-right">
              <button class="sp-btn" @click="closeModal" :disabled="modal.saving">{{ canEdit ? 'Cancelar' : 'Cerrar' }}</button>
              <button
                v-if="canEdit"
                class="sp-btn sp-btn-primary"
                @click="saveOverride"
                :disabled="!modal.selectedShift || modal.saving"
              >
                <span v-if="modal.saving">Guardando…</span>
                <span v-else>Guardar</span>
              </button>
            </div>
          </div>
        </div>
      </div>
    </teleport>

    <!-- ══════════════ MODAL AYUDA ══════════════ -->
    <teleport to="body">
      <div v-if="helpOpen" class="sp-modal-overlay" @click.self="helpOpen = false">
        <div class="sp-modal sp-modal-help">
          <div class="sp-modal-header">
            <h3>Como usar el Planificador</h3>
            <button class="sp-modal-close" @click="helpOpen = false">✕</button>
          </div>

          <div class="sp-modal-body">
            <div class="sp-help-section">
              <div class="sp-help-title">Navegacion</div>
              <ul class="sp-help-list">
                <li>Usa <strong>Anterior / Siguiente</strong> para moverte entre semanas o meses.</li>
                <li>El boton <strong>Hoy</strong> vuelve a la semana actual.</li>
                <li>El selector de la derecha cambia la cantidad de dias visibles (7, 14 o 30).</li>
              </ul>
            </div>

            <div class="sp-help-section">
              <div class="sp-help-title">Filtros</div>
              <ul class="sp-help-list">
                <li>Filtra por <strong>empresa</strong> o <strong>sucursal</strong> y presiona <strong>Aplicar</strong>.</li>
                <li>Si no seleccionas filtros, se muestran todos los empleados con rotacion activa.</li>
              </ul>
            </div>

            <div class="sp-help-section">
              <div class="sp-help-title">Cambiar un turno puntual (Override)</div>
              <ul class="sp-help-list">
                <li>Haz clic en cualquier celda del grid para abrir el panel de edicion.</li>
                <li>Selecciona el turno nuevo, el motivo del cambio y notas opcionales.</li>
                <li>Presiona <strong>Guardar</strong>. El cambio aplica solo para ese dia — el ciclo no se modifica.</li>
                <li>Las celdas con override muestran un borde punteado.</li>
              </ul>
            </div>

            <div class="sp-help-section">
              <div class="sp-help-title">Restaurar el ciclo</div>
              <ul class="sp-help-list">
                <li>Si una celda ya tiene un override, el panel muestra el boton <strong>Restaurar ciclo</strong>.</li>
                <li>Al presionarlo, el dia vuelve al turno que le corresponde segun el patron de rotacion.</li>
              </ul>
            </div>

            <div class="sp-help-section">
              <div class="sp-help-title">Drag &amp; Drop</div>
              <ul class="sp-help-list">
                <li>Arrastra el badge de un turno a otra celda para asignar ese turno al empleado/dia destino.</li>
                <li>El sistema crea automaticamente un override con motivo "Cambio de turno".</li>
              </ul>
            </div>

            <div class="sp-help-section">
              <div class="sp-help-title">Celdas vacias</div>
              <ul class="sp-help-list">
                <li>Un empleado sin patron de rotacion aparece con <strong>+</strong> en sus celdas.</li>
                <li>Igualmente podes hacer clic para asignar un override puntual.</li>
                <li>Para asignar un patron completo, ve a <strong>Empleados → pestaña Rotaciones</strong>.</li>
              </ul>
            </div>

            <div class="sp-help-section">
              <div class="sp-help-title">Leyenda de colores</div>
              <ul class="sp-help-list">
                <li>Cada turno tiene un color definido en la configuracion de turnos.</li>
                <li>Los colores se muestran en la leyenda sobre el grid.</li>
              </ul>
            </div>
          </div>

          <div class="sp-modal-footer">
            <div class="sp-modal-footer-right">
              <button class="sp-btn sp-btn-primary" @click="helpOpen = false">Entendido</button>
            </div>
          </div>
        </div>
      </div>
    </teleport>

  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue';

// ─── Props ───────────────────────────────────────────────────────────────────
const props = defineProps({
  /** Datos iniciales inyectados desde Blade (empresas, sucursales, CSRF, rutas). */
  initData: { type: Object, required: true },
});

// ─── Constantes de fecha ─────────────────────────────────────────────────────
const DAY_NAMES   = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
const MONTH_NAMES = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];

// ─── Estado ──────────────────────────────────────────────────────────────────
const canEdit        = computed(() => props.initData.canEdit !== false);
const loading        = ref(false);
const helpOpen       = ref(false);
const viewDays       = ref(14);
const startDate      = ref(getMonday(new Date()));   // semana actual (Lunes)
const employees      = ref([]);
const shifts         = ref({});                       // { emp_id: { 'YYYY-MM-DD': shiftObj } }
const availableShifts = ref([]);
const dragSource     = ref(null);                     // { employee, day }
const dragTarget     = ref(null);                     // { employeeId, date }

const filters = reactive({
  company_id: null,
  branch_id:  null,
});

const modal = reactive({
  open:          false,
  employee:      null,
  day:           null,
  currentShift:  null,
  selectedShift: null,
  reasonType:    'cambio_turno',
  notes:         '',
  saving:        false,
  error:         '',
});

// ─── Computed ─────────────────────────────────────────────────────────────────

/** Sucursales filtradas por empresa seleccionada. */
const filteredBranches = computed(() =>
  filters.company_id
    ? props.initData.branches.filter(b => b.company_id === filters.company_id)
    : props.initData.branches
);

/** Array de días del rango actual. */
const days = computed(() => {
  const result = [];
  let prevMonth = -1;

  for (let i = 0; i < viewDays.value; i++) {
    const d = addDays(startDate.value, i);
    const dateStr = formatDate(d);
    const month = d.getMonth();

    result.push({
      date:       dateStr,
      dayName:    DAY_NAMES[d.getDay()],
      dayNum:     d.getDate(),
      monthName:  MONTH_NAMES[month],
      showMonth:  month !== prevMonth,
      isToday:    dateStr === formatDate(new Date()),
      isWeekend:  d.getDay() === 0 || d.getDay() === 6,
      label:      `${DAY_NAMES[d.getDay()]} ${d.getDate()} ${MONTH_NAMES[month]} ${d.getFullYear()}`,
    });

    prevMonth = month;
  }

  return result;
});

/** Label de navegación: "6 – 19 Abr 2026" */
const navLabel = computed(() => {
  const first = days.value[0];
  const last  = days.value[days.value.length - 1];
  if (! first || ! last) return '';

  if (first.monthName === last.monthName) {
    return `${first.dayNum} – ${last.dayNum} ${first.monthName} ${startDate.value.getFullYear()}`;
  }
  return `${first.dayNum} ${first.monthName} – ${last.dayNum} ${last.monthName} ${startDate.value.getFullYear()}`;
});

/** Turnos disponibles filtrados por empresa del modal. */
const modalShifts = computed(() => {
  if (! filters.company_id) return availableShifts.value;
  return availableShifts.value.filter(s => s.company_id === filters.company_id || ! s.company_id);
});

// ─── Métodos de fecha ─────────────────────────────────────────────────────────

/** Retorna el Lunes de la semana que contiene a `date`. */
function getMonday(date) {
  const d   = new Date(date);
  const day = d.getDay();
  d.setDate(d.getDate() - (day === 0 ? 6 : day - 1));
  d.setHours(0, 0, 0, 0);
  return d;
}

function addDays(date, n) {
  const d = new Date(date);
  d.setDate(d.getDate() + n);
  return d;
}

function formatDate(date) {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}

/** Formatea "HH:MM:SS" → "HH:MM". */
function formatTime(timeStr) {
  return timeStr ? timeStr.substring(0, 5) : '';
}

// ─── Navegación ──────────────────────────────────────────────────────────────

function navigate(direction) {
  startDate.value = addDays(startDate.value, direction * viewDays.value);
  loadData();
}

function goToToday() {
  startDate.value = getMonday(new Date());
  loadData();
}

// ─── Carga de datos ───────────────────────────────────────────────────────────

async function loadData() {
  loading.value = true;
  try {
    const end = addDays(startDate.value, viewDays.value - 1);
    const params = new URLSearchParams({
      start: formatDate(startDate.value),
      end:   formatDate(end),
    });
    if (filters.company_id) params.set('company_id', filters.company_id);
    if (filters.branch_id)  params.set('branch_id',  filters.branch_id);

    const res  = await fetch(`${props.initData.routes.data}?${params}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    const data = await res.json();

    employees.value = data.employees;
    shifts.value    = data.shifts;
  } catch (err) {
    console.error('Error cargando planificación:', err);
  } finally {
    loading.value = false;
  }
}

async function loadAvailableShifts() {
  try {
    const params = new URLSearchParams();
    if (filters.company_id) params.set('company_id', filters.company_id);

    const res  = await fetch(`${props.initData.routes.shifts}?${params}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    availableShifts.value = await res.json();
  } catch (err) {
    console.error('Error cargando turnos:', err);
  }
}

function onCompanyChange() {
  filters.branch_id = null;
  loadAvailableShifts();
}

// ─── Helpers de turno ─────────────────────────────────────────────────────────

function getShift(employeeId, date) {
  return shifts.value[employeeId]?.[date] ?? null;
}

/**
 * Calcula el color de texto (blanco o negro) en función del color de fondo.
 * Usa luminancia relativa para garantizar contraste legible.
 */
function contrastColor(hex) {
  if (! hex) return '#000';
  const r = parseInt(hex.slice(1, 3), 16);
  const g = parseInt(hex.slice(3, 5), 16);
  const b = parseInt(hex.slice(5, 7), 16);
  const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
  return luminance > 0.5 ? '#1f2937' : '#ffffff';
}

/** Minutos netos planificados de un empleado en el rango visible. */
function employeeMinutes(employeeId) {
  return days.value.reduce((total, day) => total + (getShift(employeeId, day.date)?.net_minutes || 0), 0);
}

/** Formatea minutos como horas (p. ej. 2.790 → «46,5 h»); sin turnos muestra «—». */
function formatHours(minutes) {
  if (! minutes) return '—';
  return `${(minutes / 60).toLocaleString('es-PY', { maximumFractionDigits: 1 })} h`;
}

/**
 * Guarda un turno puntual. Devuelve { ok, data, message }: ante un error del servidor (permiso, validación)
 * no se toca la grilla y se devuelve el mensaje para mostrarlo.
 */
async function postOverride(payload) {
  const res = await fetch(props.initData.routes.overrideStore, {
    method:  'POST',
    headers: {
      'Content-Type':     'application/json',
      'Accept':           'application/json',
      'X-CSRF-TOKEN':     props.initData.csrf,
      'X-Requested-With': 'XMLHttpRequest',
    },
    body: JSON.stringify(payload),
  });

  const body = await res.json().catch(() => ({}));

  if (! res.ok) {
    return {
      ok: false,
      message: res.status === 403
        ? 'No tiene permiso para modificar los turnos.'
        : (body.message || 'No se pudo guardar el turno.'),
    };
  }

  return { ok: true, data: body };
}

// ─── Modal ────────────────────────────────────────────────────────────────────

function openModal(emp, day) {
  if (! canEdit.value && ! getShift(emp.id, day.date)) return;

  modal.employee      = emp;
  modal.day           = day;
  modal.currentShift  = getShift(emp.id, day.date);
  modal.selectedShift = null;
  modal.reasonType    = 'cambio_turno';
  modal.notes         = '';
  modal.saving        = false;
  modal.error         = '';
  modal.open          = true;
}

function closeModal() {
  modal.open = false;
}

async function saveOverride() {
  if (! modal.selectedShift || modal.saving) return;

  modal.saving = true;
  modal.error  = '';
  try {
    const result = await postOverride({
      employee_id: modal.employee.id,
      date:        modal.day.date,
      shift_id:    modal.selectedShift.id,
      reason_type: modal.reasonType,
      notes:       modal.notes || null,
    });

    if (! result.ok) {
      modal.error = result.message;
      return;
    }

    // Actualizar la celda en el estado local sin recargar todo
    if (! shifts.value[modal.employee.id]) shifts.value[modal.employee.id] = {};
    shifts.value[modal.employee.id][modal.day.date] = result.data;

    closeModal();
  } catch (err) {
    console.error('Error guardando turno puntual:', err);
    modal.error = 'No se pudo guardar el turno. Revisar la conexión e intentar de nuevo.';
  } finally {
    modal.saving = false;
  }
}

async function removeOverride() {
  if (modal.saving) return;

  modal.saving = true;
  try {
    const res = await fetch(props.initData.routes.overrideDestroy, {
      method:  'DELETE',
      headers: {
        'Content-Type':     'application/json',
        'X-CSRF-TOKEN':     props.initData.csrf,
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify({
        employee_id: modal.employee.id,
        date:        modal.day.date,
      }),
    });

    if (! res.ok) {
      modal.error = res.status === 403 ? 'No tiene permiso para modificar los turnos.' : 'No se pudo restaurar el ciclo.';
      return;
    }

    // Recargar el día para obtener el turno del ciclo
    await reloadEmployeeDay(modal.employee.id, modal.day.date);

    closeModal();
  } catch (err) {
    console.error('Error restaurando el ciclo:', err);
    modal.error = 'No se pudo restaurar el ciclo. Revisar la conexión e intentar de nuevo.';
  } finally {
    modal.saving = false;
  }
}

/** Recarga solo los datos de un empleado para 1 día, para actualizar la celda tras quitar override. */
async function reloadEmployeeDay(employeeId, date) {
  const params = new URLSearchParams({
    start:       date,
    end:         date,
    employee_id: employeeId,
  });
  if (filters.company_id) params.set('company_id', filters.company_id);

  const res  = await fetch(`${props.initData.routes.data}?${params}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
  const data = await res.json();

  const dayShift = data.shifts?.[employeeId]?.[date];
  if (! shifts.value[employeeId]) shifts.value[employeeId] = {};

  if (dayShift) {
    shifts.value[employeeId][date] = dayShift;
  } else {
    delete shifts.value[employeeId][date];
  }
}

// ─── Drag & Drop ──────────────────────────────────────────────────────────────

function onDragStart(emp, day) {
  if (! canEdit.value) return;
  dragSource.value = { employee: emp, day };
}

function onDragOver(employeeId, date) {
  if (! dragSource.value) return;
  dragTarget.value = { employeeId, date };
}

async function onDrop(targetEmp, targetDay) {
  if (! dragSource.value) { dragTarget.value = null; return; }
  if (dragSource.value.employee.id === targetEmp.id && dragSource.value.day.date === targetDay.date) {
    dragSource.value = null;
    dragTarget.value = null;
    return;
  }

  const sourceShift = getShift(dragSource.value.employee.id, dragSource.value.day.date);
  if (! sourceShift) { dragSource.value = null; dragTarget.value = null; return; }

  // Mover el turno al empleado/día destino como turno puntual
  try {
    const result = await postOverride({
      employee_id: targetEmp.id,
      date:        targetDay.date,
      shift_id:    sourceShift.id,
      reason_type: 'cambio_turno',
      notes:       `Movido desde ${dragSource.value.employee.name} (${dragSource.value.day.label})`,
    });

    if (! result.ok) {
      window.alert(result.message);
      return;
    }

    if (! shifts.value[targetEmp.id]) shifts.value[targetEmp.id] = {};
    shifts.value[targetEmp.id][targetDay.date] = result.data;
  } catch (err) {
    console.error('Error en drag & drop:', err);
    window.alert('No se pudo mover el turno. Revisar la conexión e intentar de nuevo.');
  } finally {
    dragSource.value = null;
    dragTarget.value = null;
  }
}

// ─── Inicialización ───────────────────────────────────────────────────────────

onMounted(() => {
  loadAvailableShifts();
  loadData();
});
</script>
