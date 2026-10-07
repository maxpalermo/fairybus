import FetchHelper from "../core/FetchHelper.js";
import DialogHelper from "../core/DialogHelper.js";
import SearchableSelect from "../components/SearchableSelect.js";
import { viewItem } from "../components/ViewGrid.js";

const dialog = new DialogHelper();

const MONTHS = ["Gennaio", "Febbraio", "Marzo", "Aprile", "Maggio", "Giugno", "Luglio", "Agosto", "Settembre", "Ottobre", "Novembre", "Dicembre"];
const DOW = ["Lun", "Mar", "Mer", "Gio", "Ven", "Sab", "Dom"];
let KM_MARGIN = 2000;
let DATE_MARGIN = 30;

let current = new Date(); // mese visualizzato
let occurrences = [];
let kmTableInitialized = false;
let dateTableInitialized = false;
let vehicleTableInitialized = false;
let storicoTableInitialized = false;
let vehiclesCache = null;
let tagsCache = null;

function escapeHtml(text) {
    const div = document.createElement("div");
    div.textContent = String(text ?? "");
    return div.innerHTML;
}

function fmtDate(value) {
    if (!value) {
        return "—";
    }
    const d = new Date(String(value).replace(" ", "T"));
    return isNaN(d) ? escapeHtml(value) : d.toLocaleDateString("it-IT");
}

function fmtKm(v) {
    return v !== null && v !== undefined && v !== "" ? `${Number(v).toLocaleString("it-IT")} km` : "—";
}

/* Somma un intervallo (days|months|years) a una data ISO, ritorna YYYY-MM-DD */
function addDays(dateStr, unit, value) {
    const d = new Date(`${dateStr}T00:00:00`);
    if (isNaN(d)) {
        return null;
    }
    if (unit === "months") {
        d.setMonth(d.getMonth() + value);
    } else if (unit === "years") {
        d.setFullYear(d.getFullYear() + value);
    } else {
        d.setDate(d.getDate() + value);
    }
    return d.toISOString().slice(0, 10);
}

const ICONS = {
    check: '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>',
    alert: '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>',
    clock: '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>',
    bell: '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>',
    calendar: '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>',
    edit: '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.83 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>',
    trash: '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>',
    eye: '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>',
    eyeOff: '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c6.5 0 10 8 10 8a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.5 13.5 0 0 0 2 12s3.5 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" y1="2" x2="22" y2="22"/><path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/></svg>',
};

/* Colonna azioni scadenze: modifica / nascondi-mostra / elimina */
let showHiddenExpirations = false;

function formatOccurrenceActions(row) {
    const hidden = Number(row.hidden) === 1;
    const toggleBtn = hidden ? `<button type="button" class="fb-btn-icon fb-btn-icon-success" data-occ-action="show" title="Ripristina scadenza">${ICONS.eye}</button>` : `<button type="button" class="fb-btn-icon fb-btn-icon-warning" data-occ-action="hide" title="Nascondi scadenza">${ICONS.eyeOff}</button>`;
    return `<div class="fb-btn-group">
        <button type="button" class="fb-btn-icon fb-btn-icon-info" data-occ-action="edit" title="Modifica scadenza">${ICONS.edit}</button>
        ${toggleBtn}
        <button type="button" class="fb-btn-icon fb-btn-icon-danger" data-occ-action="delete" title="Elimina scadenza">${ICONS.trash}</button>
    </div>`;
}

const occActionsColumn = {
    field: "_actions",
    title: "",
    align: "center",
    sortable: false,
    searchable: false,
    formatter: (v, row) => formatOccurrenceActions(row),
};

function refreshExpirationViews() {
    loadOccurrences();
    loadAlerts();
    const hiddenSuffix = showHiddenExpirations ? "&include_hidden=1" : "";
    const tables = {
        "#km-table": "api/expirations/occurrences?km_only=1",
        "#date-table": "api/expirations/occurrences?date_only=1",
    };
    for (const [sel, path] of Object.entries(tables)) {
        if (window.$(sel).data("bootstrap.table")) {
            window.$(sel).bootstrapTable("refreshOptions", { url: `${window.FB.baseUrl}${path}${hiddenSuffix}` });
        }
    }
    document.querySelectorAll("table.veh-exp-table").forEach((t) => {
        const idVehicle = t.dataset.idVehicle;
        window.$(t).bootstrapTable("refreshOptions", {
            url: `${window.FB.baseUrl}api/expirations/occurrences?id_vehicle=${idVehicle}${hiddenSuffix}`,
        });
    });
    document.querySelectorAll("table.storico-exp-table").forEach((t) => {
        const idExpiration = t.dataset.idExpiration;
        window.$(t).bootstrapTable("refreshOptions", {
            url: `${window.FB.baseUrl}api/expirations/occurrences?id_expiration=${idExpiration}&include_hidden=1`,
        });
    });
    if (window.$("#storico-table").data("bootstrap.table")) {
        window.$("#storico-table").bootstrapTable("refresh");
    }
}

/* Delegato: un listener per tutte le tabelle (incluse le inner di dettaglio) */
document.addEventListener("click", (ev) => {
    const btn = ev.target.closest("[data-occ-action]");
    if (!btn) {
        return;
    }
    ev.stopPropagation();
    const tr = btn.closest("tr");
    const table = btn.closest("table");
    if (!tr || !table || tr.dataset.index === undefined) {
        return;
    }
    const row = window.$(table).bootstrapTable("getData", { useCurrentPage: true })[Number(tr.dataset.index)];
    if (row) {
        handleOccurrenceAction(btn.dataset.occAction, row);
    }
});

async function handleOccurrenceAction(action, row) {
    try {
        if (action === "edit") {
            openEditOccurrence(row);
        } else if (action === "delete") {
            const label = row.expiration_date ? fmtDate(row.expiration_date) : fmtKm(row.km);
            if (!(await dialog.confirm(`Eliminare la scadenza "${row.tag_name || row.description || "—"}" del veicolo ${row.vehicle_plate || "—"} (${label})?`, "Conferma eliminazione"))) {
                return;
            }
            await FetchHelper.post(`${window.FB.baseUrl}api/expirations/occurrences/${row.id_expiration_occurrence}/delete`);
            refreshExpirationViews();
        } else if (action === "hide" || action === "show") {
            await FetchHelper.post(`${window.FB.baseUrl}api/expirations/${action === "hide" ? "hide" : "unhide"}`, {
                id_vehicle: row.id_vehicle,
                id_expiration_tag: row.id_expiration_tag,
            });
            refreshExpirationViews();
        }
    } catch (err) {
        await dialog.error(err);
    }
}

