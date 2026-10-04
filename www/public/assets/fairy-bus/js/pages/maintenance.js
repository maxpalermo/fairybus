import FetchHelper from "../core/FetchHelper.js";
import DialogHelper from "../core/DialogHelper.js";
import PrintHelper from "../components/PrintHelper.js";
import { viewItem } from "../components/ViewGrid.js";

const dialog = new DialogHelper();
let vehiclesCache = [];
let productsCache = [];
let invoicesCache = null;

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

function fmtMoney(value) {
    return `${Number(value || 0).toLocaleString("it-IT", { minimumFractionDigits: 2, maximumFractionDigits: 2 })} €`;
}

function fmtQty(value) {
    return Number(value || 0).toLocaleString("it-IT", { maximumFractionDigits: 3 });
}

function fmtKm(value) {
    return value !== null && value !== undefined && value !== "" ? `${Number(value).toLocaleString("it-IT")} km` : "—";
}

const ICONS = {
    check: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>',
    times: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>',
    eye: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>',
    edit: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.83 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5z"></path></svg>',
    trash: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>',
};

function invoiceBadge(value, row) {
    const n = Number(row.invoices_count || 0);
    if (n > 0) {
        return `<span class="fb-ico fb-ico-ok" title="${n} fattura/e collegata/e">${ICONS.check}</span>`;
    }
    return `<span class="fb-ico fb-ico-no" title="Nessuna fattura">${ICONS.times}</span>`;
}

function formatActions(value, row) {
    return `
        <div class="fb-btn-group">
            <button type="button" class="fb-btn-icon fb-btn-icon-info" title="Visualizza" data-action="view-maintenance" data-id="${row.id_maintenance}">${ICONS.eye}</button>
            <button type="button" class="fb-btn-icon" title="Modifica" data-action="edit-maintenance" data-id="${row.id_maintenance}">${ICONS.edit}</button>
            <button type="button" class="fb-btn-icon fb-btn-icon-danger" title="Elimina" data-action="delete-maintenance" data-id="${row.id_maintenance}">${ICONS.trash}</button>
        </div>
    `;
}

window.maintenanceActionsEvents = {
    "click [data-action=view-maintenance]": function (ev, value, row) {
        ev.stopPropagation();
        openMaintenanceView(row);
    },
    "click [data-action=edit-maintenance]": function (ev, value, row) {
        ev.stopPropagation();
        openMaintenanceForm(row);
    },
    "click [data-action=delete-maintenance]": async function (ev, value, row) {
        ev.stopPropagation();
        if (!(await dialog.confirm(`Eliminare la manutenzione del ${fmtDate(row.date)} su ${escapeHtml(row.vehicle_plate || "veicolo")}? I ricambi manuali rientrano in giacenza.`, "Conferma"))) {
            return;
        }
        try {
            await FetchHelper.post(`${window.FB.baseUrl}api/maintenance/${row.id_maintenance}/delete`, {});
            refreshTable();
        } catch (err) {
            await dialog.error(err);
        }
    },
};

function initMaintenanceTable() {
    if (!window.$ || !window.$.fn.bootstrapTable) {
        setTimeout(initMaintenanceTable, 100);
        return;
    }

    window.$("#maintenance-table").bootstrapTable({
        url: window.FB.baseUrl + "api/maintenance",
        sidePagination: "client",
        pagination: true,
        search: false,
        filterControl: true,
        sortable: true,
        locale: "it-IT",
        columns: [
            { field: "state", checkbox: true, align: "center", valign: "middle" },
            { field: "date", title: "Data", sortable: true, formatter: (v) => fmtDate(v), filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "vehicle_plate", title: "Targa", sortable: true, formatter: (v) => escapeHtml(v || "—"), filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "brand_name", title: "Marca", sortable: true, formatter: (v) => escapeHtml(v || "—"), filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "km", title: "Km", sortable: true, align: "right", formatter: (v) => fmtKm(v), filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "note", title: "Nota", formatter: (v) => escapeHtml(v || "—"), filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "invoices_count", title: "Fatture", align: "center", formatter: invoiceBadge, filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "actions", title: "Azioni", searchable: false, formatter: formatActions, events: window.maintenanceActionsEvents },
        ],
    });
}

