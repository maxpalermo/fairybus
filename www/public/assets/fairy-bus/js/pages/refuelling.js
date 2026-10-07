import FetchHelper from "../core/FetchHelper.js";
import DialogHelper from "../core/DialogHelper.js";
import Toast from "../core/Toast.js";
import PrintHelper from "../components/PrintHelper.js";
import RefuellingKpis from "../components/RefuellingKpis.js";
import SearchableSelect from "../components/SearchableSelect.js?v=4";
import { viewItem } from "../components/ViewGrid.js";

const dialog = new DialogHelper();
const toast = new Toast();
const BASE = window.FB.baseUrl;
const PAGE_DIRECTION = document.getElementById("refuelling-table")?.dataset.direction === "in" ? "in" : "out";
const kpis = new RefuellingKpis("#refuelling-kpis");

let stationsCache = [];
let fuelTypesCache = [];
let vehiclesCache = [];
let suppliersCache = [];

const ICONS = {
    view: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>',
    edit: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.83 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>',
    trash: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>',
};

function escapeHtml(text) {
    const div = document.createElement("div");
    div.textContent = String(text ?? "");
    return div.innerHTML;
}

function buildDialog(title, size = "") {
    const d = document.createElement("dialog");
    d.className = `fb-dialog${size ? " " + size : ""}`;
    d.innerHTML = `
        <div class="fb-dialog-header">
            <h3 class="fb-dialog-title">${escapeHtml(title)}</h3>
            <button type="button" class="fb-dialog-close" data-action="close" aria-label="Chiudi">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="fb-dialog-body"></div>`;
    d.querySelector(".fb-dialog-close").addEventListener("click", () => d.close());
    document.body.appendChild(d);
    d.showModal();
    d.addEventListener("close", () => d.remove(), { once: true });
    return d;
}

