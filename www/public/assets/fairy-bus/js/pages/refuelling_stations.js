import FetchHelper from "../core/FetchHelper.js";
import DialogHelper from "../core/DialogHelper.js";
import Toast from "../core/Toast.js";
import PrintHelper from "../components/PrintHelper.js";
import { viewItem } from "../components/ViewGrid.js";

const dialog = new DialogHelper();
const toast = new Toast();
const BASE = window.FB.baseUrl;

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

function initTable() {
    if (!window.$ || !window.$.fn.bootstrapTable) {
        setTimeout(initTable, 100);
        return;
    }

    window.stationsActionsEvents = {
        "click .view": (e, value, row) => openStationView(row),
        "click .edit": (e, value, row) => openForm(row),
        "click .delete": async (e, value, row) => {
            const ok = await dialog.confirm(`Eliminare il punto di rifornimento <strong>${escapeHtml(row.name)}</strong>?`, "Elimina");
            if (!ok) return;
            try {
                await FetchHelper.post(`${BASE}api/refuelling-stations/${row.id_refuelling_station}/delete`, {});
                refreshTable();
            } catch (err) {
                await dialog.error(err);
            }
        },
    };

    window.$("#stations-table").bootstrapTable({
        url: BASE + "api/refuelling-stations",
        sidePagination: "client",
        pagination: true,
        search: false,
        filterControl: true,
        sortable: true,
        locale: "it-IT",
        columns: [
            { field: "state", checkbox: true, align: "center", valign: "middle" },
            { field: "name", title: "Nome", sortable: true, formatter: (v) => escapeHtml(v || "—"), filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "suburb", title: "Quartiere", sortable: true, formatter: (v) => escapeHtml(v || "—"), filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "town", title: "Comune", sortable: true, formatter: (v) => escapeHtml(v || "—"), filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "province", title: "Prov.", sortable: true, align: "center", formatter: (v) => escapeHtml(v || "—"), filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "post_code", title: "CAP", align: "center", formatter: (v) => escapeHtml(v || "—"), filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "street", title: "Indirizzo", formatter: (v) => escapeHtml(v || "—"), filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            {
                field: "actions",
                title: "Azioni",
                searchable: false,
                formatter: () => `
                    <div class="fb-btn-group">
                        <button type="button" class="fb-btn-icon fb-btn-icon-info view" title="Anteprima">${ICONS.view}</button>
                        <button type="button" class="fb-btn-icon edit" title="Modifica">${ICONS.edit}</button>
                        <button type="button" class="fb-btn-icon fb-btn-icon-danger delete" title="Elimina">${ICONS.trash}</button>
                    </div>`,
                events: window.stationsActionsEvents,
            },
        ],
    });
}

function refreshTable() {
    const $t = window.$("#stations-table");
    if ($t.length && typeof $t.bootstrapTable === "function") {
        $t.bootstrapTable("refresh");
    }
}

function openStationView(row) {
    const d = buildDialog(`Punto di rifornimento — ${row.name || ""}`);
    d.querySelector(".fb-dialog-body").innerHTML = `
        <dl class="fb-view-grid">
            ${viewItem("Nome", row.name)}
            ${viewItem("Indirizzo", row.street)}
            ${viewItem("Quartiere", row.suburb)}
            ${viewItem("Comune", row.town)}
            ${viewItem("Provincia", row.province)}
            ${viewItem("CAP", row.post_code)}
            ${viewItem("Latitudine", row.lat)}
            ${viewItem("Longitudine", row.lon)}
            ${viewItem("EPSG", row.epsg_code)}
            ${viewItem("Nota", row.note, { span: 2 })}
        </dl>
        <div class="fb-form-actions">
            <button type="button" class="fb-btn fb-btn-secondary" data-action="close-view">Chiudi</button>
        </div>
    `;
    d.querySelector('[data-action="close-view"]').addEventListener("click", () => d.close());
}

async function openForm(row = null) {
    const tpl = document.getElementById("tpl-station-form");
    const form = tpl.content.cloneNode(true).querySelector("form");

    if (row) {
        form.id_refuelling_station.value = row.id_refuelling_station;
        for (const k of ["name", "street", "suburb", "town", "province", "post_code", "lat", "lon", "epsg_code", "note"]) {
            if (form[k]) form[k].value = row[k] ?? "";
        }
    }

    const d = buildDialog(row ? "Modifica punto di rifornimento" : "Nuovo punto di rifornimento", "fb-dialog-medium");
    d.querySelector(".fb-dialog-body").appendChild(form);

    form.addEventListener("submit", async (e) => {
        e.preventDefault();
        const id = form.id_refuelling_station.value;
        const body = Object.fromEntries(new FormData(form).entries());
        try {
            await FetchHelper.post(id ? `${BASE}api/refuelling-stations/${id}/update` : `${BASE}api/refuelling-stations`, body);
            d.close();
            refreshTable();
            toast.showToastSuccess(id ? "Punto di rifornimento aggiornato." : "Punto di rifornimento creato.");
        } catch (err) {
            await dialog.error(err);
        }
    });
}

document.addEventListener("click", async (e) => {
    const btn = e.target.closest("[data-action]");
    if (!btn) return;
    if (btn.dataset.action === "new-station") {
        openForm();
    }
});

function init() {
    initTable();
    new PrintHelper({
        table: "#stations-table",
        button: '[data-action="print-stations"]',
        counter: "#stations-selected-count",
        url: "admin/refuelling-stations/print",
        idField: "id_refuelling_station",
        dialog,
    });
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
} else {
    init();
}