function refreshTable() {
    const $table = window.$("#maintenance-table");
    if ($table.length && typeof $table.bootstrapTable === "function") {
        $table.bootstrapTable("refresh");
    }
}

/* ---------- Dialog manutenzione ---------- */

function populateVehicleSelect(select, selectedId) {
    select.innerHTML = '<option value="">— Seleziona —</option>' + vehiclesCache.map((v) => `<option value="${v.id_vehicle}" ${Number(selectedId) === Number(v.id_vehicle) ? "selected" : ""}>${escapeHtml(v.plate)}${v.brand_name ? " — " + escapeHtml(v.brand_name) : ""}</option>`).join("");
}

function populateProductSelect(select) {
    select.innerHTML = '<option value="">— Articolo —</option>' + productsCache.map((p) => `<option value="${p.id_product}">${escapeHtml(p.label)}</option>`).join("");
}

function detailTotals(detail) {
    const qty = Number(detail.quantity || 0);
    const price = Number(detail.price || 0);
    const discount = Number(detail.discount || 0);
    const vat = detail.vat_rate !== null && detail.vat_rate !== undefined ? Number(detail.vat_rate) : 0;
    const importo = qty * price * (1 + discount / 100);
    return { importo, totale: importo * (1 + vat / 100) };
}

function vatLabel(detail) {
    if (detail.vat_code) {
        return escapeHtml(`Aliquota ${detail.vat_code}%`);
    }
    return detail.vat_rate !== null && detail.vat_rate !== undefined ? `${Number(detail.vat_rate)}%` : "—";
}

function renderDetails(form, details) {
    const tbody = form.querySelector('[data-field="details-body"]');
    tbody.innerHTML = "";

    let total = 0;
    let qty = 0;
    for (const d of details) {
        const t = detailTotals(d);
        total += t.totale;
        qty += Number(d.quantity || 0);
        const tr = document.createElement("tr");
        tr.innerHTML = `
            <td class="num">${fmtQty(d.quantity)}</td>
            <td>${escapeHtml(d.sku || "—")}</td>
            <td>${escapeHtml(d.product_name || "—")}</td>
            <td class="num">${fmtMoney(d.price)}</td>
            <td class="num">${Number(d.discount || 0)}%</td>
            <td class="num">${fmtMoney(t.importo)}</td>
            <td>${vatLabel(d)}</td>
            <td class="num">${fmtMoney(t.totale)}</td>
            <td><button type="button" class="fb-btn fb-btn-secondary fb-btn-sm" data-action="del-detail" data-id="${d.id_maintenance_detail}">✕</button></td>
        `;
        tbody.appendChild(tr);
    }
    if (details.length === 0) {
        tbody.innerHTML = '<tr><td colspan="9" class="fb-muted" style="text-align:center">Nessun ricambio.</td></tr>';
    }

    form.querySelector('[data-kpi="total"]').textContent = fmtMoney(total);
    form.querySelector('[data-kpi="qty"]').textContent = fmtQty(qty);
    form.querySelector('[data-kpi="rows"]').textContent = String(details.length);
}

async function loadDetails(form, idMaintenance) {
    try {
        const res = await FetchHelper.get(`${window.FB.baseUrl}api/maintenance/${idMaintenance}/details`);
        renderDetails(form, res.rows || []);
    } catch (err) {
        await dialog.error(err);
    }
}

function renderInvoices(form, invoices) {
    const tbody = form.querySelector('[data-field="invoices-body"]');
    tbody.innerHTML = "";
    for (const inv of invoices) {
        const tr = document.createElement("tr");
        tr.innerHTML = `
            <td>${escapeHtml(inv.invoice_number || "s.n.")}</td>
            <td>${fmtDate(inv.invoice_date)}</td>
            <td>${escapeHtml(inv.supplier_name || inv.customer_name || "—")}</td>
            <td><button type="button" class="fb-btn fb-btn-secondary fb-btn-sm" data-action="unlink-invoice" data-id="${inv.id_invoice}">Scollega</button></td>
        `;
        tbody.appendChild(tr);
    }
    if (invoices.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" class="fb-muted" style="text-align:center">Nessuna fattura collegata.</td></tr>';
    }
}