function fmtDate(v) {
    if (!v) return "—";
    const d = new Date(String(v).replace(" ", "T"));
    if (Number.isNaN(d.getTime())) return escapeHtml(v);
    const pad = (n) => String(n).padStart(2, "0");
    return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function fmtKm(v) {
    return v === null || v === undefined || v === "" ? "—" : Number(v).toLocaleString("it-IT");
}

function fmtKmPerLiter(v) {
    return v === null || v === undefined || v === "" ? "—" : `${Number(v).toLocaleString("it-IT", { minimumFractionDigits: 2, maximumFractionDigits: 2 })} km/l`;
}

function fmtLiters(v) {
    return v === null || v === undefined ? "—" : `${Number(v).toLocaleString("it-IT", { minimumFractionDigits: 2 })} l`;
}

function fmtMoney(v) {
    return `€ ${Number(v || 0).toLocaleString("it-IT", { minimumFractionDigits: 2 })}`;
}

async function loadOptions() {
    const [optsRes, vehiclesRes, suppliersRes] = await Promise.all([FetchHelper.get(`${BASE}api/refuelling/options`), FetchHelper.get(`${BASE}api/vehicles`).catch(() => ({ rows: [] })), FetchHelper.get(`${BASE}api/suppliers`).catch(() => ({ rows: [] }))]);
    stationsCache = optsRes.stations || [];
    fuelTypesCache = optsRes.fuel_types || [];
    vehiclesCache = (vehiclesRes.rows || []).filter((v) => (v.status ?? "active") !== "retired");
    suppliersCache = (suppliersRes.rows || []).filter((s) => Number(s.active ?? 1) === 1);
}

function initTable() {
    if (!window.$ || !window.$.fn.bootstrapTable) {
        setTimeout(initTable, 100);
        return;
    }

    window.refuellingActionsEvents = {
        "click .view": (e, value, row) => openRefuellingView(row),
        "click .edit": (e, value, row) => openForm(row),
        "click .delete": async (e, value, row) => {
            const ok = await dialog.confirm(`Eliminare il rifornimento del <strong>${fmtDate(row.refuel_time)}</strong> (${escapeHtml(row.vehicle_plate || "")})?`, "Elimina");
            if (!ok) return;
            try {
                await FetchHelper.post(`${BASE}api/refuelling/${row.id_refuelling}/delete`, {});
                refreshTable();
            } catch (err) {
                await dialog.error(err);
            }
        },
    };

    window.$("#refuelling-table").bootstrapTable({
        url: BASE + "api/refuelling?direction=" + PAGE_DIRECTION,
        sidePagination: "client",
        pagination: true,
        search: false,
        filterControl: true,
        sortable: true,
        locale: "it-IT",
        // evidenzia le righe con chilometraggio non congruo
        rowStyle: (row) => (row.km_error ? { classes: "fb-row-error" } : {}),
        columns: [
            { field: "state", checkbox: true, align: "center", valign: "middle" },
            { field: "refuel_time", title: "Data", sortable: true, formatter: fmtDate, filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            ...(PAGE_DIRECTION === "in" ? [{ field: "supplier_name", title: "Fornitore", sortable: true, formatter: (v) => escapeHtml(v || "—"), filterControl: "input", filterCustomSearch: window.fbFilterSearch }] : [{ field: "vehicle_plate", title: "Automezzo", sortable: true, formatter: (v) => escapeHtml(v || "—"), filterControl: "input", filterCustomSearch: window.fbFilterSearch }]),
            { field: "station_name", title: "Stazione", sortable: true, formatter: (v) => escapeHtml(v || "—"), filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "fuel_type_name", title: "Alimentazione", sortable: true, formatter: (v) => escapeHtml(v || "—"), filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "liters", title: "Litri", sortable: true, align: "right", formatter: fmtLiters, filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "price_per_liter", title: "€/litro", sortable: true, align: "right", formatter: (v) => (Number(v) > 0 ? fmtMoney(v) : "—"), filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            ...(PAGE_DIRECTION === "out"
                ? [
                      { field: "km_prev", title: "Km precedenti", sortable: true, align: "right", formatter: fmtKm, filterControl: "input", filterCustomSearch: window.fbFilterSearch },
                      { field: "km_at_refuel", title: "Km attuali", sortable: true, align: "right", formatter: fmtKm, filterControl: "input", filterCustomSearch: window.fbFilterSearch },
                      { field: "km_diff", title: "Differenza", sortable: true, align: "right", formatter: fmtKm, filterControl: "input", filterCustomSearch: window.fbFilterSearch },
                      { field: "km_per_liter", title: "Km/litro", sortable: true, align: "right", formatter: fmtKmPerLiter, filterControl: "input", filterCustomSearch: window.fbFilterSearch },
                  ]
                : [{ field: "km_at_refuel", title: "Km", sortable: true, align: "right", formatter: fmtKm, filterControl: "input", filterCustomSearch: window.fbFilterSearch }]),
            { field: "actions", title: "Azioni", searchable: false, formatter: formatActions, events: window.refuellingActionsEvents },
        ],
    });
}

function formatActions() {
    return `
        <div class="fb-btn-group">
            <button type="button" class="fb-btn-icon fb-btn-icon-info view" title="Anteprima">${ICONS.view}</button>
            <button type="button" class="fb-btn-icon edit" title="Modifica">${ICONS.edit}</button>
            <button type="button" class="fb-btn-icon fb-btn-icon-danger delete" title="Elimina">${ICONS.trash}</button>
        </div>`;
}

function refreshTable() {
    const $t = window.$("#refuelling-table");
    if ($t.length && typeof $t.bootstrapTable === "function") {
        $t.bootstrapTable("refresh");
    }
    kpis.refresh();
}

function openRefuellingView(row) {
    const total = Number(row.liters || 0) * Number(row.price_per_liter || 0);
    const isLoad = PAGE_DIRECTION === "in";
    const d = buildDialog(`${isLoad ? "Carico" : "Rifornimento"} — ${isLoad ? row.supplier_name || "" : row.vehicle_plate || ""} · ${fmtDate(row.refuel_time)}`);
    d.querySelector(".fb-dialog-body").innerHTML = `
        <dl class="fb-view-grid">
            ${viewItem("Data", fmtDate(row.refuel_time))}
            ${isLoad ? viewItem("Fornitore", row.supplier_name) : viewItem("Automezzo", row.vehicle_plate)}
            ${viewItem("Stazione", row.station_name)}
            ${row.supplier_name ? viewItem("Fornitore", row.supplier_name) : ""}
            ${viewItem("Alimentazione", row.fuel_type_name)}
            ${viewItem("Quantità", fmtLiters(row.liters))}
            ${viewItem("Prezzo al litro", Number(row.price_per_liter) > 0 ? fmtMoney(row.price_per_liter) : null)}
            ${viewItem("Importo", total > 0 ? fmtMoney(total) : null)}
            ${isLoad ? "" : viewItem("Km attuali", fmtKm(row.km_at_refuel))}
            ${isLoad ? "" : viewItem("Km precedenti", fmtKm(row.km_prev ?? row.km_since_last_refuel))}
            ${!isLoad && row.km_diff !== null && row.km_diff !== undefined ? viewItem("Differenza km", fmtKm(row.km_diff)) : ""}
            ${!isLoad ? viewItem("Consumo", row.km_per_liter ? `${Number(row.km_per_liter).toLocaleString("it-IT", { minimumFractionDigits: 2 })} km/l` : null) : ""}
            ${viewItem("Nota", row.note, { span: 2 })}
        </dl>
        <div class="fb-form-actions">
            <button type="button" class="fb-btn fb-btn-secondary" data-action="close-view">Chiudi</button>
        </div>
    `;
    d.querySelector('[data-action="close-view"]').addEventListener("click", () => d.close());
}

function populateSelects(form, row) {
    const vehicleSel = form.querySelector('[data-field="vehicle"]');
    if (vehicleSel) {
        vehicleSel.innerHTML = '<option value="">— Seleziona —</option>' + vehiclesCache.map((v) => `<option value="${v.id_vehicle}" ${row && Number(row.id_vehicle) === Number(v.id_vehicle) ? "selected" : ""}>${escapeHtml(v.plate)}${v.brand_name ? " — " + escapeHtml(v.brand_name) : ""}</option>`).join("");
    }

    form.querySelector('[data-field="station"]').innerHTML = stationsCache.map((s) => `<option value="${s.id_refuelling_station}" ${row && Number(row.id_station) === Number(s.id_refuelling_station) ? "selected" : ""}>${escapeHtml(s.name)}</option>`).join("");

    form.querySelector('[data-field="fuel-type"]').innerHTML = '<option value="">— Seleziona —</option>' + fuelTypesCache.map((f) => `<option value="${f.id_fuel_type}" ${row && Number(row.id_fuel_type) === Number(f.id_fuel_type) ? "selected" : ""}>${escapeHtml(f.name)}</option>`).join("");

    const supplierSel = form.querySelector('[data-field="supplier"]');
    if (supplierSel) {
        supplierSel.innerHTML = '<option value="">— Nessuno —</option>' + suppliersCache.map((s) => `<option value="${s.id_supplier}" ${row && Number(row.id_supplier) === Number(s.id_supplier) ? "selected" : ""}>${escapeHtml(s.company || s.name || "")}</option>`).join("");
    }

    // Select ricercabili (SearchableSelect funziona dentro <dialog>)
    const labels = { vehicle: "Cerca automezzo…", station: "Cerca stazione…", "fuel-type": "Cerca alimentazione…", supplier: "Cerca fornitore…" };
    const selects = {};
    form.querySelectorAll("select[data-field]").forEach((sel) => {
        selects[sel.dataset.field] = new SearchableSelect(sel, {
            placeholder: labels[sel.dataset.field] || "Cerca…",
            emptyText: sel.dataset.field === "supplier" ? "— Nessuno —" : "— Seleziona —",
            onChange: sel.dataset.field === "vehicle" ? (v) => loadVehicleData(form, selects, v) : null,
        });
    });
}

async function loadVehicleData(form, selects, idVehicle) {
    const kmInput = form.querySelector("#rf_km_last");
    const overlay = showDialogOverlay(form.closest("dialog"));

    if (!idVehicle) {
        if (kmInput) kmInput.value = "";
        selects["fuel-type"]?._select("");
        overlay.remove();
        return;
    }

    // in edit: km del rifornimento precedente a quello in modifica (escluso id, prima della data)
    const editingId = form.id_refuelling?.value;
    let kmUrl = `${BASE}api/refuelling/last-km?vehicle_id=${encodeURIComponent(idVehicle)}`;
    if (editingId) {
        kmUrl += `&exclude_id=${encodeURIComponent(editingId)}`;
        if (form.refuel_time?.value) {
            kmUrl += `&before=${encodeURIComponent(form.refuel_time.value)}`;
        }
    }

    try {
        const [kmRes, fuelRes] = await Promise.all([FetchHelper.get(kmUrl), FetchHelper.get(`${BASE}api/refuelling/vehicle-fuel?vehicle_id=${encodeURIComponent(idVehicle)}`)]);
        if (kmInput) {
            kmInput.value = kmRes.km ?? "";
            // ricalcola la casella "differenza" dello scarico
            kmInput.dispatchEvent(new Event("input", { bubbles: true }));
        }
        if (fuelRes.fuel_type !== null && fuelRes.fuel_type !== undefined) {
            selects["fuel-type"]?._select(String(fuelRes.fuel_type));
        }
    } catch (err) {
        console.error("Errore lettura dati veicolo:", err);
    } finally {
        overlay.remove();
    }
}

function showDialogOverlay(dialog) {
    const overlay = document.createElement("div");
    overlay.className = "fb-dialog-overlay";
    overlay.innerHTML = '<div class="fb-spinner"></div>';
    (dialog || document.body).appendChild(overlay);
    return overlay;
}

function fuelTypeName(id) {
    const f = fuelTypesCache.find((x) => Number(x.id_fuel_type) === Number(id));
    return f ? f.name : `#${id}`;
}

/**
 * Verifica la coerenza tra l'alimentazione del veicolo e quella del rifornimento.
 * Imposta body.update_vehicle_fuel quando serve aggiornare la feature del veicolo.
 * Restituisce false se l'utente annulla.
 */
async function checkFuelCoherence(body) {
    let vehicleFuel = null;
    try {
        const res = await FetchHelper.get(`${BASE}api/refuelling/vehicle-fuel?vehicle_id=${encodeURIComponent(body.id_vehicle)}`);
        vehicleFuel = res.fuel_type;
    } catch {
        return true; // se il controllo fallisce non blocchiamo il salvataggio
    }

    // nessuna alimentazione impostata sul veicolo -> la impostiamo
    if (vehicleFuel === null || vehicleFuel === undefined || vehicleFuel === "") {
        body.update_vehicle_fuel = "1";
        return true;
    }

    if (Number(vehicleFuel) === Number(body.id_fuel_type)) {
        return true;
    }

    return await confirmFuelMismatch(fuelTypeName(vehicleFuel), fuelTypeName(body.id_fuel_type), body);
}

function confirmFuelMismatch(vehicleLabel, selectedLabel, body) {
    return new Promise((resolve) => {
        const d = buildDialog("Tipo di carburante diverso");
        d.querySelector(".fb-dialog-body").innerHTML = `
            <div class="fb-alert fb-alert-warning">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                <div>
                    Il veicolo ha un'alimentazione diversa da quella selezionata.
                    Continuando, il tipo di carburante del veicolo verrà aggiornato.
                </div>
            </div>
            <dl class="fb-alert-compare">
                <div class="fb-compare-item old"><dt>Alimentazione veicolo</dt><dd>${escapeHtml(vehicleLabel)}</dd></div>
                <svg class="fb-compare-arrow" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                <div class="fb-compare-item new"><dt>Selezionata</dt><dd>${escapeHtml(selectedLabel)}</dd></div>
            </dl>
            <div class="fb-form-actions">
                <button type="button" class="fb-btn fb-btn-secondary" data-action="cancel">Annulla</button>
                <button type="button" class="fb-btn fb-btn-primary" data-action="confirm">Sì, aggiorna e continua</button>
            </div>
        `;

        d.querySelector('[data-action="cancel"]').addEventListener("click", () => {
            resolve(false);
            d.close();
        });
        d.querySelector('[data-action="confirm"]').addEventListener("click", () => {
            body.update_vehicle_fuel = "1";
            resolve(true);
            d.close();
        });
        d.addEventListener("close", () => resolve(false), { once: true });
    });
}

function openForm(row = null) {
    const tpl = document.getElementById("tpl-refuelling-form");
    const form = tpl.content.cloneNode(true).querySelector("form");

    populateSelects(form, row);

    if (row) {
        form.id_refuelling.value = row.id_refuelling;
        form.refuel_time.value = row.refuel_time ? String(row.refuel_time).replace(" ", "T").slice(0, 16) : "";
        form.liters.value = row.liters ?? "";
        form.price_per_liter.value = Number(row.price_per_liter) > 0 ? row.price_per_liter : "";
        if (form.km_at_refuel) form.km_at_refuel.value = row.km_at_refuel ?? "";
        if (form.km_since_last_refuel) form.km_since_last_refuel.value = row.km_since_last_refuel ?? "";
        form.note.value = row.note ?? "";
        const dirRadio = form.querySelector(`input[name="direction"][value="${row.direction === "in" ? "in" : "out"}"]`);
        if (dirRadio) dirRadio.checked = true;
    } else {
        const now = new Date();
        const pad = (n) => String(n).padStart(2, "0");
        form.refuel_time.value = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}T${pad(now.getHours())}:${pad(now.getMinutes())}`;
        const dirRadio = form.querySelector(`input[name="direction"][value="${PAGE_DIRECTION}"]`);
        if (dirRadio) dirRadio.checked = true;
    }

    const isLoad = PAGE_DIRECTION === "in";

    // casella readonly "differenza" = km attuali − km ultimo rifornimento (solo scarico)
    function updateKmDiff() {
        const diffInput = form.km_diff;
        if (!diffInput) return;
        diffInput.classList.remove("fb-km-diff-ok", "fb-km-diff-zero", "fb-km-diff-bad");
        const last = parseFloat(form.km_since_last_refuel?.value);
        const cur = parseFloat(form.km_at_refuel?.value);
        if (Number.isNaN(last) || Number.isNaN(cur)) {
            diffInput.value = "";
            return;
        }
        const diff = cur - last;
        diffInput.value = diff;
        diffInput.classList.add(diff > 0 ? "fb-km-diff-ok" : diff === 0 ? "fb-km-diff-zero" : "fb-km-diff-bad");
    }
    form.km_at_refuel?.addEventListener("input", updateKmDiff);
    form.km_since_last_refuel?.addEventListener("input", updateKmDiff);
    updateKmDiff();

    const d = buildDialog(row ? `Modifica ${isLoad ? "carico" : "rifornimento"}` : `Nuovo ${isLoad ? "carico" : "rifornimento"}`, "fb-dialog-medium");
    d.querySelector(".fb-dialog-body").appendChild(form);

    form.addEventListener("submit", async (e) => {
        e.preventDefault();
        const id = form.id_refuelling.value;
        const body = Object.fromEntries(new FormData(form).entries());
        // datetime-local produce YYYY-MM-DDTHH:mm, il server vuole Y-m-d H:i
        if (body.refuel_time) {
            body.refuel_time = body.refuel_time.replace("T", " ");
        }

        // i campi select ricercabili non hanno validazione nativa (input hidden)
        const required = isLoad ? { id_station: "Stazione", id_fuel_type: "Alimentazione" } : { id_vehicle: "Automezzo", id_station: "Stazione", id_fuel_type: "Alimentazione" };
        for (const [field, label] of Object.entries(required)) {
            if (!body[field]) {
                await dialog.alert(`Il campo <strong>${label}</strong> è obbligatorio.`, "Attenzione");
                return;
            }
        }

        // differenza km non può essere negativa (solo scarichi)
        if (!isLoad) {
            const last = parseFloat(body.km_since_last_refuel);
            const cur = parseFloat(body.km_at_refuel);
            if (!Number.isNaN(last) && !Number.isNaN(cur) && cur - last < 0) {
                await dialog.alert("Chilometri non validi: i km attuali non possono essere inferiori a quelli dell'ultimo rifornimento.", "Km errati");
                return;
            }
        }

        // coerenza alimentazione veicolo <-> rifornimento (solo scarichi)
        if (!isLoad) {
            const proceed = await checkFuelCoherence(body);
            if (!proceed) return;
        }

        try {
            await FetchHelper.post(id ? `${BASE}api/refuelling/${id}/update` : `${BASE}api/refuelling`, body);
            d.close();
            refreshTable();
            toast.showToastSuccess(id ? `${isLoad ? "Carico" : "Rifornimento"} aggiornato.` : `${isLoad ? "Carico" : "Rifornimento"} registrato.`);
        } catch (err) {
            await dialog.error(err);
        }
    });
}

document.addEventListener("click", async (e) => {
    const btn = e.target.closest("[data-action]");
    if (!btn) return;
    if (btn.dataset.action === "new-refuelling") {
        openForm();
    } else if (btn.dataset.action === "align-km") {
        runAlignKm();
    }
});

/**
 * Allineamento km_since_last_refuel: chiamate AJAX a blocchi con
 * dialog di avanzamento (veicoli elaborati + righe aggiornate).
 */
async function runAlignKm() {
    const ok = await dialog.confirm("Ricalcolare per ogni scarico i <strong>km del rifornimento precedente</strong>?<br>" + "Le righe di ogni automezzo vengono ordinate cronologicamente e il campo " + "<em>Km ultimo rifornimento</em> viene riempito con i km dello scarico precedente.", "Allinea KM");
    if (!ok) return;

    const d = buildDialog("Allineamento chilometri", "fb-dialog-small");
    d.querySelector(".fb-dialog-body").innerHTML = `
        <p class="fb-muted" style="margin:0 0 0.5rem">Elaborazione in corso…</p>
        <div class="fb-progress"><div class="fb-progress-bar" data-progress-bar style="width:0%"></div></div>
        <p class="fb-muted" style="margin:0.5rem 0 0;font-size:0.8125rem">
            <span data-progress-text>0%</span> ·
            veicoli <span data-progress-vehicles>0/0</span> ·
            righe aggiornate <span data-progress-rows>0</span>
        </p>`;
    const bar = d.querySelector("[data-progress-bar]");
    const txt = d.querySelector("[data-progress-text]");
    const veh = d.querySelector("[data-progress-vehicles]");
    const rows = d.querySelector("[data-progress-rows]");

    let cancelled = false;
    d.addEventListener("close", () => (cancelled = true), { once: true });

    let offset = 0;
    let updated = 0;
    try {
        while (true) {
            const res = await FetchHelper.post(`${BASE}api/refuelling/align-km`, { offset, limit: 50 });
            offset = res.processed;
            updated += res.updated || 0;
            const pct = res.total_vehicles > 0 ? Math.round((offset / res.total_vehicles) * 100) : 100;
            bar.style.width = `${pct}%`;
            txt.textContent = `${pct}%`;
            veh.textContent = `${offset}/${res.total_vehicles}`;
            rows.textContent = updated.toLocaleString("it-IT");
            if (res.finished || cancelled) break;
        }
        if (!cancelled) {
            d.close();
            refreshTable();
            toast.showToastSuccess(`Allineamento completato: ${updated.toLocaleString("it-IT")} righe aggiornate.`);
        }
    } catch (err) {
        d.close();
        await dialog.error(err);
    }
}

async function init() {
    initTable();
    kpis.load();
    new PrintHelper({
        table: "#refuelling-table",
        button: '[data-action="print-refuelling"]',
        counter: "#refuelling-selected-count",
        url: "admin/refuelling/print",
        idField: "id_refuelling",
        extraParams: { direction: PAGE_DIRECTION },
        dialog,
    });
    try {
        await loadOptions();
    } catch (err) {
        console.error("Errore caricamento opzioni:", err);
    }
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
} else {
    init();
}