function openEditOccurrence(row) {
    const isKm = !row.expiration_date || row.expiration_kind === "km" || row.tag_kind === "km";
    const d = document.createElement("dialog");
    d.className = "fb-dialog";
    d.innerHTML = `
        <div class="fb-dialog-header"><h3 class="fb-dialog-title">Modifica scadenza</h3></div>
        <div class="fb-dialog-body">
            <div class="fb-edit-occ-head">
                <span class="fb-alert-plate">${escapeHtml(row.vehicle_plate || "—")}</span>
                <span class="fb-edit-occ-tag">${escapeHtml(row.tag_name || row.description || "Scadenza")}</span>
            </div>
            <form class="fb-form" method="dialog">
                <div class="fb-form-group">
                    <label class="fb-form-label">${isKm ? "Scadenza (km)" : "Scadenza (data)"}</label>
                    ${isKm ? `<input type="number" name="km" class="fb-form-input" min="0" step="1" value="${row.km ?? ""}" required>` : `<input type="date" name="expiration_date" class="fb-form-input" value="${String(row.expiration_date || "").slice(0, 10)}" required>`}
                </div>
                <div class="fb-form-actions">
                    <button type="submit" class="fb-btn fb-btn-primary">Salva</button>
                    <button type="button" class="fb-btn fb-btn-secondary" data-action="close">Annulla</button>
                </div>
            </form>
        </div>`;
    document.body.appendChild(d);
    d.showModal();
    d.querySelector("form").addEventListener("submit", async (ev) => {
        ev.preventDefault();
        const data = Object.fromEntries(new FormData(ev.target).entries());
        try {
            await FetchHelper.post(`${window.FB.baseUrl}api/expirations/occurrences/${row.id_expiration_occurrence}/update`, data);
            d.close();
            refreshExpirationViews();
        } catch (err) {
            await dialog.error(err);
        }
    });
    d.querySelector('[data-action="close"]').addEventListener("click", () => d.close());
    d.addEventListener("close", () => d.remove(), { once: true });
}

function monthRange() {
    const y = current.getFullYear();
    const m = current.getMonth();
    const first = `${y}-${String(m + 1).padStart(2, "0")}-01`;
    const last = new Date(y, m + 1, 0).getDate();
    return { from: first, to: `${y}-${String(m + 1).padStart(2, "0")}-${String(last).padStart(2, "0")}` };
}

async function loadOccurrences() {
    const { from, to } = monthRange();
    try {
        const res = await FetchHelper.get(`${window.FB.baseUrl}api/expirations/occurrences?from=${from}&to=${to}`);
        occurrences = res.rows || [];
    } catch (err) {
        await dialog.error(err);
        occurrences = [];
    }
    renderCalendar();
}

/* ---------- Tab di pagina ---------- */

function initTabs() {
    const tabs = document.querySelectorAll(".fb-page-tabs .fb-tab");
    tabs.forEach((tab) => {
        tab.addEventListener("click", () => {
            tabs.forEach((t) => {
                t.classList.remove("active");
                t.setAttribute("aria-selected", "false");
            });
            tab.classList.add("active");
            tab.setAttribute("aria-selected", "true");
            document.querySelectorAll(".fb-tab-panel").forEach((p) => {
                p.classList.toggle("active", p.dataset.panel === tab.dataset.tab);
            });
            updateSelectedCounter();
            if (tab.dataset.tab === "km" && !kmTableInitialized) {
                initKmTable();
            }
            if (tab.dataset.tab === "date" && !dateTableInitialized) {
                initDateTable();
            }
            if (tab.dataset.tab === "vehicle" && !vehicleTableInitialized) {
                initVehicleTable();
            }
            if (tab.dataset.tab === "storico" && !storicoTableInitialized) {
                initStoricoTable();
            }
        });
    });
}

/* ---------- Griglia mese ---------- */

function renderCalendar() {
    const grid = document.querySelector('[data-field="grid"]');
    const title = document.querySelector('[data-field="month-title"]');
    if (!grid) {
        return;
    }

    title.textContent = `${MONTHS[current.getMonth()]} ${current.getFullYear()}`;
    grid.innerHTML = "";

    for (const dow of DOW) {
        const el = document.createElement("div");
        el.className = "fb-cal-dow";
        el.textContent = dow;
        grid.appendChild(el);
    }

    const y = current.getFullYear();
    const m = current.getMonth();
    const first = new Date(y, m, 1);
    const start = new Date(first);
    start.setDate(first.getDate() - ((first.getDay() + 6) % 7));

    const todayStr = new Date().toISOString().slice(0, 10);
    const byDate = {};
    for (const o of occurrences) {
        if (o.expiration_date) {
            (byDate[o.expiration_date] = byDate[o.expiration_date] || []).push(o);
        }
    }

    for (let i = 0; i < 42; i++) {
        const d = new Date(start);
        d.setDate(start.getDate() + i);
        const iso = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;

        const cell = document.createElement("div");
        cell.className = "fb-cal-day";
        if (d.getMonth() !== m) {
            cell.classList.add("fb-cal-outside");
        }
        if (iso === todayStr) {
            cell.classList.add("fb-cal-today");
        }

        const num = document.createElement("div");
        num.className = "fb-cal-daynum";
        num.textContent = d.getDate();
        cell.appendChild(num);

        const items = byDate[iso] || [];
        if (items.length > 0) {
            cell.classList.add("fb-cal-has-items");
            cell.addEventListener("click", () => openDayDialog(iso, items));
        }
        const MAX = 3;
        items.slice(0, MAX).forEach((o) => {
            const chip = document.createElement("button");
            chip.type = "button";
            chip.className = "fb-cal-chip";
            if (Number(o.state) === 4) {
                chip.classList.add("fb-chip-done");
            } else if (o.expiration_date < todayStr) {
                chip.classList.add("fb-chip-overdue");
            }
            chip.textContent = `${o.vehicle_plate || "?"} · ${o.tag_name || o.description || "Scadenza"}`;
            chip.title = chip.textContent;
            chip.addEventListener("click", (ev) => {
                ev.stopPropagation();
                openOccurrenceDialog(o);
            });
            cell.appendChild(chip);
        });
        if (items.length > MAX) {
            const more = document.createElement("div");
            more.className = "fb-cal-more";
            more.textContent = `+${items.length - MAX} altre`;
            cell.appendChild(more);
        }

        grid.appendChild(cell);
    }
}

/* ---------- Scadenze km (Bootstrap Table AJAX) ---------- */

/* Ordinamento default delle tabelle scadenze: targa asc, poi voce asc */
function sortByVehicleTag(a, b) {
    const byPlate = String(a.vehicle_plate || "").localeCompare(String(b.vehicle_plate || ""), "it");
    if (byPlate !== 0) {
        return byPlate;
    }
    const byTag = String(a.tag_name || a.description || "").localeCompare(String(b.tag_name || b.description || ""), "it");
    if (byTag !== 0) {
        return byTag;
    }
    if (a.expiration_date && b.expiration_date) {
        return String(b.expiration_date).localeCompare(String(a.expiration_date));
    }
    if (a.expiration_date) {
        return -1;
    }
    if (b.expiration_date) {
        return 1;
    }
    return Number(b.km || 0) - Number(a.km || 0);
}

function kmStateInfo(o) {
    const targetKm = Number(o.km || 0);
    const currentKm = Number(o.current_km || 0);
    if (Number(o.state) === 4) {
        return { icon: ICONS.check, label: "Eseguita", cls: "fb-st-done" };
    }
    if (targetKm > 0 && targetKm <= currentKm) {
        return { icon: ICONS.alert, label: "Superata", cls: "fb-st-overdue" };
    }
    if (targetKm > 0 && targetKm <= currentKm + KM_MARGIN) {
        return { icon: ICONS.bell, label: "In scadenza", cls: "fb-st-soon" };
    }
    switch (Number(o.state)) {
        case 1:
            return { icon: ICONS.bell, label: "Notificata", cls: "fb-st-info" };
        case 2:
            return { icon: ICONS.calendar, label: "Pianificata", cls: "fb-st-info" };
        case 3:
            return { icon: ICONS.alert, label: "Scaduta", cls: "fb-st-overdue" };
        default:
            return { icon: ICONS.clock, label: "In attesa", cls: "fb-st-muted" };
    }
}