async function loadInvoicesList(form, idMaintenance) {
    try {
        const res = await FetchHelper.get(`${window.FB.baseUrl}api/maintenance/${idMaintenance}`);
        renderInvoices(form, res.maintenance?.invoices || []);
    } catch (err) {
        await dialog.error(err);
    }
}

function populateInvoiceSelect(select) {
    select.innerHTML = '<option value="">— Fattura —</option>' + (invoicesCache || []).map((i) => `<option value="${i.id_invoice}">${escapeHtml(i.number || "s.n.")} — ${escapeHtml(i.supplier_name || i.customer_name || "")} (${fmtDate(i.date)})</option>`).join("");
}

function setDetailsEnabled(form, enabled) {
    const add = form.querySelector('[data-field="detail-add"]');
    const hint = form.querySelector('[data-field="detail-hint"]');
    if (add) {
        add.style.display = enabled ? "" : "none";
    }
    if (hint) {
        hint.style.display = enabled ? "none" : "";
    }
    const invWrap = form.querySelector('[data-field="invoices-wrap"]');
    const invHint = form.querySelector('[data-field="invoices-hint"]');
    if (invWrap) {
        invWrap.hidden = !enabled;
    }
    if (invHint) {
        invHint.hidden = enabled;
    }
}

function initFormTabs(form) {
    const tabs = form.querySelectorAll(".fb-form-tabs .fb-tab");
    tabs.forEach((tab) => {
        tab.addEventListener("click", () => {
            const panelName = tab.dataset.tab;
            tabs.forEach((t) => {
                t.classList.remove("active");
                t.setAttribute("aria-selected", "false");
            });
            tab.classList.add("active");
            tab.setAttribute("aria-selected", "true");
            form.querySelectorAll(".fb-tab-panel").forEach((p) => {
                p.classList.toggle("active", p.dataset.panel === panelName);
            });
        });
    });
}

