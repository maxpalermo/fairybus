import FetchHelper from "../core/FetchHelper.js";
import DialogHelper from "../core/DialogHelper.js";
import { viewItem } from "../components/ViewGrid.js";

const dialog = new DialogHelper();

const MONTHS = ["Gennaio", "Febbraio", "Marzo", "Aprile", "Maggio", "Giugno", "Luglio", "Agosto", "Settembre", "Ottobre", "Novembre", "Dicembre"];
const DOW = ["Lun", "Mar", "Mer", "Gio", "Ven", "Sab", "Dom"];
const KM_MARGIN = 2000;

let current = new Date(); // mese visualizzato
let occurrences = [];
let kmTableInitialized = false;

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

const ICONS = {
    check: '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>',
    alert: '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>',
    clock: '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>',
    bell: '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>',
    calendar: '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>',
};

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
            // le frecce di navigazione servono solo nel calendario
            const actions = document.querySelector('[data-field="cal-actions"]');
            if (actions) {
                actions.style.visibility = tab.dataset.tab === "calendario" ? "visible" : "hidden";
            }
            if (tab.dataset.tab === "km" && !kmTableInitialized) {
                initKmTable();
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
            chip.addEventListener("click", () => openOccurrenceDialog(o));
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
            return res;
        },
        rowAttributes: (row) => ({ "data-id": row.id_expiration_occurrence }),
        columns: [
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
                formatter: (v) => (Number(v) > 0 ? `${Number(v).toLocaleString("it-IT")} km` : "—"),
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
        ],
        onClickRow: (row) => openOccurrenceDialog(row),
    });
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

/* ---------- Dialog occorrenza ---------- */

function openOccurrenceDialog(o) {
    const d = document.createElement("dialog");
    d.className = "fb-dialog";
    const isDone = Number(o.state) === 4;
    d.innerHTML = `
        <div class="fb-dialog-header"><h3 class="fb-dialog-title">${escapeHtml(o.tag_name || o.description || "Scadenza")}</h3></div>
        <div class="fb-dialog-body">
            <dl class="fb-view-grid">
                ${viewItem("Veicolo", o.vehicle_plate)}
                ${viewItem("Tipo", o.is_km_deadline || (o.km && !o.expiration_date) ? "Km" : "Data")}
                ${viewItem("Scadenza", o.expiration_date ? fmtDate(o.expiration_date) : fmtKm(o.km))}
                ${viewItem("Stato", o.state_label)}
                ${viewItem("Km attuali", fmtKm(o.current_km))}
            </dl>
            <div class="fb-form-actions">
                ${isDone ? "" : '<button type="button" class="fb-btn fb-btn-primary" data-action="done">Segna come eseguita</button>'}
                <button type="button" class="fb-btn fb-btn-secondary" data-action="close">Chiudi</button>
            </div>
        </div>
    `;
    document.body.appendChild(d);
    d.showModal();

    d.querySelector('[data-action="done"]')?.addEventListener("click", async () => {
        try {
            await FetchHelper.post(`${window.FB.baseUrl}api/expirations/occurrences/${o.id_expiration_occurrence}/update`, { state: 4 });
            d.close();
            loadOccurrences();
            loadAlerts();
            if (kmTableInitialized && window.$ && window.$.fn.bootstrapTable) {
                window.$("#km-table").bootstrapTable("refresh");
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

    loadOccurrences();
    loadAlerts();
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initPage);
} else {
    initPage();
}