function formatKmState(value, row) {
    const s = kmStateInfo(row);
    return `<span class="fb-state ${s.cls}">${s.icon}<span>${escapeHtml(s.label)}</span></span>`;
}

// opzioni per la select della colonna Stato (filter-control)
window.kmStateOptions = {
    "": "Tutti",
    Eseguita: "Eseguita",
    Superata: "Superata",
    "In scadenza": "In scadenza",
    Notificata: "Notificata",
    Pianificata: "Pianificata",
    Scaduta: "Scaduta",
    "In attesa": "In attesa",
};

function initKmTable() {
    if (!window.$ || !window.$.fn.bootstrapTable) {
        setTimeout(initKmTable, 100);
        return;
    }
    kmTableInitialized = true;

    window.$("#km-table").bootstrapTable({
        url: window.FB.baseUrl + "api/expirations/occurrences?km_only=1",
        sidePagination: "client",
        pagination: true,
        search: false,
        sortable: true,
        filterControl: true,
        locale: "it-IT",
        responseHandler: (res) => {
            // testo stato precomputato per il filtro (i filtri lavorano sul valore grezzo)
            (res.rows || []).forEach((r) => {
                r.state_text = kmStateInfo(r).label;
                r.km_missing = Number(r.km || 0) - Number(r.current_km || 0);
            });
            (res.rows || []).sort(sortByVehicleTag);
            return res;
        },
        rowAttributes: (row) => ({ "data-id": row.id_expiration_occurrence }),
        rowStyle: (r) => (Number(r.hidden) === 1 ? { classes: "fb-row-hidden" } : {}),
        columns: [
            { field: "_ck", checkbox: true, align: "center", valign: "middle" },
            { field: "vehicle_plate", title: "Veicolo", sortable: true, filterControl: "input", filterCustomSearch: window.fbFilterSearch, formatter: (v) => escapeHtml(v || "—") },
            { field: "tag_name", title: "Voce", sortable: true, filterControl: "input", filterCustomSearch: window.fbFilterSearch, formatter: (v, r) => escapeHtml(r.tag_name || r.description || "—") },
            { field: "km", title: "Scadenza a km", sortable: true, align: "right", filterControl: "input", filterCustomSearch: window.fbFilterSearch, formatter: (v) => fmtKm(v) },
            { field: "current_km", title: "Km attuali", sortable: true, align: "right", filterControl: "input", filterCustomSearch: window.fbFilterSearch, formatter: (v) => fmtKm(v) },
            {
                field: "km_missing",
                title: "Km mancanti",
                sortable: true,
                align: "right",
                filterControl: "input",
                filterCustomSearch: window.fbFilterSearch,
                formatter: formatKmMissing,
            },
            {
                field: "state_text",
                title: "Stato",
                sortable: true,
                align: "center",
                formatter: (v, row) => formatKmState(row.state, row),
                searchFormatter: false,
                filterControl: "select",
                filterData: "var:kmStateOptions",
            },
            occActionsColumn,
        ],
        onClickRow: (row, $el, field) => {
            if (field !== "_ck" && field !== "_actions") {
                openOccurrenceDialog(row);
            }
        },
    });
    bindSelectionCounter("#km-table");
}

/* ---------- Scadenze a data (Bootstrap Table AJAX) ---------- */

function daysRemaining(dateStr) {
    if (!dateStr) {
        return null;
    }
    const target = new Date(String(dateStr).replace(" ", "T"));
    if (isNaN(target)) {
        return null;
    }
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    target.setHours(0, 0, 0, 0);
    return Math.round((target - today) / 86400000);
}

function dateStateInfo(o) {
    if (Number(o.state) === 4) {
        return { icon: ICONS.check, label: "Eseguita", cls: "fb-st-done" };
    }
    const days = daysRemaining(o.expiration_date);
    if (days !== null && days < 0) {
        return { icon: ICONS.alert, label: "Scaduta", cls: "fb-st-overdue" };
    }
    if (days !== null && days <= DATE_MARGIN) {
        return { icon: ICONS.bell, label: "In scadenza", cls: "fb-st-soon" };
    }
    switch (Number(o.state)) {
        case 1:
            return { icon: ICONS.bell, label: "Notificata", cls: "fb-st-info" };
        case 2:
            return { icon: ICONS.calendar, label: "Pianificata", cls: "fb-st-info" };
        case 3:
            return { icon: ICONS.alert, label: "Scaduta", cls: "fb-st-overdue" };
        default:
            return { icon: ICONS.clock, label: "In attesa", cls: "fb-st-muted" };
    }
}

function formatDateState(value, row) {
    const s = dateStateInfo(row);
    return `<span class="fb-state ${s.cls}">${s.icon}<span>${escapeHtml(s.label)}</span></span>`;
}

function formatKmMissing(v, row) {
    if (row && Number(row.state) === 4) {
        return ""; // scadenza eseguita: la differenza non ha più senso
    }
    if (v === null || v === undefined || v === "") {
        return "—";
    }
    const n = Number(v);
    if (n <= 0) {
        return `<span class="fb-km-miss-neg">−${Math.abs(n).toLocaleString("it-IT")} km</span>`;
    }
    if (n <= KM_MARGIN) {
        return `<span class="fb-km-miss-soon">${n.toLocaleString("it-IT")} km</span>`;
    }
    return `<span class="fb-km-miss-ok">${n.toLocaleString("it-IT")} km</span>`;
}

function formatDaysRemaining(v, row) {
    if (row && Number(row.state) === 4) {
        return ""; // scadenza eseguita: la differenza non ha più senso
    }
    if (v === null || v === undefined || v === "") {
        return "—";
    }
    const n = Number(v);
    if (n < 0) {
        return `<span class="fb-days-neg">−${Math.abs(n)} gg</span>`;
    }
    if (n <= DATE_MARGIN) {
        return `<span class="fb-days-soon">${n} gg</span>`;
    }
    return `<span class="fb-days-ok">${n} gg</span>`;
}