async function openMaintenanceForm(row = null) {
    const tpl = document.getElementById("tpl-maintenance-form");
    const form = tpl.content.cloneNode(true).querySelector("form");
    const title = row ? `Manutenzione ${row.vehicle_plate || ""}` : "Nuova manutenzione";

    populateVehicleSelect(form.querySelector('[data-field="vehicle"]'), row?.id_vehicle);
    populateProductSelect(form.querySelector('[data-field="detail-product"]'));
    populateInvoiceSelect(form.querySelector('[data-field="invoice-select"]'));

    if (row) {
        form.querySelector('[name="id_maintenance"]').value = row.id_maintenance;
        form.querySelector('[name="date"]').value = row.date || "";
        form.querySelector('[name="km"]').value = row.km || "";
        form.querySelector('[name="note"]').value = row.note || "";

        // carica il primo task per precompilare i campi lavoro
        try {
            const res = await FetchHelper.get(`${window.FB.baseUrl}api/maintenance/${row.id_maintenance}`);
            const task = res.maintenance?.tasks?.[0];
            if (task) {
                form.querySelector('[name="task_description"]').value = task.description || "";
                form.querySelector('[name="hours"]').value = task.hours ?? "";
                form.querySelector('[name="manpower"]').value = task.manpower ?? "";
                form.querySelector('[name="price_per_hour"]').value = task.price_per_hour ?? "";
            }
        } catch (err) {
            /* ignora: i campi restano vuoti */
        }
    }

    const isNew = !row;
    setDetailsEnabled(form, !isNew);

    const d = document.createElement("dialog");
    d.className = "fb-dialog";
    d.innerHTML = `<div class="fb-dialog-header"><h3 class="fb-dialog-title">${escapeHtml(title)}</h3></div><div class="fb-dialog-body"></div>`;
    d.querySelector(".fb-dialog-body").appendChild(form);
    document.body.appendChild(d);
    d.showModal();

    initFormTabs(form);

    if (row) {
        loadDetails(form, row.id_maintenance);
        loadInvoicesList(form, row.id_maintenance);
    } else {
        renderDetails(form, []);
    }

    form.addEventListener("submit", async (ev) => {
        ev.preventDefault();
        const data = Object.fromEntries(new FormData(form).entries());
        const currentId = form.querySelector('[name="id_maintenance"]').value;
        const url = currentId ? `${window.FB.baseUrl}api/maintenance/${currentId}/update` : `${window.FB.baseUrl}api/maintenance`;
        try {
            const res = await FetchHelper.post(url, data);
            refreshTable();
            if (currentId) {
                d.close();
            } else {
                form.querySelector('[name="id_maintenance"]').value = res.id;
                setDetailsEnabled(form, true);
                await dialog.alert("Manutenzione salvata. Ora puoi aggiungere ricambi e fatture.", "OK");
            }
        } catch (err) {
            await dialog.error(err);
        }
    });

    form.querySelector('[data-action="add-detail"]').addEventListener("click", async () => {
        const idMaintenance = form.querySelector('[name="id_maintenance"]').value;
        if (!idMaintenance) {
            return;
        }
        const data = {
            id_product: form.querySelector('[data-field="detail-product"]').value,
            quantity: form.querySelector('[data-field="detail-qty"]').value,
            price: form.querySelector('[data-field="detail-price"]').value,
            discount: form.querySelector('[data-field="detail-discount"]').value,
            vat_rate: form.querySelector('[data-field="detail-vat"]').value,
        };
        try {
            await FetchHelper.post(`${window.FB.baseUrl}api/maintenance/${idMaintenance}/details`, data);
            form.querySelector('[data-field="detail-qty"]').value = "";
            form.querySelector('[data-field="detail-price"]').value = "";
            form.querySelector('[data-field="detail-discount"]').value = "";
            form.querySelector('[data-field="detail-vat"]').value = "";
            await loadDetails(form, idMaintenance);
        } catch (err) {
            await dialog.error(err);
        }
    });

    form.querySelector('[data-field="details-body"]').addEventListener("click", async (ev) => {
        const btn = ev.target.closest('[data-action="del-detail"]');
        if (!btn) {
            return;
        }
        if (!(await dialog.confirm("Eliminare questa riga? La giacenza verrà ripristinata.", "Conferma"))) {
            return;
        }
        try {
            await FetchHelper.post(`${window.FB.baseUrl}api/maintenance/details/${btn.dataset.id}/delete`, {});
            await loadDetails(form, form.querySelector('[name="id_maintenance"]').value);
        } catch (err) {
            await dialog.error(err);
        }
    });

    form.querySelector('[data-action="link-invoice"]')?.addEventListener("click", async () => {
        const idMaintenance = form.querySelector('[name="id_maintenance"]').value;
        const idInvoice = form.querySelector('[data-field="invoice-select"]').value;
        if (!idMaintenance || !idInvoice) {
            return;
        }
        try {
            await FetchHelper.post(`${window.FB.baseUrl}api/maintenance/${idMaintenance}/link-invoice`, { id_invoice: idInvoice });
            await loadInvoicesList(form, idMaintenance);
            refreshTable();
        } catch (err) {
            await dialog.error(err);
        }
    });

    form.querySelector('[data-field="invoices-body"]').addEventListener("click", async (ev) => {
        const btn = ev.target.closest('[data-action="unlink-invoice"]');
        if (!btn) {
            return;
        }
        const idMaintenance = form.querySelector('[name="id_maintenance"]').value;
        try {
            await FetchHelper.post(`${window.FB.baseUrl}api/maintenance/${idMaintenance}/unlink-invoice`, { id_invoice: btn.dataset.id });
            await loadInvoicesList(form, idMaintenance);
            refreshTable();
        } catch (err) {
            await dialog.error(err);
        }
    });

    form.querySelector('[data-action="close"]').addEventListener("click", () => d.close());
    d.addEventListener("close", () => d.remove(), { once: true });
}

/* ---------- Visualizzazione (sola lettura + stampa) ---------- */

