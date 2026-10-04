/**
 * Copyright since 2026 Massimiliano Palermo
 * @license AFL-3.0
 *
 * Statistiche rifornimenti: KPI per tipo carburante, grafici SVG
 * (andamento litri, costi, consumo medio e per scarico) e lista movimenti.
 * Filtri globali: fornitore, automezzo, tipo carburante, direzione, date.
 */
import FetchHelper from "../core/FetchHelper.js";
import DialogHelper from "../core/DialogHelper.js";
import PrintHelper from "../components/PrintHelper.js";

const dialog = new DialogHelper();

window.fbStatsDirectionOptions = { in: "Carico", out: "Scarico" };

let tableInited = false;

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

function fmtLiters(value) {
    return `${Number(value || 0).toLocaleString("it-IT", { minimumFractionDigits: 2, maximumFractionDigits: 2 })} l`;
}

function fmtMoney(value) {
    return `${Number(value || 0).toLocaleString("it-IT", { minimumFractionDigits: 2, maximumFractionDigits: 2 })} €`;
}

function directionBadge(value) {
    if (value === "in") {
        return '<span class="fb-badge fb-badge-success">Carico</span>';
    }
    return '<span class="fb-badge fb-badge-danger">Scarico</span>';
}

function kmPerLiter(row) {
    if (row.direction !== "out") {
        return null;
    }
    const km = Number(row.km_at_refuel || 0);
    const prev = Number(row.km_since_last_refuel || 0);
    const liters = Number(row.liters || 0);
    if (km <= prev || liters <= 0) {
        return null;
    }
    return (km - prev) / liters;
}

/* ---------- Grafici SVG ---------- */

const COLORS = { in: "#16a34a", out: "#dc2626", cost: "#2563eb", consumption: "#f59e0b", perUnload: "#7c3aed" };

/**
 * Disegna un line chart SVG in `el`.
 * series: [{name, color, values:[num]}] allineate a `labels`.
 */
function renderLineChart(el, labels, series) {
    const usable = series.filter((s) => s.values.some((v) => v !== null));
    const n = labels.length;
    if (n === 0 || usable.length === 0) {
        el.innerHTML = '<div class="fb-chart-empty">Nessun dato disponibile.</div>';
        return;
    }

    const W = 760;
    const H = 220;
    const P = 10;
    const max = Math.max(1e-9, ...usable.flatMap((s) => s.values.filter((v) => v !== null)));
    const min = Math.min(0, ...usable.flatMap((s) => s.values.filter((v) => v !== null)));
    const span = max - min || 1;

    const x = (i) => (n <= 1 ? W / 2 : P + (i * (W - 2 * P)) / (n - 1));
    const y = (v) => H - P - ((v - min) / span) * (H - 2 * P);

    const paths = usable
        .map((s) => {
            const pts = s.values.map((v, i) => (v === null ? null : `${x(i).toFixed(1)},${y(v).toFixed(1)}`));
            const segments = [];
            let cur = [];
            pts.forEach((p) => {
                if (p === null) {
                    if (cur.length) segments.push(cur);
                    cur = [];
                } else {
                    cur.push(p);
                }
            });
            if (cur.length) segments.push(cur);
            return segments.map((seg) => `<path d="M${seg.join(" L")}" fill="none" stroke="${s.color}" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>`).join("");
        })
        .join("");

    // etichette X decimate se troppe
    const step = Math.max(1, Math.ceil(n / 12));
    const labelSpans = labels.map((l, i) => (i % step === 0 || i === n - 1 ? `<span>${escapeHtml(l)}</span>` : "<span></span>")).join("");

    const legend = usable.length > 1 || usable[0].name ? `<div class="fb-chart-legend">${usable.map((s) => `<span><i class="dot" style="background:${s.color}"></i>${escapeHtml(s.name)}</span>`).join("")}</div>` : "";

    el.innerHTML = `<svg class="fb-chart-svg" viewBox="0 0 ${W} ${H}" preserveAspectRatio="none">${paths}</svg><div class="fb-chart-labels">${labelSpans}</div>${legend}`;
}