function initDateTable() {
    if (!window.$ || !window.$.fn.bootstrapTable) {
        setTimeout(initDateTable, 100);
        return;
    }
    dateTableInitialized = true;

    window.$("#date-table").bootstrapTable({
        url: window.FB.baseUrl + "api/expirations/occurrences?date_only=1",
        sidePagination: "client",
        pagination: true,
        search: false,
        sortable: true,
        filterControl: true,
        locale: "it-IT",
        responseHandler: (res) => {
            (res.rows || []).forEach((r) => {
                r.state_text = dateStateInfo(r).label;
                r.days_remaining = daysRemaining(r.expiration_date);
            });
            (res.rows || []).sort(sortByVehicleTag);
            return res;
        },
        rowAttributes: (row) => ({ "data-id": row.id_expiration_occurrence }),
        rowStyle: (r) => (Number(r.hidden) === 1 ? { classes: "fb-row-hidden" } : {}),
        columns: [
            { field: "_ck", checkbox: true, align: "center", valign: "middle" },
            { field: "vehicle_plate", title: "Veicolo", sortable: true, filterControl: "input", filterCustomSearch: window.fbFilterSearch, formatter: (v) => escapeHtml(v || "—") },
            { field: "tag_name", title: "Voce", sortable: true, filterControl: "input", filterCustomSearch: window.fbFilterSearch, formatter: (v, r) => escapeHtml(r.tag_name || r.description || "—") },
            { field: "expiration_date", title: "Scadenza", sortable: true, filterControl: "input", filterCustomSearch: window.fbFilterSearch, formatter: (v) => fmtDate(v) },
            {
                field: "days_remaining",
                title: "Giorni mancanti",
                sortable: true,
                align: "right",
                filterControl: "input",
                filterCustomSearch: window.fbFilterSearch,
                formatter: formatDaysRemaining,
            },
            {
                field: "state_text",
                title: "Stato",
                sortable: true,
                align: "center",
                formatter: (v, row) => formatDateState(row.state, row),
                searchFormatter: false,
                filterControl: "select",
                filterData: "var:kmStateOptions",
            },
            occActionsColumn,
        ],
        onClickRow: (row, $el, field) => {
            if (field !== "_ck" && field !== "_actions") {
                openOccurrenceDialog(row);
            }
        },
    });
    bindSelectionCounter("#date-table");
}

/* ---------- Scadenze per veicolo (tabella + dettaglio) ---------- */

window.expTypeOptions = { "": "Tutti", date: "Data", km: "Km" };

function occurrenceStateInfo(o) {
    return o.expiration_date ? dateStateInfo(o) : kmStateInfo(o);
}

function formatOccurrenceState(value, row) {
    const s = occurrenceStateInfo(row);
    return `<span class="fb-state ${s.cls}">${s.icon}<span>${escapeHtml(s.label)}</span></span>`;
}

function vehicleExpColumns() {
    return [
        { field: "_ck", checkbox: true, align: "center", valign: "middle" },
        {
            field: "tipo",
            title: "Tipo",
            sortable: true,
            align: "center",
            searchFormatter: false,
            filterControl: "select",
            filterData: "var:expTypeOptions",
            formatter: (v) => (v === "km" ? '<span class="fb-kind-badge fb-kind-km">Km</span>' : '<span class="fb-kind-badge fb-kind-date">Data</span>'),
        },
        { field: "vehicle_plate", title: "Targa", sortable: true, filterControl: "input", filterCustomSearch: window.fbFilterSearch, formatter: (v) => escapeHtml(v || "—") },
        { field: "tag_name", title: "Voce", sortable: true, filterControl: "input", filterCustomSearch: window.fbFilterSearch, formatter: (v, r) => escapeHtml(r.tag_name || r.description || "—") },
        { field: "km", title: "Scadenza a km", sortable: true, align: "right", filterControl: "input", filterCustomSearch: window.fbFilterSearch, formatter: (v) => fmtKm(v) },
        { field: "current_km", title: "Km attuali", sortable: true, align: "right", filterControl: "input", filterCustomSearch: window.fbFilterSearch, formatter: (v) => fmtKm(v) },
        {
            field: "km_missing",
            title: "Km mancanti",
            sortable: true,
            align: "right",
            filterControl: "input",
            filterCustomSearch: window.fbFilterSearch,
            formatter: formatKmMissing,
        },
        { field: "expiration_date", title: "Data scadenza", sortable: true, filterControl: "input", filterCustomSearch: window.fbFilterSearch, formatter: (v) => fmtDate(v) },
        {
            field: "days_remaining",
            title: "Giorni mancanti",
            sortable: true,
            align: "right",
            filterControl: "input",
            filterCustomSearch: window.fbFilterSearch,
            formatter: formatDaysRemaining,
        },
        {
            field: "state_text",
            title: "Stato",
            sortable: true,
            align: "center",
            formatter: (v, row) => formatOccurrenceState(row.state, row),
            searchFormatter: false,
            filterControl: "select",
            filterData: "var:kmStateOptions",
        },
        occActionsColumn,
    ];
}

function vehicleExpResponse(res) {
    (res.rows || []).forEach((r) => {
        r.tipo = r.expiration_date ? "date" : "km";
        r.state_text = occurrenceStateInfo(r).label;
        r.km_missing = r.km ? Number(r.km) - Number(r.current_km || 0) : null;
        r.days_remaining = r.expiration_date ? daysRemaining(r.expiration_date) : null;
    });
    (res.rows || []).sort(sortByVehicleTag);
    return res;
}

function initVehicleTable() {
    if (!window.$ || !window.$.fn.bootstrapTable) {
        setTimeout(initVehicleTable, 100);
        return;
    }
    vehicleTableInitialized = true;

    window.$("#vehicle-table").bootstrapTable({
        url: window.FB.baseUrl + "api/vehicles?active=1",
        sidePagination: "client",
        pagination: true,
        search: false,
        sortable: true,
        filterControl: true,
        locale: "it-IT",
        detailView: true,
        detailFormatter: (index, row) => `<div class="fb-vexp-detail"><table class="fb-table veh-exp-table" data-id-vehicle="${row.id_vehicle}"></table></div>`,
        onExpandRow: (index, row, $detail) => {
            const $inner = $detail.find("table.veh-exp-table");
            $inner.bootstrapTable({
                url: `${window.FB.baseUrl}api/expirations/occurrences?id_vehicle=${row.id_vehicle}${showHiddenExpirations ? "&include_hidden=1" : ""}`,
                sidePagination: "client",
                pagination: true,
                search: false,
                sortable: true,
                filterControl: true,
                locale: "it-IT",
                responseHandler: vehicleExpResponse,
                rowAttributes: (r) => ({ "data-id": r.id_expiration_occurrence }),
                rowStyle: (r) => (Number(r.hidden) === 1 ? { classes: "fb-row-hidden" } : {}),
                columns: vehicleExpColumns(),
                onClickRow: (r, $el, field) => {
                    if (field !== "_ck" && field !== "_actions") {
                        openOccurrenceDialog(r);
                    }
                },
            });
            bindSelectionCounter($inner);
        },
        columns: [
            { field: "plate", title: "Targa", sortable: true, filterControl: "input", filterCustomSearch: window.fbFilterSearch, formatter: (v) => escapeHtml(v || "—") },
            {
                field: "vehicle_name",
                title: "Veicolo",
                sortable: true,
                filterControl: "input",
                filterCustomSearch: window.fbFilterSearch,
                formatter: (v, r) => escapeHtml([r.brand_name, r.description].filter(Boolean).join(" — ") || "—"),
            },
            { field: "current_km", title: "Km attuali", sortable: true, align: "right", filterControl: "input", filterCustomSearch: window.fbFilterSearch, formatter: (v) => fmtKm(v) },
        ],
        responseHandler: (res) => {
            (res.rows || []).forEach((r) => {
                r.vehicle_name = [r.brand_name, r.description].filter(Boolean).join(" — ");
            });
            return res;
        },
    });
}

/* ---------- Storico scadenziario (una riga per veicolo+voce) ---------- */

function storicoOccColumns() {
    return [{ field: "scadenza", title: "Scadenza", sortable: true, formatter: (v, r) => (r.expiration_date ? fmtDate(r.expiration_date) : fmtKm(r.km)) }, { field: "state_text", title: "Stato", sortable: true, align: "center", formatter: (v, r) => formatOccurrenceState(r.state, r), searchFormatter: false }, { field: "done_date", title: "Eseguita il", sortable: true, formatter: (v) => (v ? fmtDate(v) : "—") }, occActionsColumn];
}

