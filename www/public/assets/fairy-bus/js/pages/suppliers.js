import FetchHelper from "../core/FetchHelper.js";
import DialogHelper from "../core/DialogHelper.js";
import Toast from "../core/Toast.js";
import PrintHelper from "../components/PrintHelper.js";
import { viewItem } from "../components/ViewGrid.js";

const dialog = new DialogHelper();
const toast = new Toast();
const BASE = window.FB.baseUrl;

window.fbActiveFilterOptions = { 0: "No", 1: "Sì" };

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

function initSuppliersTable() {
    if (!window.$ || !window.$.fn.bootstrapTable) {
        setTimeout(initSuppliersTable, 100);
        return;
    }

    window.supplierToggleEvents = {
        "click .fb-toggle": async (e, value, row) => {
            const el = e.currentTarget;
            const field = el.dataset.field;
            el.disabled = true;
            try {
                const newVal = await window.toggleTrueFalse("fb_supplier", field, row.id_supplier);
                window.$("#suppliers-table").bootstrapTable("updateCellByUniqueId", {
                    id: row.id_supplier,
                    field,
                    value: newVal,
                });
            } catch (err) {
                el.disabled = false;
                await dialog.error(err);
            }
        },
    };

    window.suppliersActionsEvents = {
        "click .view": (e, value, row) => openSupplierView(row),
        "click .edit": (e, value, row) => openForm(row),
        "click .delete": async (e, value, row) => {
            const ok = await dialog.confirm(`Eliminare il fornitore <strong>${escapeHtml(row.company || "")}</strong>?`, "Elimina");
            if (!ok) return;
            try {
                await FetchHelper.post(`${BASE}api/suppliers/${row.id_supplier}/delete`, {});
                refreshTable();
                toast.showToastSuccess("Fornitore eliminato.");
            } catch (err) {
                await dialog.error(err);
            }
        },
    };

    window.$("#suppliers-table").bootstrapTable({
        url: BASE + "api/suppliers",
        sidePagination: "client",
        pagination: true,
        search: false,
        filterControl: true,
        sortable: true,
        locale: "it-IT",
        uniqueId: "id_supplier",
        pageSize: 25,
        columns: [
            { field: "state", checkbox: true, align: "center", valign: "middle" },
            { field: "id_supplier", title: "ID", sortable: true, width: 60, align: "center", filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "company", title: "Ragione sociale", sortable: true, filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "vat_number", title: "P. IVA / CF", sortable: true, filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "email", title: "Email", sortable: true, filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "contact_name", title: "Referente", sortable: true, filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "address1", title: "Indirizzo", sortable: true, formatter: formatAddress, filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "postcode", title: "CAP", sortable: true, width: 80, filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "city", title: "Città", sortable: true, filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "state_name", title: "Provincia", sortable: true, filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "fuel", title: "Carburante", sortable: true, width: 110, align: "center", formatter: formatFuel, searchFormatter: false, filterControl: "select", filterData: "var:fbActiveFilterOptions", events: window.supplierToggleEvents },
            { field: "active", title: "Attivo", sortable: true, width: 80, align: "center", formatter: formatActive, searchFormatter: false, filterControl: "select", filterData: "var:fbActiveFilterOptions", events: window.supplierToggleEvents },
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
                events: window.suppliersActionsEvents,
            },
        ],
    });
}

function refreshTable() {
    const $t = window.$("#suppliers-table");
    if ($t.length && typeof $t.bootstrapTable === "function") {
        $t.bootstrapTable("refresh");
    }
}

function formatAddress(value, row) {
    return [row.address1, row.address2].filter(Boolean).join(" ") || "—";
}

const ICON_CHECK = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
const ICON_TIMES = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';

function toggleIcon(value, field) {
    const on = Number(value) === 1;
    return `<button type="button" class="fb-toggle ${on ? "fb-toggle-on" : "fb-toggle-off"}" data-field="${field}" title="${on ? "Attivo" : "Disattivo"} — clicca per cambiare">${on ? ICON_CHECK : ICON_TIMES}</button>`;
}

function formatActive(value) {
    return toggleIcon(value, "active");
}

function formatFuel(value) {
    return toggleIcon(value, "fuel");
}

function openSupplierView(row) {
    const d = buildDialog(`Fornitore — ${row.company || ""}`);
    d.querySelector(".fb-dialog-body").innerHTML = `
        <dl class="fb-view-grid">
            ${viewItem("Ragione sociale", row.company)}
            ${viewItem("P. IVA / CF", row.vat_number)}
            ${viewItem("Referente", row.contact_name)}
            ${viewItem("Email", row.email)}
            ${viewItem("PEC", row.pec)}
            ${viewItem("Indirizzo", [row.address1, row.address2].filter(Boolean).join(" "))}
            ${viewItem("CAP", row.postcode)}
            ${viewItem("Città", row.city)}
            ${viewItem("Provincia", row.state_name)}
            ${viewItem("Telefono", row.phone_number)}
            ${viewItem("Cellulare", row.mobile_number)}
            ${viewItem("Fornitore carburante", Number(row.fuel) === 1 ? "Sì" : "No")}
            ${viewItem("Attivo", Number(row.active) === 1 ? "Sì" : "No")}
        </dl>
        <div class="fb-form-actions">
            <button type="button" class="fb-btn fb-btn-secondary" data-action="close-view">Chiudi</button>
        </div>
    `;
    d.querySelector('[data-action="close-view"]').addEventListener("click", () => d.close());
}

async function openForm(row = null) {
    const tpl = document.getElementById("tpl-supplier-form");
    const form = tpl.content.cloneNode(true).querySelector("form");

    if (row) {
        form.id_supplier.value = row.id_supplier;
        for (const k of ["company", "vat_number", "email", "pec", "contact_name", "address1", "address2", "postcode", "city", "phone_number", "mobile_number"]) {
            if (form[k]) form[k].value = row[k] ?? "";
        }
        form.fuel.checked = Number(row.fuel) === 1;
        form.active.checked = Number(row.active ?? 1) === 1;
    }

    const d = buildDialog(row ? "Modifica fornitore" : "Nuovo fornitore", "fb-dialog-medium");
    d.querySelector(".fb-dialog-body").appendChild(form);

    form.addEventListener("submit", async (e) => {
        e.preventDefault();
        const id = form.id_supplier.value;
        const body = Object.fromEntries(new FormData(form).entries());
        try {
            await FetchHelper.post(id ? `${BASE}api/suppliers/${id}/update` : `${BASE}api/suppliers`, body);
            d.close();
            refreshTable();
            toast.showToastSuccess(id ? "Fornitore aggiornato." : "Fornitore creato.");
        } catch (err) {
            await dialog.error(err);
        }
    });
}

document.addEventListener("click", async (e) => {
    const btn = e.target.closest("[data-action]");
    if (!btn) return;
    if (btn.dataset.action === "new-supplier") {
        openForm();
    }
});

function init() {
    initSuppliersTable();
    new PrintHelper({
        table: "#suppliers-table",
        button: '[data-action="print-suppliers"]',
        counter: "#suppliers-selected-count",
        url: "admin/suppliers/print",
        idField: "id_supplier",
        dialog,
    });
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
} else {
    init();
}