/* ---------- Filtri ---------- */

function currentFilters() {
    return {
        supplier_id: document.getElementById("flt-supplier").value,
        vehicle_id: document.getElementById("flt-vehicle").value,
        fuel_type_id: document.getElementById("flt-fuel").value,
        direction: document.getElementById("flt-direction").value,
        date_from: document.getElementById("flt-from").value,
        date_to: document.getElementById("flt-to").value,
    };
}

function fillSelect(id, rows, valueKey, labelKey) {
    const el = document.getElementById(id);
    el.innerHTML = '<option value="">Tutti</option>' + rows.map((r) => `<option value="${r[valueKey]}">${escapeHtml(r[labelKey])}</option>`).join("");
}

async function loadFilterOptions() {
    const [suppliers, vehicles, refOptions] = await Promise.all([FetchHelper.get(`${window.FB.baseUrl}api/suppliers`), FetchHelper.get(`${window.FB.baseUrl}api/vehicles`), FetchHelper.get(`${window.FB.baseUrl}api/refuelling/options`)]);

    const supplierRows = (suppliers.rows || []).filter((s) => Number(s.fuel) === 1);
    const supplierList = supplierRows.length > 0 ? supplierRows : suppliers.rows || [];
    fillSelect("flt-supplier", supplierList, "id_supplier", "company");
    fillSelect(
        "flt-vehicle",
        (vehicles.rows || []).map((v) => ({ id_vehicle: v.id_vehicle, label: `${v.plate}${v.description ? ` — ${v.description}` : ""}` })),
        "id_vehicle",
        "label",
    );
    fillSelect("flt-fuel", refOptions.fuel_types || [], "id_fuel_type", "name");

    // opzioni per i filtri colonna della tabella
    window.fbStatsSupplierOptions = Object.fromEntries((suppliers.rows || []).map((s) => [s.company, s.company]));
    window.fbStatsVehicleOptions = Object.fromEntries((vehicles.rows || []).map((v) => [v.plate, v.plate]));
    window.fbStatsFuelOptions = Object.fromEntries((refOptions.fuel_types || []).map((f) => [f.name, f.name]));
}

/* ---------- Rendering ---------- */

function renderKpis(totals) {
    const wrap = document.getElementById("stats-kpis");
    if (!totals.length) {
        wrap.innerHTML = '<article class="fb-kpi-card"><p class="fb-kpi-title">Nessun dato</p><p class="fb-kpi-value">—</p></article>';
        return;
    }
    wrap.innerHTML = totals
        .map(
            (t) => `
        <article class="fb-kpi-card">
            <div class="fb-kpi-top">
                <div>
                    <p class="fb-kpi-title">${escapeHtml(t.name)}</p>
                    <p class="fb-kpi-value">${fmtLiters(t.stock)}</p>
                    <p class="fb-kpi-sub">Giacenza</p>
                </div>
            </div>
            <p class="fb-kpi-sub">Carichi: ${fmtLiters(t.load)} &middot; Scarichi: ${fmtLiters(t.unload)}</p>
        </article>`,
        )
        .join("");
}

function renderCharts(charts) {
    const months = charts.liters_monthly.map((r) => r.ym);
    renderLineChart(document.getElementById("chart-liters"), months, [
        { name: "Carichi", color: COLORS.in, values: charts.liters_monthly.map((r) => r.in) },
        { name: "Scarichi", color: COLORS.out, values: charts.liters_monthly.map((r) => r.out) },
    ]);

    renderLineChart(
        document.getElementById("chart-costs"),
        charts.cost_monthly.map((r) => r.ym),
        [{ name: "Costo (€)", color: COLORS.cost, values: charts.cost_monthly.map((r) => r.cost) }],
    );

    renderLineChart(
        document.getElementById("chart-consumption"),
        charts.consumption_monthly.map((r) => r.ym),
        [{ name: "Km/l medi", color: COLORS.consumption, values: charts.consumption_monthly.map((r) => r.km_per_liter) }],
    );

    renderLineChart(
        document.getElementById("chart-per-unload"),
        charts.consumption_per_unload.map((r) => r.date),
        [{ name: "Km/l per scarico", color: COLORS.perUnload, values: charts.consumption_per_unload.map((r) => r.km_per_liter) }],
    );
}