function initStoricoTable() {
    if (!window.$ || !window.$.fn.bootstrapTable) {
        setTimeout(initStoricoTable, 100);
        return;
    }
    storicoTableInitialized = true;

    window.$("#storico-table").bootstrapTable({
        url: window.FB.baseUrl + "api/expirations",
        sidePagination: "client",
        pagination: true,
        search: false,
        sortable: true,
        filterControl: true,
        locale: "it-IT",
        rowAttributes: (row) => ({ "data-id": row.id_expiration }),
        rowStyle: (r) => (Number(r.hidden) === 1 ? { classes: "fb-row-hidden" } : {}),
        detailView: true,
        detailFormatter: (index, row) => `<div class="fb-vexp-detail"><table class="fb-table storico-exp-table" data-id-expiration="${row.id_expiration}"></table></div>`,
        onExpandRow: (index, row, $detail) => {
            const $inner = $detail.find("table.storico-exp-table");
            $inner.bootstrapTable({
                url: `${window.FB.baseUrl}api/expirations/occurrences?id_expiration=${row.id_expiration}&include_hidden=1`,
                sidePagination: "client",
                pagination: false,
                search: false,
                sortable: true,
                locale: "it-IT",
                responseHandler: (res) => {
                    // dalla scadenza più recente/lontana alla più vecchia
                    const rows = (res.rows || []).map((r) => {
                        r.state_text = occurrenceStateInfo(r).label;
                        return r;
                    });
                    rows.sort((a, b) => {
                        if (a.expiration_date && b.expiration_date) {
                            return String(b.expiration_date).localeCompare(String(a.expiration_date));
                        }
                        return Number(b.km || 0) - Number(a.km || 0);
                    });
                    res.rows = rows;
                    return res;
                },
                rowAttributes: (r) => ({ "data-id": r.id_expiration_occurrence }),
                rowStyle: (r) => (Number(r.hidden) === 1 ? { classes: "fb-row-hidden" } : {}),
                columns: storicoOccColumns(),
                onClickRow: (r, $el, field) => {
                    if (field !== "_actions") {
                        openOccurrenceDialog(r);
                    }
                },
            });
        },
        columns: [
            { field: "vehicle_plate", title: "Veicolo", sortable: true, filterControl: "input", filterCustomSearch: window.fbFilterSearch, formatter: (v) => escapeHtml(v || "—") },
            { field: "tag_name", title: "Voce", sortable: true, filterControl: "input", filterCustomSearch: window.fbFilterSearch, formatter: (v, r) => escapeHtml(r.tag_name || r.description || "—") },
            { field: "current_km", title: "Km attuali", sortable: true, align: "right", filterControl: "input", filterCustomSearch: window.fbFilterSearch, formatter: (v) => fmtKm(v) },
        ],
    });
}

/* ---------- Selezione e stampa ---------- */

function activeTableSelector() {
    const panel = document.querySelector(".fb-tab-panel.active");
    if (!panel) {
        return null;
    }
    if (panel.dataset.panel === "date") {
        return "#date-table";
    }
    if (panel.dataset.panel === "km") {
        return "#km-table";
    }
    if (panel.dataset.panel === "vehicle") {
        return "vehicle";
    }
    return null;
}

function selectedRows() {
    const sel = activeTableSelector();
    if (!sel || !window.$ || typeof window.$.fn.bootstrapTable !== "function") {
        return [];
    }
    if (sel === "vehicle") {
        const rows = [];
        window.$(".veh-exp-table").each(function () {
            try {
                rows.push(...window.$(this).bootstrapTable("getSelections"));
            } catch (e) {
                /* tabella non ancora inizializzata */
            }
        });
        return rows;
    }
    return window.$(sel).bootstrapTable("getSelections");
}

function updateSelectedCounter() {
    const el = document.getElementById("cal-selected-count");
    if (!el) {
        return;
    }
    const n = selectedRows().length;
    el.textContent = `${n} selezionat${n === 1 ? "a" : "e"}`;
}

function bindSelectionCounter(tableSel) {
    window.$(tableSel).on("check.bs.table uncheck.bs.table check-all.bs.table uncheck-all.bs.table load-success.bs.table", () => updateSelectedCounter());
}

async function printSelected() {
    const rows = selectedRows();
    if (rows.length === 0) {
        await dialog.alert("Seleziona almeno una riga da stampare.", "Attenzione");
        return;
    }
    if (rows.length > 500) {
        await dialog.alert(`Puoi stampare al massimo 500 righe alla volta (selezionate: ${rows.length}).`, "Attenzione");
        return;
    }
    const ids = rows.map((r) => Number(r.id_expiration_occurrence)).filter((i) => i > 0);

    const form = document.createElement("form");
    form.method = "POST";
    form.action = `${window.FB.baseUrl}admin/calendar/print`;
    form.target = "_blank";
    form.style.display = "none";
    const input = document.createElement("input");
    input.type = "hidden";
    input.name = "ids";
    input.value = JSON.stringify(ids);
    form.appendChild(input);
    document.body.appendChild(form);
    form.submit();
    form.remove();
}

/* ---------- Legenda stati ---------- */

function openLegend() {
    const items = [
        { cls: "fb-st-done", icon: ICONS.check, label: "Eseguita", desc: "La scadenza è stata completata (collegata a un tagliando o segnata come eseguita)." },
        { cls: "fb-st-overdue", icon: ICONS.alert, label: "Scaduta / Superata", desc: "La data è passata oppure il veicolo ha già superato il chilometraggio previsto." },
        { cls: "fb-st-soon", icon: ICONS.bell, label: "In scadenza", desc: `Manca poco: entro ${DATE_MARGIN} giorni dalla data oppure entro ${KM_MARGIN.toLocaleString("it-IT")} km.` },
        { cls: "fb-st-info", icon: ICONS.bell, label: "Notificata", desc: "Scadenza segnalata ma non ancora pianificata." },
        { cls: "fb-st-info", icon: ICONS.calendar, label: "Pianificata", desc: "Intervento già pianificato in officina." },
        { cls: "fb-st-muted", icon: ICONS.clock, label: "In attesa", desc: "Scadenza registrata, non ancora in finestra di attenzione." },
    ];
    const d = document.createElement("dialog");
    d.className = "fb-dialog";
    d.innerHTML = `
        <div class="fb-dialog-header"><h3 class="fb-dialog-title">Legenda stati</h3></div>
        <div class="fb-dialog-body">
            <ul class="fb-legend-list">
                ${items.map((i) => `<li><span class="fb-state ${i.cls}">${i.icon}<span>${i.label}</span></span><span class="fb-legend-desc">${i.desc}</span></li>`).join("")}
            </ul>
            <div class="fb-form-actions">
                <button type="button" class="fb-btn fb-btn-secondary" data-action="close">Chiudi</button>
            </div>
        </div>
    `;
    document.body.appendChild(d);
    d.showModal();
    d.querySelector('[data-action="close"]').addEventListener("click", () => d.close());
    d.addEventListener("close", () => d.remove(), { once: true });
}

/* ---------- Nuova scadenza ---------- */