async function openMaintenanceView(row) {
    let m;
    try {
        const res = await FetchHelper.get(`${window.FB.baseUrl}api/maintenance/${row.id_maintenance}`);
        m = res.maintenance;
    } catch (err) {
        await dialog.error(err);
        return;
    }

    let total = 0;
    const rowsHtml = (m.details || [])
        .map((d) => {
            const t = detailTotals(d);
            total += t.totale;
            return `
            <tr>
                <td class="num">${fmtQty(d.quantity)}</td>
                <td>${escapeHtml(d.sku || "—")}</td>
                <td>${escapeHtml(d.product_name || "—")}</td>
                <td class="num">${fmtMoney(d.price)}</td>
                <td class="num">${Number(d.discount || 0)}%</td>
                <td class="num">${fmtMoney(t.importo)}</td>
                <td>${vatLabel(d)}</td>
                <td class="num">${fmtMoney(t.totale)}</td>
            </tr>
        `;
        })
        .join("");

    const task = (m.tasks || [])[0];
    const lavoroHtml = task ? viewItem("Lavoro", `${task.description || "—"} (${task.hours || 0} h · ${fmtQty(task.manpower)} manodopera · ${fmtMoney(task.price_per_hour)}/h)`, { span: 2 }) : "";

    const invoicesHtml = (m.invoices || []).map((i) => `${escapeHtml(i.invoice_number || "s.n.")} (${fmtDate(i.invoice_date)})`).join(", ");

    const dlg = document.createElement("dialog");
    dlg.className = "fb-dialog";
    dlg.innerHTML = `
        <div class="fb-dialog-header"><h3 class="fb-dialog-title">Manutenzione — ${escapeHtml(m.vehicle_plate || "")}</h3></div>
        <div class="fb-dialog-body">
            <dl class="fb-view-grid">
                ${viewItem("Veicolo", `${m.vehicle_plate || "—"}${m.brand_name ? " — " + m.brand_name : ""}`)}
                ${viewItem("Data", fmtDate(m.date))}
                ${viewItem("Km", fmtKm(m.km))}
                ${viewItem("Fatture", invoicesHtml || null)}
                ${lavoroHtml}
                ${m.note ? viewItem("Nota", m.note, { span: 2 }) : ""}
            </dl>
            <div class="fb-details-scroll">
                <table class="fb-table fb-details-table">
                    <thead>
                        <tr>
                            <th class="num">Q.tà</th><th>Codice</th><th>Articolo</th>
                            <th class="num">Prezzo</th><th class="num">Sconto</th><th class="num">Importo</th>
                            <th>I.v.a.</th><th class="num">Totale</th>
                        </tr>
                    </thead>
                    <tbody>${rowsHtml || '<tr><td colspan="8" class="fb-muted" style="text-align:center">Nessun ricambio.</td></tr>'}</tbody>
                </table>
            </div>
            <div class="fb-details-total"><span class="fb-muted">Totale ricambi</span><strong>${fmtMoney(total)}</strong></div>
            <div class="fb-form-actions">
                <button type="button" class="fb-btn fb-btn-primary" data-action="print">Stampa PDF</button>
                <button type="button" class="fb-btn fb-btn-secondary" data-action="close">Chiudi</button>
            </div>
        </div>
    `;
    document.body.appendChild(dlg);
    dlg.showModal();

    dlg.querySelector('[data-action="print"]').addEventListener("click", () => {
        window.open(`${window.FB.baseUrl}admin/maintenance/${m.id_maintenance}/print`, "_blank");
    });
    dlg.querySelector('[data-action="close"]').addEventListener("click", () => dlg.close());
    dlg.addEventListener("close", () => dlg.remove(), { once: true });
}

/* ---------- Init ---------- */

async function loadOptions() {
    try {
        const [vehicles, products, invoices] = await Promise.all([FetchHelper.get(`${window.FB.baseUrl}api/vehicles`), FetchHelper.get(`${window.FB.baseUrl}api/products/options`), FetchHelper.get(`${window.FB.baseUrl}api/invoices`)]);
        vehiclesCache = (vehicles.rows || []).filter((v) => Number(v.active ?? 1) === 1);
        productsCache = products.products || [];
        invoicesCache = invoices.rows || [];
    } catch (err) {
        await dialog.error(err);
    }
}

function initPage() {
    initMaintenanceTable();
    document.querySelector('[data-action="new-maintenance"]')?.addEventListener("click", () => openMaintenanceForm());
    new PrintHelper({
        table: "#maintenance-table",
        button: '[data-action="print-maintenance"]',
        counter: "#maintenance-selected-count",
        url: "admin/maintenance/print",
        idField: "id_maintenance",
        dialog,
    });
    loadOptions();
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initPage);
} else {
    initPage();
}