function initStatsTable() {
    if (tableInited) {
        return;
    }
    tableInited = true;

    window.$("#refuelling-stats-table").bootstrapTable({
        data: [],
        sidePagination: "client",
        pagination: true,
        search: false,
        filterControl: true,
        sortable: true,
        locale: "it-IT",
        columns: [
            { field: "state", checkbox: true, align: "center", valign: "middle" },
            { field: "refuel_time", title: "Data", sortable: true, formatter: fmtDate, filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "vehicle_plate", title: "Automezzo", sortable: true, formatter: (v) => escapeHtml(v || "—"), filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "station_name", title: "Stazione", sortable: true, formatter: (v) => escapeHtml(v || "—"), filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "supplier_name", title: "Fornitore", sortable: true, formatter: (v) => escapeHtml(v || "—"), filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "fuel_type_name", title: "Carburante", sortable: true, formatter: (v) => escapeHtml(v || "—"), filterControl: "select", filterData: "var:fbStatsFuelOptions", searchFormatter: false },
            { field: "direction", title: "Operazione", sortable: true, align: "center", formatter: directionBadge, filterControl: "select", filterData: "var:fbStatsDirectionOptions", searchFormatter: false },
            { field: "liters", title: "Litri", sortable: true, align: "right", formatter: fmtLiters, filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "price_per_liter", title: "€/l", sortable: true, align: "right", formatter: (v) => `${Number(v || 0).toLocaleString("it-IT", { minimumFractionDigits: 4 })}`, filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "cost", title: "Costo", align: "right", formatter: (v, row) => fmtMoney(Number(row.liters || 0) * Number(row.price_per_liter || 0)) },
            { field: "km_at_refuel", title: "Km", sortable: true, align: "right", formatter: (v) => Number(v || 0).toLocaleString("it-IT"), filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            {
                field: "km_per_liter",
                title: "Km/l",
                sortable: true,
                align: "right",
                formatter: (v, row) => {
                    const k = kmPerLiter(row);
                    return k === null ? "—" : k.toLocaleString("it-IT", { maximumFractionDigits: 2 });
                },
            },
        ],
    });
}

async function loadStats() {
    try {
        const res = await FetchHelper.get(`${window.FB.baseUrl}api/refuelling/stats`, currentFilters());
        renderKpis(res.fuel_totals || []);
        renderCharts(res.charts || {});
        window.$("#refuelling-stats-table").bootstrapTable("load", res.rows || []);
    } catch (err) {
        await dialog.error(err);
    }
}

/* ---------- Init ---------- */

async function initPage() {
    if (!window.$ || !window.$.fn.bootstrapTable) {
        setTimeout(initPage, 100);
        return;
    }

    try {
        await loadFilterOptions();
    } catch (err) {
        await dialog.error(err);
        return;
    }

    initStatsTable();
    await loadStats();

    document.getElementById("flt-apply").addEventListener("click", loadStats);
    document.getElementById("flt-reset").addEventListener("click", () => {
        ["flt-supplier", "flt-vehicle", "flt-fuel", "flt-direction", "flt-from", "flt-to"].forEach((id) => {
            document.getElementById(id).value = "";
        });
        loadStats();
    });
    document.querySelector(".fb-stats-filters").addEventListener("keydown", (e) => {
        if (e.key === "Enter") {
            loadStats();
        }
    });

    new PrintHelper({
        table: "#refuelling-stats-table",
        button: '[data-action="print-stats"]',
        counter: "#stats-selected-count",
        url: "admin/refuelling/print",
        idField: "id_refuelling",
        dialog,
    });
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initPage);
} else {
    initPage();
}