async function loadExpirationOptions() {
    if (vehiclesCache !== null && tagsCache !== null) {
        return;
    }
    const [vehicles, tags] = await Promise.all([FetchHelper.get(`${window.FB.baseUrl}api/vehicles`), FetchHelper.get(`${window.FB.baseUrl}api/expiration-tags`)]);
    vehiclesCache = (vehicles.rows || []).filter((v) => (v.status ?? "active") !== "retired");
    tagsCache = tags.rows || [];
}

async function openNewExpiration() {
    try {
        await loadExpirationOptions();
    } catch (err) {
        await dialog.error(err);
        return;
    }

    const tpl = document.getElementById("tpl-expiration-new");
    const form = tpl.content.cloneNode(true).querySelector("form");

    const vehicleSel = form.querySelector('[data-field="vehicle"]');
    vehicleSel.innerHTML = '<option value="">— Seleziona veicolo —</option>' + vehiclesCache.map((v) => `<option value="${v.id_vehicle}" data-search="${escapeHtml(`${v.plate || ""} ${v.brand_name || ""} ${v.description || ""}`)}">${escapeHtml(v.plate || "")}${v.brand_name ? " — " + escapeHtml(v.brand_name) : ""}</option>`).join("");

    const tagSel = form.querySelector('[data-field="tag"]');
    tagSel.innerHTML = '<option value="">— Nessuna —</option>' + tagsCache.map((t) => `<option value="${t.id_expiration_tag}">${escapeHtml(t.name)}</option>`).join("");

    const d = document.createElement("dialog");
    d.className = "fb-dialog fb-dialog-medium";
    d.innerHTML = `<div class="fb-dialog-header"><h3 class="fb-dialog-title">Nuova scadenza</h3></div><div class="fb-dialog-body"></div>`;
    d.querySelector(".fb-dialog-body").appendChild(form);
    document.body.appendChild(d);
    d.showModal();

    const kindSel = form.querySelector('[data-field="kind"]');
    const dateInput = form.querySelector("#occ_date");
    const kmInput = form.querySelector("#occ_km");
    const kmBadge = form.querySelector('[data-field="km-badge"]');
    const kmBadgeValue = form.querySelector('[data-field="km-badge-value"]');
    const summary = form.querySelector('[data-field="exp-summary"]');
    const expName = form.querySelector('[data-field="exp-name"]');
    const expChip = form.querySelector('[data-field="exp-chip"]');
    const expFlow = form.querySelector('[data-field="exp-flow"]');
    const expLast = form.querySelector('[data-field="exp-last"]');
    const expNext = form.querySelector('[data-field="exp-next"]');
    const expHint = form.querySelector('[data-field="exp-hint"]');
    const vehicleOccCache = {};
    let sugSeq = 0;

    async function vehicleOccurrences(idVehicle) {
        if (!(idVehicle in vehicleOccCache)) {
            const res = await FetchHelper.get(`${window.FB.baseUrl}api/expirations/occurrences?id_vehicle=${idVehicle}&include_hidden=1`);
            vehicleOccCache[idVehicle] = res.rows || [];
        }
        return vehicleOccCache[idVehicle];
    }

    async function updateSuggestion() {
        const seq = ++sugSeq;
        // SearchableSelect rimuove il select: il valore sta nell'hidden input omonimo
        const idVehicle = form.querySelector('[name="id_vehicle"]').value;
        const v = vehiclesCache.find((x) => String(x.id_vehicle) === String(idVehicle));
        const tag = tagsCache.find((x) => String(x.id_expiration_tag) === String(tagSel.value));
        kmBadge.hidden = !v;
        if (v) {
            kmBadgeValue.textContent = `${Number(v.current_km || 0).toLocaleString("it-IT")} km`;
        }
        if (!tag) {
            summary.hidden = true;
            return;
        }
        const kind = tag.kind === "km" ? "km" : "date";
        if (kindSel.value !== kind) {
            kindSel.value = kind;
            kindSel.dispatchEvent(new Event("change"));
        }
        summary.hidden = false;
        expName.textContent = tag.name;
        expChip.textContent = tag.interval_label || (kind === "km" ? "A chilometri" : "A data");
        if (!v) {
            expFlow.hidden = true;
            expHint.hidden = false;
            return;
        }
        const occs = (await vehicleOccurrences(v.id_vehicle)).filter((o) => String(o.id_expiration_tag) === String(tag.id_expiration_tag));
        if (seq !== sugSeq) {
            return; // selezione cambiata nel frattempo
        }
        expFlow.hidden = false;
        expHint.hidden = true;
        const iv = Number(tag.interval_value) || 0;
        if (kind === "km") {
            const last = occs.reduce((best, o) => (o.km !== null && o.km !== undefined && Number(o.km) > Number(best ? best.km : -1) ? o : best), null);
            const base = last ? Number(last.km) : null;
            const next = iv ? (base !== null ? base : Number(v.current_km || 0)) + iv : null;
            expLast.textContent = base !== null ? fmtKm(base) : "mai registrata";
            expNext.textContent = next !== null ? fmtKm(next) : "—";
            if (next !== null) {
                kmInput.value = next;
            }
        } else {
            const last = occs.reduce((best, o) => (o.expiration_date && String(o.expiration_date) > String(best ? best.expiration_date : "") ? o : best), null);
            const base = last ? last.expiration_date : null;
            const next = iv ? addDays(base || new Date().toISOString().slice(0, 10), tag.interval_unit || "days", iv) : null;
            expLast.textContent = base ? fmtDate(base) : "mai registrata";
            expNext.textContent = next ? fmtDate(next) : "—";
            if (next) {
                dateInput.value = next;
            }
        }
    }

    new SearchableSelect(vehicleSel, { placeholder: "Cerca per targa, marca o descrizione…", onChange: updateSuggestion });
    tagSel.addEventListener("change", updateSuggestion);

    kindSel.addEventListener("change", () => {
        const isKm = kindSel.value === "km";
        form.querySelector('[data-field="date-wrap"]').hidden = isKm;
        form.querySelector('[data-field="km-wrap"]').hidden = !isKm;
    });

    form.addEventListener("submit", async (ev) => {
        ev.preventDefault();
        const data = Object.fromEntries(new FormData(form).entries());
        if (!data.id_vehicle) {
            await dialog.alert("Seleziona un veicolo.", "Attenzione");
            return;
        }
        try {
            await FetchHelper.post(`${window.FB.baseUrl}api/expirations`, data);
            d.close();
            loadOccurrences();
            loadAlerts();
            if (kmTableInitialized) {
                window.$("#km-table").bootstrapTable("refresh");
            }
            if (dateTableInitialized) {
                window.$("#date-table").bootstrapTable("refresh");
            }
        } catch (err) {
            await dialog.error(err);
        }
    });

    form.querySelector('[data-action="close"]').addEventListener("click", () => d.close());
    d.addEventListener("close", () => d.remove(), { once: true });
}

/* ---------- Allerte ---------- */

async function loadAlerts() {
    const list = document.querySelector('[data-field="alerts-list"]');
    const badge = document.querySelector('[data-field="alerts-badge"]');
    if (!list) {
        return;
    }
    try {
        const res = await FetchHelper.get(`${window.FB.baseUrl}api/expirations/alerts?days=30&km=${KM_MARGIN}`);
        const rows = res.rows || [];
        if (badge) {
            badge.textContent = String(rows.length);
        }
        list.innerHTML = "";
        if (rows.length === 0) {
            list.innerHTML = '<div class="fb-alert-item fb-muted" style="cursor:default">Nessuna scadenza in avvicinamento.</div>';
            return;
        }
        const todayStr = new Date().toISOString().slice(0, 10);
        for (const o of rows) {
            const item = document.createElement("div");
            item.className = "fb-alert-item";
            const isOverdue = o.expiration_date ? o.expiration_date < todayStr : o.km !== null && Number(o.km) <= Number(o.current_km || 0);
            const due = o.expiration_date ? fmtDate(o.expiration_date) : fmtKm(o.km);
            item.innerHTML = `
                <span class="fb-alert-plate">${escapeHtml(o.vehicle_plate || "—")}</span>
                <span>${escapeHtml(o.tag_name || o.description || "Scadenza")}</span>
                <span class="fb-muted">${due}</span>
                <span class="fb-alert-badge ${isOverdue ? "fb-alert-overdue" : "fb-alert-soon"}">${isOverdue ? "Scaduta" : "In scadenza"}</span>
            `;
            item.addEventListener("click", () => openOccurrenceDialog(o));
            list.appendChild(item);
        }
    } catch (err) {
        /* allerte non bloccanti */
    }
}

/* ---------- Dialog scadenze del giorno ---------- */

function openDayDialog(iso, items) {
    const [y, m, dd] = iso.split("-").map(Number);
    const d = document.createElement("dialog");
    d.className = "fb-dialog";
    d.innerHTML = `
        <div class="fb-dialog-header"><h3 class="fb-dialog-title">Scadenze — ${dd} ${MONTHS[m - 1]} ${y}</h3></div>
        <div class="fb-dialog-body">
            <div class="fb-alerts-list fb-alerts-open"></div>
            <div class="fb-form-actions">
                <button type="button" class="fb-btn fb-btn-secondary" data-action="close">Chiudi</button>
            </div>
        </div>
    `;
    const list = d.querySelector(".fb-alerts-list");
    const todayStr = new Date().toISOString().slice(0, 10);
    for (const o of items) {
        const item = document.createElement("div");
        item.className = "fb-alert-item fb-day-item";
        const s = occurrenceStateInfo(o);
        const isOverdue = o.expiration_date ? o.expiration_date < todayStr && Number(o.state) !== 4 : o.km !== null && Number(o.km) <= Number(o.current_km || 0);
        item.innerHTML = `
            <span class="fb-day-plate">${escapeHtml(o.vehicle_plate || "—")}</span>
            <div class="fb-day-info">
                <span class="fb-day-tag">${escapeHtml(o.tag_name || o.description || "Scadenza")}</span>
                <span class="fb-day-due">${o.expiration_date ? fmtDate(o.expiration_date) : fmtKm(o.km)}</span>
            </div>
            <span class="fb-state ${s.cls}">${s.icon}<span>${escapeHtml(isOverdue && s.label !== "Eseguita" ? "Scaduta" : s.label)}</span></span>
        `;
        item.addEventListener("click", () => {
            openOccurrenceDialog(o);
        });
        list.appendChild(item);
    }
    document.body.appendChild(d);
    d.showModal();
    d.querySelector('[data-action="close"]').addEventListener("click", () => d.close());
    d.addEventListener("close", () => d.remove(), { once: true });
}

/* ---------- Dialog occorrenza ---------- */

function openOccurrenceDialog(o) {
    const d = document.createElement("dialog");
    d.className = "fb-dialog";
    d.innerHTML = `
        <div class="fb-dialog-header"><h3 class="fb-dialog-title">${escapeHtml(o.tag_name || o.description || "Scadenza")}</h3></div>
        <div class="fb-dialog-body">
            <dl class="fb-view-grid"></dl>
            <div class="fb-form-actions">
                <button type="button" class="fb-btn fb-btn-primary" data-action="done"></button>
                <button type="button" class="fb-btn fb-btn-renew" data-action="renew">Rinnova</button>
                <button type="button" class="fb-btn fb-btn-secondary" data-action="close">Chiudi</button>
            </div>
        </div>
    `;
    document.body.appendChild(d);
    d.showModal();

    // Riempie/aggiorna i campi info (chiamata anche dopo i salvataggi
    // nei dialog figli, che si aprono in cima senza chiudere questa scheda)
    const renderInfo = () => {
        const isDone = Number(o.state) === 4;
        d.querySelector(".fb-view-grid").innerHTML = `
            ${viewItem("Veicolo", o.vehicle_plate)}
            ${viewItem("Tipo", o.is_km_deadline || (o.km && !o.expiration_date) ? "Km" : "Data")}
            ${viewItem("Scadenza", o.expiration_date ? fmtDate(o.expiration_date) : fmtKm(o.km))}
            ${viewItem("Stato", o.state_label)}
            ${isDone ? viewItem("Eseguita il", fmtDate(o.done_date || o.date_upd)) : ""}
            ${viewItem("Km attuali", fmtKm(o.current_km))}
        `;
        d.querySelector('[data-action="done"]').textContent = isDone ? "Modifica esecuzione" : "Segna come eseguita";
    };
    renderInfo();

    d.querySelector('[data-action="done"]')?.addEventListener("click", () => {
        openDoneDialog(o, renderInfo);
    });
    d.querySelector('[data-action="renew"]')?.addEventListener("click", () => {
        openRenewDialog(o);
    });
    d.querySelector('[data-action="close"]').addEventListener("click", () => d.close());
    d.addEventListener("close", () => d.remove(), { once: true });
}

/* ---------- Dialog "rinnova scadenza" ---------- */

function openRenewDialog(o) {
    const isKm = o.is_km_deadline || (o.km !== null && o.km !== undefined && !o.expiration_date) || o.expiration_kind === "km" || o.tag_kind === "km";
    const iv = Number(o.interval_value) || 0;
    const unit = o.interval_unit || (isKm ? "km" : "months");
    const today = new Date().toISOString().slice(0, 10);
    const curKm = Number(o.current_km || 0);
    const newDate = iv ? addDays(today, unit, iv) : null;
    const newKm = iv ? curKm + iv : null;
    const computed = isKm ? newKm : newDate;
    const tagName = o.tag_name || o.description || "Scadenza";
    const unitLabels = { km: "km", days: "giorni", months: "mesi", years: "anni" };
    const intervalLabel = iv ? `Ogni ${iv.toLocaleString("it-IT")} ${unitLabels[unit] || unit}` : "Intervallo non impostato";

    const d = document.createElement("dialog");
    d.className = "fb-dialog fb-dialog-medium";
    d.innerHTML = `
        <div class="fb-dialog-header"><h3 class="fb-dialog-title">Rinnova — ${escapeHtml(tagName)}</h3></div>
        <div class="fb-dialog-body">
            <div class="fb-renew-info">
                <div class="fb-renew-field"><span>Targa</span><strong>${escapeHtml(o.vehicle_plate || "—")}</strong></div>
                <div class="fb-renew-field"><span>Data odierna</span><strong>${fmtDate(today)}</strong></div>
                <div class="fb-renew-field"><span>Km attuali</span><strong>${fmtKm(curKm)}</strong></div>
            </div>
            <div class="fb-renew-banner">
                <div class="fb-exp-summary-head" style="justify-content:center">
                    <strong>${escapeHtml(tagName)}</strong>
                    <span class="fb-exp-chip">${escapeHtml(intervalLabel)}</span>
                </div>
                <div class="fb-renew-next">Nuova scadenza: <strong>${computed !== null ? (isKm ? fmtKm(computed) : fmtDate(computed)) : "—"}</strong></div>
            </div>
            <form class="fb-form" data-form="renew">
                <div class="fb-form-group" data-field="renew-date-wrap" ${isKm ? "hidden" : ""}>
                    <label class="fb-form-label" for="renew_date">Nuova data</label>
                    <input type="date" id="renew_date" name="expiration_date" class="fb-form-input" value="${newDate || ""}">
                </div>
                <div class="fb-form-group" data-field="renew-km-wrap" ${isKm ? "" : "hidden"}>
                    <label class="fb-form-label" for="renew_km">Nuovi km</label>
                    <input type="number" id="renew_km" name="km" class="fb-form-input" min="0" step="1" value="${newKm ?? ""}">
                </div>
                <div class="fb-exp-hint" data-field="renew-warn" hidden>Attenzione: il valore inserito è inferiore ${isKm ? "ai km attuali" : "alla data odierna"}</div>
                <div class="fb-form-actions">
                    <button type="submit" class="fb-btn fb-btn-renew">Salva rinnovo</button>
                    <button type="button" class="fb-btn fb-btn-secondary" data-action="close">Annulla</button>
                </div>
            </form>
        </div>`;
    document.body.appendChild(d);
    d.showModal();

    const input = d.querySelector(isKm ? "#renew_km" : "#renew_date");
    const warn = d.querySelector('[data-field="renew-warn"]');
    input.addEventListener("input", () => {
        if (input.value === "") {
            warn.hidden = true;
            return;
        }
        warn.hidden = isKm ? Number(input.value) >= curKm : input.value >= today;
    });

    d.querySelector('[data-form="renew"]').addEventListener("submit", async (ev) => {
        ev.preventDefault();
        const data = { id_expiration: o.id_expiration };
        const v = input.value;
        if (isKm) {
            data.km = v !== "" ? Number(v) : newKm;
        } else {
            data.expiration_date = v !== "" ? v : newDate;
        }
        try {
            await FetchHelper.post(`${window.FB.baseUrl}api/expirations/occurrences`, data);
            d.close();
            refreshExpirationViews();
        } catch (err) {
            await dialog.error(err);
        }
    });
    d.querySelector('[data-action="close"]').addEventListener("click", () => d.close());
    d.addEventListener("close", () => d.remove(), { once: true });
}

/* ---------- Dialog "segna come eseguita" ---------- */

function openDoneDialog(o, onSaved = null) {
    const d = document.createElement("dialog");
    d.className = "fb-dialog fb-dialog-medium";
    const isEdit = Number(o.state) === 4;
    const today = new Date().toISOString().slice(0, 10);
    const doneDate = isEdit && o.done_date ? String(o.done_date).slice(0, 10) : today;
    d.innerHTML = `
        <div class="fb-dialog-header"><h3 class="fb-dialog-title">${isEdit ? "Modifica esecuzione" : "Esecuzione"} — ${escapeHtml(o.tag_name || o.description || "Scadenza")}</h3></div>
        <div class="fb-dialog-body">
            <form class="fb-form" data-form="done">
                <div class="fb-form-grid">
                    <div class="fb-form-group">
                        <label class="fb-form-label" for="done_date">Data esecuzione</label>
                        <input type="date" id="done_date" name="done_date" class="fb-form-input" value="${doneDate}">
                    </div>
                    <div class="fb-form-group">
                        <label class="fb-form-label" for="done_km">Km attuali</label>
                        <input type="number" id="done_km" name="done_km" class="fb-form-input" min="0" step="1" value="${Number(o.current_km || 0)}">
                    </div>
                </div>
                <p class="fb-muted" style="font-size:0.8rem;">I km vengono registrati nel registro chilometrico del veicolo e ne aggiornano il chilometraggio.</p>
                <div class="fb-form-actions">
                    <button type="submit" class="fb-btn fb-btn-primary">${isEdit ? "Salva modifiche" : "Conferma esecuzione"}</button>
                    <button type="button" class="fb-btn fb-btn-secondary" data-action="close">Annulla</button>
                </div>
            </form>
        </div>
    `;
    document.body.appendChild(d);
    d.showModal();

    d.querySelector('[data-form="done"]').addEventListener("submit", async (ev) => {
        ev.preventDefault();
        const data = Object.fromEntries(new FormData(ev.target).entries());
        try {
            await FetchHelper.post(`${window.FB.baseUrl}api/expirations/occurrences/${o.id_expiration_occurrence}/update`, {
                state: 4,
                done_date: data.done_date,
                done_km: data.done_km,
            });
            o.state = 4;
            o.state_label = "Eseguita";
            o.done_date = data.done_date;
            o.current_km = Math.max(Number(o.current_km || 0), Number(data.done_km || 0));
            onSaved?.();
            d.close();
            loadOccurrences();
            loadAlerts();
            if (kmTableInitialized && window.$ && window.$.fn.bootstrapTable) {
                window.$("#km-table").bootstrapTable("refresh");
            }
            if (dateTableInitialized && window.$ && window.$.fn.bootstrapTable) {
                window.$("#date-table").bootstrapTable("refresh");
            }
        } catch (err) {
            await dialog.error(err);
        }
    });

    d.querySelector('[data-action="close"]').addEventListener("click", () => d.close());
    d.addEventListener("close", () => d.remove(), { once: true });
}

/* ---------- Init ---------- */

function initPage() {
    initTabs();
    document.querySelector('[data-action="cal-prev"]')?.addEventListener("click", () => {
        current = new Date(current.getFullYear(), current.getMonth() - 1, 1);
        loadOccurrences();
    });
    document.querySelector('[data-action="cal-next"]')?.addEventListener("click", () => {
        current = new Date(current.getFullYear(), current.getMonth() + 1, 1);
        loadOccurrences();
    });
    document.querySelector('[data-action="cal-today"]')?.addEventListener("click", () => {
        current = new Date();
        loadOccurrences();
    });
    document.querySelector('[data-action="print"]')?.addEventListener("click", printSelected);
    document.querySelector('[data-action="legend"]')?.addEventListener("click", openLegend);
    document.querySelector('[data-action="new-expiration"]')?.addEventListener("click", openNewExpiration);
    document.querySelectorAll('[data-field="show-hidden"]').forEach((sw) => {
        sw.addEventListener("change", (ev) => {
            showHiddenExpirations = ev.target.checked;
            // gli switch delle varie schede restano allineati
            document.querySelectorAll('[data-field="show-hidden"]').forEach((o) => {
                o.checked = showHiddenExpirations;
            });
            refreshExpirationViews();
        });
    });

    loadConfig().then(() => {
        loadOccurrences();
        loadAlerts();
    });
}

async function loadConfig() {
    try {
        const cfg = await FetchHelper.get(`${window.FB.baseUrl}api/settings/config`);
        if (Number(cfg.expiration_alert_days) > 0) {
            DATE_MARGIN = Number(cfg.expiration_alert_days);
        }
        if (Number(cfg.expiration_alert_km) > 0) {
            KM_MARGIN = Number(cfg.expiration_alert_km);
        }
    } catch (err) {
        /* soglie di default */
    }
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initPage);
} else {
    initPage();
}
