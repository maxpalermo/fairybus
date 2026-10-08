import FetchHelper from "../core/FetchHelper.js";
import DialogHelper from "../core/DialogHelper.js";
import PrintHelper from "../components/PrintHelper.js";
import { viewItem } from "../components/ViewGrid.js";
import SearchableSelect from "../components/SearchableSelect.js?v=4";

const dialog = new DialogHelper();
let suppliersCache = [];
let customersCache = [];
let vehiclesCache = [];

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
    return `${Number(value || 0).toLocaleString("it-IT", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}\u00A0€`;
}

function fmtQty(value) {
    return Number(value || 0).toLocaleString("it-IT", { maximumFractionDigits: 3 });
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
    const vat = detail.vat_code ?? detail.vat_rate;
    if (vat === null || vat === undefined || vat === "") {
        return "—";
    }
    const num = Number(vat);
    return isNaN(num) ? escapeHtml(String(vat)) : `${num.toLocaleString("it-IT", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}%`;
}

const ICONS = {
    eye: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>',
    edit: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.83 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5z"></path></svg>',
    link: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>',
    unlink: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18.84 12.25l1.72-1.71a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M5.17 11.75l-1.72 1.71a5 5 0 0 0 7.07 7.07l1.71-1.71"></path><line x1="2" y1="2" x2="22" y2="22"></line></svg>',
    trash: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>',
    wrench: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"></path></svg>',
};

function formatActions(value, row) {
    return `
        <div class="fb-btn-group">
            <button type="button" class="fb-btn-icon fb-btn-icon-info" title="Visualizza" data-action="view-invoice" data-id="${row.id_invoice}">${ICONS.eye}</button>
            <button type="button" class="fb-btn-icon" title="Modifica" data-action="edit-invoice" data-id="${row.id_invoice}">${ICONS.edit}</button>
            <button type="button" class="fb-btn-icon fb-btn-icon-success" title="Associa DDT" data-action="assign-ddt" data-id="${row.id_invoice}">${ICONS.link}</button>
            ${Number(row.id_customer) > 0 ? `<button type="button" class="fb-btn-icon fb-btn-icon-purple" title="Associa manutenzioni" data-action="assign-mnt" data-id="${row.id_invoice}">${ICONS.wrench}</button>` : ""}
            <button type="button" class="fb-btn-icon fb-btn-icon-danger" title="Elimina" data-action="delete-invoice" data-id="${row.id_invoice}">${ICONS.trash}</button>
        </div>
    `;
}

window.invoiceActionsEvents = {
    "click [data-action=view-invoice]": function (ev, value, row) {
        ev.stopPropagation();
        openInvoiceView(row);
    },
    "click [data-action=edit-invoice]": function (ev, value, row) {
        ev.stopPropagation();
        openInvoiceForm(row);
    },
    "click [data-action=assign-ddt]": function (ev, value, row) {
        ev.stopPropagation();
        openAssignDdtDialog(row);
    },
    "click [data-action=assign-mnt]": function (ev, value, row) {
        ev.stopPropagation();
        openAssignMaintenanceDialog(row);
    },
    "click [data-action=delete-invoice]": async function (ev, value, row) {
        ev.stopPropagation();
        if (!(await dialog.confirm(`Eliminare la fattura "${escapeHtml(row.number || row.id_invoice)}"? I DDT collegati verranno svincolati.`, "Conferma"))) {
            return;
        }
        try {
            await FetchHelper.post(`${window.FB.baseUrl}api/invoices/${row.id_invoice}/delete`, {});
            refreshTable();
        } catch (err) {
            await dialog.error(err);
        }
    },
};

window.invoiceKindOptions = {
    "": "Tutti",
    in: "Carico (fornitore)",
    out: "Scarico (cliente)",
};

const ICON_IN = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>';
const ICON_OUT = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>';
const ICON_SUPPLIER = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>';
const ICON_CUSTOMER = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>';
const ICON_VEHICLE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 6v6"></path><path d="M15 6v6"></path><path d="M2 12h19.6"></path><path d="M18 18h3s.5-1.7.8-2.8c.1-.4.2-.8.2-1.2 0-.4-.1-.8-.2-1.2l-1.4-5C20.1 6.8 19.1 6 18 6H4a2 2 0 0 0-2 2v10h2"></path><circle cx="7" cy="18" r="2"></circle><path d="M9 18h5"></path><circle cx="16" cy="18" r="2"></circle></svg>';

function initInvoicesTable() {
    if (!window.$ || !window.$.fn.bootstrapTable) {
        setTimeout(initInvoicesTable, 100);
        return;
    }

    window.$("#invoices-table").bootstrapTable({
        url: window.FB.baseUrl + "api/invoices",
        sidePagination: "client",
        pagination: true,
        search: false,
        filterControl: true,
        sortable: true,
        locale: "it-IT",
        responseHandler: (res) => {
            // tipo precomputato: i filtri lavorano sul valore grezzo del campo
            (res.rows || []).forEach((r) => {
                r.invoice_kind = Number(r.id_customer) > 0 ? "out" : "in";
            });
            return res;
        },
        columns: [
            { field: "state", checkbox: true, align: "center", valign: "middle" },
            {
                field: "invoice_kind",
                title: "Tipo",
                sortable: true,
                align: "center",
                filterControl: "select",
                filterData: "var:invoiceKindOptions",
                formatter: (v) => (v === "out" ? `<span class="fb-tipo-badge fb-tipo-out">${ICON_OUT}Scarico</span>` : `<span class="fb-tipo-badge fb-tipo-in">${ICON_IN}Carico</span>`),
            },
            {
                field: "supplier_name",
                title: "Soggetto",
                sortable: true,
                formatter: (v, row) => {
                    const isCustomer = Number(row.id_customer) > 0;
                    const name = escapeHtml(row.supplier_name || row.customer_name || "—");
                    return `<span class="fb-subj ${isCustomer ? "fb-subj-customer" : "fb-subj-supplier"}" title="${isCustomer ? "Cliente" : "Fornitore"}">${isCustomer ? ICON_CUSTOMER : ICON_SUPPLIER}${name}</span>`;
                },
                filterControl: "input",
                filterCustomSearch: window.fbFilterSearch,
            },
            { field: "date", title: "Data", sortable: true, formatter: (v) => fmtDate(v), filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            {
                field: "vehicle_plate",
                title: "Targa",
                sortable: true,
                formatter: (v) => (v ? `<span class="fb-subj fb-subj-vehicle">${ICON_VEHICLE}${escapeHtml(v)}</span>` : "—"),
                filterControl: "input",
                filterCustomSearch: window.fbFilterSearch,
            },
            { field: "number", title: "Numero", sortable: true, formatter: (v) => (v ? escapeHtml(v) : "—"), filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "documents_count", title: "N. doc.", sortable: true, align: "center", formatter: (v, row) => (Number(row.id_customer) > 0 ? Number(row.maintenances_count || 0) : Number(v || 0)), filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "actions", title: "Azioni", searchable: false, formatter: formatActions, events: window.invoiceActionsEvents },
        ],
    });
}

function refreshTable() {
    const $table = window.$("#invoices-table");
    if ($table.length && typeof $table.bootstrapTable === "function") {
        $table.bootstrapTable("refresh");
    }
}

/* ---------- Dialog fattura ---------- */

function populateSupplierSelect(select, selectedId) {
    select.innerHTML = '<option value="">— Seleziona —</option>' + suppliersCache.map((s) => `<option value="${s.id_supplier}" ${Number(selectedId) === Number(s.id_supplier) ? "selected" : ""}>${escapeHtml(s.company)}</option>`).join("");
}

function populateCustomerSelect(select, selectedId) {
    select.innerHTML = '<option value="">— Seleziona —</option>' + customersCache.map((c) => `<option value="${c.id_customer}" ${Number(selectedId) === Number(c.id_customer) ? "selected" : ""}>${escapeHtml(c.company)}</option>`).join("");
}

function populateVehicleSelect(select, selectedId) {
    select.innerHTML = '<option value="">— Seleziona targa —</option>' + vehiclesCache.map((v) => `<option value="${v.id_vehicle}" ${Number(selectedId) === Number(v.id_vehicle) ? "selected" : ""}>${escapeHtml(v.plate)}${v.brand_name ? " — " + escapeHtml(v.brand_name) : ""}</option>`).join("");
}

function renderDdtGroups(form, documents, linkedIds) {
    const container = form.querySelector('[data-field="ddt-list"]');
    const hint = form.querySelector('[data-field="ddt-hint"]');
    container.innerHTML = "";

    if (documents.length === 0) {
        hint.style.display = "";
        hint.textContent = "Nessun DDT disponibile per questo fornitore.";
        updateInvoiceKpis(form);
        return;
    }
    hint.style.display = "none";

    for (const doc of documents) {
        const checked = linkedIds.includes(Number(doc.id_document));
        const group = document.createElement("div");
        group.className = "fb-ddt-group";

        const rowsHtml = (doc.details || [])
            .map((d) => {
                const t = detailTotals(d);
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

        group.innerHTML = `
            <label class="fb-ddt-group-header">
                <input type="checkbox" data-doc-id="${doc.id_document}" ${checked ? "checked" : ""}>
                DDT ${escapeHtml(doc.number || doc.id_document)} del ${fmtDate(doc.date)}
            </label>
            <table>
                <thead>
                    <tr>
                        <th class="num">Q.tà</th>
                        <th>Codice</th>
                        <th>Articolo</th>
                        <th class="num">Prezzo</th>
                        <th class="num">Sconto/ricarico</th>
                        <th class="num">Importo</th>
                        <th>I.v.a.</th>
                        <th class="num">Totale</th>
                    </tr>
                </thead>
                <tbody>${rowsHtml || '<tr><td colspan="8" class="fb-muted" style="text-align:center">Nessun movimento.</td></tr>'}</tbody>
            </table>
        `;
        container.appendChild(group);
    }

    // Salva i documenti disponibili sul form per il calcolo KPI
    form._availableDocs = documents;
    updateInvoiceKpis(form);
}

/* ---------- Schede manutenzione (fatture cliente, conto terzi) ---------- */

function renderMntGroups(form, sheets) {
    const container = form.querySelector('[data-field="mnt-list"]');
    const hint = form.querySelector('[data-field="mnt-hint"]');
    container.innerHTML = "";

    if (sheets.length === 0) {
        hint.style.display = "";
        hint.textContent = "Nessuna scheda manutenzione da fatturare per questo automezzo.";
        form._availableMnt = [];
        updateInvoiceKpis(form);
        return;
    }
    hint.style.display = "none";

    for (const m of sheets) {
        const group = document.createElement("div");
        group.className = "fb-ddt-group";

        const rowsHtml = (m.lines || [])
            .map((d) => {
                const t = detailTotals(d);
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

        const km = Number(m.km) > 0 ? ` · ${Number(m.km).toLocaleString("it-IT")}\u00A0km` : "";
        const inDoc = Number(m.id_document) > 0 ? ` <span class="fb-mnt-indoc">(in scarico #${m.id_document})</span>` : "";
        group.innerHTML = `
            <label class="fb-ddt-group-header">
                <input type="checkbox" data-mnt-id="${m.id_maintenance}" ${m.linked ? "checked" : ""}>
                Scheda del ${fmtDate(m.date)}${km}${inDoc}
                <span class="fb-mnt-amount">${fmtMoney(m.amount)}</span>
            </label>
            <table>
                <thead>
                    <tr>
                        <th class="num">Q.tà</th>
                        <th>Codice</th>
                        <th>Articolo</th>
                        <th class="num">Prezzo</th>
                        <th class="num">Sconto/ricarico</th>
                        <th class="num">Importo</th>
                        <th>I.v.a.</th>
                        <th class="num">Totale</th>
                    </tr>
                </thead>
                <tbody>${rowsHtml || '<tr><td colspan="8" class="fb-muted" style="text-align:center">Nessun movimento.</td></tr>'}</tbody>
            </table>
        `;
        container.appendChild(group);
    }

    form._availableMnt = sheets;
    updateInvoiceKpis(form);
}

async function loadAvailableMnt(form, idVehicle, idInvoice) {
    const container = form.querySelector('[data-field="mnt-list"]');
    const hint = form.querySelector('[data-field="mnt-hint"]');
    if (!idVehicle && !idInvoice) {
        container.innerHTML = "";
        hint.style.display = "";
        hint.textContent = "Seleziona cliente e automezzo per vedere le schede manutenzione da fatturare.";
        form._availableMnt = [];
        updateInvoiceKpis(form);
        return;
    }
    try {
        const res = await FetchHelper.get(`${window.FB.baseUrl}api/maintenance/available`, {
            vehicle_id: idVehicle || 0,
            invoice_id: idInvoice || 0,
            with_lines: 1,
        });
        renderMntGroups(form, res.rows || []);
    } catch (err) {
        await dialog.error(err);
    }
}

function updateInvoiceKpis(form) {
    let total = 0;
    let count = 0;
    if (form._kind === "customer") {
        const sheets = form._availableMnt || [];
        form.querySelectorAll('[data-field="mnt-list"] input[type="checkbox"]:checked').forEach((cb) => {
            const m = sheets.find((x) => Number(x.id_maintenance) === Number(cb.dataset.mntId));
            if (!m) {
                return;
            }
            count++;
            for (const d of m.lines || []) {
                total += detailTotals(d).totale;
            }
        });
    } else {
        const docs = form._availableDocs || [];
        form.querySelectorAll('[data-field="ddt-list"] input[type="checkbox"]:checked').forEach((cb) => {
            const doc = docs.find((d) => Number(d.id_document) === Number(cb.dataset.docId));
            if (!doc) {
                return;
            }
            count++;
            for (const d of doc.details || []) {
                total += detailTotals(d).totale;
            }
        });
    }
    form.querySelector('[data-kpi="total"]').textContent = fmtMoney(total);
    form.querySelector('[data-kpi="docs"]').textContent = String(count);
}

async function loadAvailableDdt(form, idSupplier, idInvoice) {
    if (!idSupplier) {
        form.querySelector('[data-field="ddt-list"]').innerHTML = "";
        form.querySelector('[data-field="ddt-hint"]').style.display = "";
        form._availableDocs = [];
        updateInvoiceKpis(form);
        return;
    }
    try {
        const url = `${window.FB.baseUrl}api/documents/available?supplier_id=${idSupplier}${idInvoice ? `&invoice_id=${idInvoice}` : ""}`;
        const res = await FetchHelper.get(url);
        const documents = res.documents || [];
        const linkedIds = documents.filter((d) => idInvoice && Number(d.id_invoice) === Number(idInvoice)).map((d) => Number(d.id_document));
        renderDdtGroups(form, documents, linkedIds);
    } catch (err) {
        await dialog.error(err);
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

function openInvoiceForm(invoice = null, kind = null) {
    const tpl = document.getElementById("tpl-invoice-form");
    const form = tpl.content.cloneNode(true).querySelector("form");
    // La fattura esistente decide il tipo; in creazione lo decide il bottone.
    const isCustomer = kind ? kind === "customer" : Number(invoice?.id_customer || 0) > 0;
    form._kind = isCustomer ? "customer" : "supplier";
    const title = invoice ? `Fattura ${invoice.number || ""}${isCustomer ? " — cliente" : ""}` : isCustomer ? "Nuova fattura cliente" : "Nuova fattura fornitore";

    // Mostra solo i campi/tabelle del tipo corrente
    form.querySelectorAll("[data-kind-group]").forEach((el) => {
        el.hidden = el.dataset.kindGroup !== form._kind;
    });
    const supplierSelect = form.querySelector('[data-field="supplier"]');
    const customerSelect = form.querySelector('[data-field="customer"]');
    const vehicleSelect = form.querySelector('[data-field="vehicle"]');
    supplierSelect.required = !isCustomer;
    supplierSelect.disabled = isCustomer;
    customerSelect.required = isCustomer;
    customerSelect.disabled = !isCustomer;
    form.querySelector('[data-field="ddt-tab-label"]').textContent = isCustomer ? "Manutenzione" : "DDT";
    form.querySelector('[data-kpi-label="docs"]').textContent = isCustomer ? "Schede manutenzione" : "Numero di DDT";

    let vehiclePicker = null;
    if (isCustomer) {
        populateCustomerSelect(customerSelect, invoice?.id_customer);
        populateVehicleSelect(vehicleSelect, null);
        vehiclePicker = new SearchableSelect(vehicleSelect, {
            placeholder: "Cerca targa…",
            emptyText: "— Seleziona targa —",
            onChange: (v) => loadAvailableMnt(form, Number(v) || 0, form.querySelector('[name="id_invoice"]').value || null),
        });
    } else {
        populateSupplierSelect(supplierSelect, invoice?.id_supplier);
    }

    if (invoice) {
        form.querySelector('[name="id_invoice"]').value = invoice.id_invoice;
        form.querySelector('[name="number"]').value = invoice.number || "";
        form.querySelector('[name="date"]').value = invoice.date || "";
        form.querySelector('[name="transport_fee"]').value = Number(invoice.transport_fee || 0);
        form.querySelector('[name="collection_fee"]').value = Number(invoice.collection_fee || 0);
        form.querySelector('[name="deposit"]').value = Number(invoice.deposit || 0);
        form.querySelector('[name="note"]').value = invoice.note || "";
    }

    const d = document.createElement("dialog");
    d.className = "fb-dialog";
    d.innerHTML = `<div class="fb-dialog-header"><h3 class="fb-dialog-title">${escapeHtml(title)}</h3></div><div class="fb-dialog-body"></div>`;
    d.querySelector(".fb-dialog-body").appendChild(form);
    document.body.appendChild(d);
    d.showModal();

    initFormTabs(form);

    if (isCustomer) {
        form.querySelector('[data-field="mnt-list"]').addEventListener("change", () => updateInvoiceKpis(form));

        // In modifica: la targa salvata sulla fattura pilota l'elenco;
        // in mancanza si risale dalle schede gia' collegate.
        if (invoice && invoice.id_customer) {
            if (Number(invoice.id_vehicle) > 0 && vehiclePicker) {
                vehiclePicker._select(String(invoice.id_vehicle));
            } else {
                FetchHelper.get(`${window.FB.baseUrl}api/maintenance/available`, { invoice_id: invoice.id_invoice })
                    .then((res) => {
                        const linked = (res.rows || []).filter((r) => r.linked);
                        if (linked.length > 0 && linked[0].id_vehicle && vehiclePicker) {
                            vehiclePicker._select(String(linked[0].id_vehicle));
                        } else {
                            renderMntGroups(form, res.rows || []);
                        }
                    })
                    .catch(() => {});
            }
        }
    } else {
        supplierSelect.addEventListener("change", () => {
            loadAvailableDdt(form, supplierSelect.value, form.querySelector('[name="id_invoice"]').value || null);
        });
        form.querySelector('[data-field="ddt-list"]').addEventListener("change", () => updateInvoiceKpis(form));

        // Per una fattura esistente: carica DDT disponibili + già collegati
        if (invoice && invoice.id_supplier) {
            loadAvailableDdt(form, invoice.id_supplier, invoice.id_invoice);
        }
    }

    form.addEventListener("submit", async (ev) => {
        ev.preventDefault();
        const data = Object.fromEntries(new FormData(form).entries());
        if (isCustomer) {
            const checkedIds = Array.from(form.querySelectorAll('[data-field="mnt-list"] input[type="checkbox"]:checked')).map((cb) => Number(cb.dataset.mntId));
            data.maintenance_ids = JSON.stringify(checkedIds);
        } else {
            const checkedIds = Array.from(form.querySelectorAll('[data-field="ddt-list"] input[type="checkbox"]:checked')).map((cb) => Number(cb.dataset.docId));
            data.document_ids = JSON.stringify(checkedIds);
        }

        const currentId = form.querySelector('[name="id_invoice"]').value;
        const url = currentId ? `${window.FB.baseUrl}api/invoices/${currentId}/update` : `${window.FB.baseUrl}api/invoices`;
        try {
            await FetchHelper.post(url, data);
            d.close();
            refreshTable();
        } catch (err) {
            await dialog.error(err);
        }
    });

    form.querySelector('[data-action="close"]').addEventListener("click", () => d.close());
    d.addEventListener("close", () => d.remove(), { once: true });
}

/* ---------- Visualizzazione fattura (sola lettura + stampa) ---------- */

async function openInvoiceView(row) {
    let invoice;
    try {
        const res = await FetchHelper.get(`${window.FB.baseUrl}api/invoices/${row.id_invoice}`);
        invoice = res.invoice;
    } catch (err) {
        await dialog.error(err);
        return;
    }

    let grandTotal = 0;
    const docsHtml = (invoice.documents || [])
        .map((doc) => {
            let docTotal = 0;
            const rowsHtml = (doc.details || [])
                .map((d) => {
                    const t = detailTotals(d);
                    docTotal += t.totale;
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
            grandTotal += docTotal;
            return `
            <div class="fb-ddt-group">
                <div class="fb-ddt-group-header">DDT ${escapeHtml(doc.number || doc.id_document)} del ${fmtDate(doc.date)}</div>
                <table class="fb-details-table">
                    <thead>
                        <tr>
                            <th class="num">Q.tà</th><th>Codice</th><th>Articolo</th>
                            <th class="num">Prezzo</th><th class="num">Sconto</th><th class="num">Importo</th>
                            <th>I.v.a.</th><th class="num">Totale</th>
                        </tr>
                    </thead>
                    <tbody>${rowsHtml || '<tr><td colspan="8" class="fb-muted" style="text-align:center">Nessun movimento.</td></tr>'}</tbody>
                </table>
                <div class="fb-details-total"><span class="fb-muted">Totale DDT</span><strong>${fmtMoney(docTotal)}</strong></div>
            </div>
        `;
        })
        .join("");

    const mntsHtml = (invoice.maintenances || [])
        .map((m) => {
            let mntTotal = 0;
            const rowsHtml = (m.details || [])
                .map((d) => {
                    const t = detailTotals(d);
                    mntTotal += t.totale;
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
            grandTotal += mntTotal;
            const km = Number(m.km) > 0 ? ` · ${Number(m.km).toLocaleString("it-IT")}\u00A0km` : "";
            return `
            <div class="fb-ddt-group">
                <div class="fb-ddt-group-header">Scheda del ${fmtDate(m.date)}${m.plate ? ` — ${escapeHtml(m.plate)}` : ""}${km}</div>
                <table class="fb-details-table">
                    <thead>
                        <tr>
                            <th class="num">Q.tà</th><th>Codice</th><th>Articolo</th>
                            <th class="num">Prezzo</th><th class="num">Sconto</th><th class="num">Importo</th>
                            <th>I.v.a.</th><th class="num">Totale</th>
                        </tr>
                    </thead>
                    <tbody>${rowsHtml || '<tr><td colspan="8" class="fb-muted" style="text-align:center">Nessun movimento.</td></tr>'}</tbody>
                </table>
                <div class="fb-details-total"><span class="fb-muted">Totale scheda</span><strong>${fmtMoney(mntTotal)}</strong></div>
            </div>
        `;
        })
        .join("");

    const fees = Number(invoice.transport_fee || 0) + Number(invoice.collection_fee || 0) + Number(invoice.deposit || 0);
    const isCustomer = Number(invoice.id_customer || 0) > 0;

    const dlg = document.createElement("dialog");
    dlg.className = "fb-dialog";
    dlg.innerHTML = `
        <div class="fb-dialog-header"><h3 class="fb-dialog-title">Fattura ${escapeHtml(invoice.number || invoice.id_invoice)}</h3></div>
        <div class="fb-dialog-body">
            <dl class="fb-view-grid">
                ${viewItem(isCustomer ? "Cliente" : "Fornitore", invoice.supplier_name || invoice.customer_name)}
                ${viewItem("Data", fmtDate(invoice.date))}
                ${viewItem("Numero", invoice.number)}
                ${viewItem(isCustomer ? "N. schede" : "N. DDT", isCustomer ? (invoice.maintenances || []).length : (invoice.documents || []).length)}
            </dl>
            ${
                fees !== 0
                    ? `<dl class="fb-view-grid">
                ${viewItem("Spese trasporto", fmtMoney(invoice.transport_fee))}
                ${viewItem("Spese di incasso", fmtMoney(invoice.collection_fee))}
                ${viewItem("Acconto", fmtMoney(invoice.deposit))}
            </dl>`
                    : ""
            }
            ${invoice.note ? `<p class="fb-muted">${escapeHtml(invoice.note)}</p>` : ""}
            <div class="fb-details-scroll">${docsHtml + mntsHtml || '<p class="fb-muted" style="padding:0.75rem">Nessun contenuto collegato.</p>'}</div>
            <div class="fb-details-total"><span class="fb-muted">Totale fattura</span><strong>${fmtMoney(grandTotal)}</strong></div>
            <div class="fb-form-actions">
                <button type="button" class="fb-btn fb-btn-primary" data-action="print">Stampa PDF</button>
                <button type="button" class="fb-btn fb-btn-secondary" data-action="close">Chiudi</button>
            </div>
        </div>
    `;
    document.body.appendChild(dlg);
    dlg.showModal();

    dlg.querySelector('[data-action="print"]').addEventListener("click", () => {
        window.open(`${window.FB.baseUrl}admin/invoices/${invoice.id_invoice}/print`, "_blank");
    });
    dlg.querySelector('[data-action="close"]').addEventListener("click", () => dlg.close());
    dlg.addEventListener("close", () => dlg.remove(), { once: true });
}

/* ---------- Associazione DDT alla fattura ---------- */

function renderDdtPicker(list, docs, linked, query = "") {
    list.innerHTML = "";
    const q = query.toLowerCase().trim();
    const filtered = docs.filter((d) => Number(d.id_invoice) > 0 === linked && `${d.number || ""} ${d.date || ""} ${fmtDate(d.date)}`.toLowerCase().includes(q));
    if (filtered.length === 0) {
        list.innerHTML = `<div class="fb-picker-item fb-muted" style="cursor:default">${linked ? "Nessun DDT associato." : "Nessun DDT disponibile per questo fornitore."}</div>`;
        return;
    }
    for (const doc of filtered) {
        const btn = document.createElement("button");
        btn.type = "button";
        btn.className = "fb-picker-item";
        btn.dataset.id = doc.id_document;
        btn.innerHTML = `
            <span class="fb-picker-main">DDT ${escapeHtml(doc.number || doc.id_document)}</span>
            <span class="fb-picker-sub">${ddtPickerMeta(doc)}</span>
        `;
        if (linked) {
            const unlink = document.createElement("span");
            unlink.className = "fb-picker-unlink";
            unlink.title = "Scollega dalla fattura";
            unlink.dataset.action = "unlink";
            unlink.innerHTML = ICONS.unlink;
            btn.appendChild(unlink);
        }
        list.appendChild(btn);
    }
}

function ddtPickerMeta(doc) {
    const rows = (doc.details || []).length;
    const total = (doc.details || []).reduce((acc, d) => acc + detailTotals(d).totale, 0);
    return `${fmtDate(doc.date)} · ${rows} righe · ${fmtMoney(total)}`;
}

async function openAssignDdtDialog(row) {
    // Fattura fornitore -> carichi (supplier_id); fattura cliente -> scarichi (customer_id)
    const partnerParam = row.id_supplier ? `supplier_id=${row.id_supplier}` : row.id_customer ? `customer_id=${row.id_customer}` : null;
    if (!partnerParam) {
        await dialog.alert("La fattura non ha un partner associato.", "Associa DDT");
        return;
    }

    let docs;
    try {
        const res = await FetchHelper.get(`${window.FB.baseUrl}api/documents/available?${partnerParam}&invoice_id=${row.id_invoice}`);
        docs = res.documents || [];
    } catch (err) {
        await dialog.error(err);
        return;
    }

    const dlg = document.createElement("dialog");
    dlg.className = "fb-dialog fb-dialog-medium";
    dlg.innerHTML = `
        <div class="fb-dialog-header"><h3 class="fb-dialog-title">Associa DDT — Fattura ${escapeHtml(row.number || row.id_invoice)}</h3></div>
        <div class="fb-dialog-body">
            <div class="fb-tabs fb-form-tabs" role="tablist">
                <button type="button" class="fb-tab active" data-tab="linked" role="tab" aria-selected="true">
                    DDT associati <span class="fb-picker-count" data-count="linked">0</span>
                </button>
                <button type="button" class="fb-tab" data-tab="available" role="tab" aria-selected="false">
                    Da associare <span class="fb-picker-count" data-count="available">0</span>
                </button>
                <button type="button" class="fb-tab" data-tab="search" role="tab" aria-selected="false">Ricerca</button>
            </div>
            <div class="fb-tab-panel active" data-panel="linked" role="tabpanel">
                <div class="fb-picker-list fb-picker-list-lg" data-field="linked"></div>
                <p class="fb-muted fb-picker-note">Clicca sull'icona a destra per scollegare il DDT dalla fattura (il documento resta intatto).</p>
            </div>
            <div class="fb-tab-panel" data-panel="available" role="tabpanel">
                <div class="fb-picker-list fb-picker-list-lg" data-field="available"></div>
                <p class="fb-muted fb-picker-note">Clicca su un DDT per associarlo a questa fattura.</p>
            </div>
            <div class="fb-tab-panel" data-panel="search" role="tabpanel">
                <input type="search" class="fb-form-input fb-picker-search" placeholder="Cerca per numero o data…" data-field="search">
                <div class="fb-picker-list fb-picker-list-lg" data-field="search-results"></div>
                <p class="fb-muted fb-picker-note">La ricerca filtra solo i DDT non ancora associati.</p>
            </div>
            <div class="fb-form-actions">
                <button type="button" class="fb-btn fb-btn-secondary" data-action="close">Chiudi</button>
            </div>
        </div>
    `;
    document.body.appendChild(dlg);
    dlg.showModal();

    const linkedList = dlg.querySelector('[data-field="linked"]');
    const availableList = dlg.querySelector('[data-field="available"]');
    const resultsList = dlg.querySelector('[data-field="search-results"]');
    const searchInput = dlg.querySelector('[data-field="search"]');
    const idInvoice = Number(row.id_invoice);

    const render = () => {
        renderDdtPicker(linkedList, docs, true);
        renderDdtPicker(availableList, docs, false);
        renderDdtPicker(resultsList, docs, false, searchInput.value);
        dlg.querySelector('[data-count="linked"]').textContent = docs.filter((d) => Number(d.id_invoice) > 0).length;
        dlg.querySelector('[data-count="available"]').textContent = docs.filter((d) => !d.id_invoice || Number(d.id_invoice) <= 0).length;
    };

    dlg.querySelectorAll(".fb-form-tabs .fb-tab").forEach((tab) => {
        tab.addEventListener("click", () => {
            dlg.querySelectorAll(".fb-form-tabs .fb-tab").forEach((t) => {
                t.classList.remove("active");
                t.setAttribute("aria-selected", "false");
            });
            tab.classList.add("active");
            tab.setAttribute("aria-selected", "true");
            dlg.querySelectorAll(".fb-tab-panel").forEach((p) => {
                p.classList.toggle("active", p.dataset.panel === tab.dataset.tab);
            });
        });
    });

    searchInput.addEventListener("input", () => {
        renderDdtPicker(resultsList, docs, false, searchInput.value);
    });

    dlg.addEventListener("click", async (ev) => {
        const item = ev.target.closest(".fb-picker-item[data-id]");
        if (!item || item.tagName !== "BUTTON") {
            return;
        }
        const doc = docs.find((x) => Number(x.id_document) === Number(item.dataset.id));
        if (!doc) {
            return;
        }
        const isUnlink = !!ev.target.closest('[data-action="unlink"]');
        const linked = Number(doc.id_invoice) > 0;
        if (linked && !isUnlink) {
            return; // i DDT associati si possono solo scollegare
        }
        try {
            await FetchHelper.post(`${window.FB.baseUrl}api/documents/${doc.id_document}/assign-invoice`, {
                id_invoice: isUnlink ? "" : String(idInvoice),
            });
            doc.id_invoice = isUnlink ? null : idInvoice;
            render();
        } catch (err) {
            await dialog.error(err);
        }
    });

    dlg.querySelector('[data-action="close"]').addEventListener("click", () => dlg.close());
    dlg.addEventListener(
        "close",
        () => {
            dlg.remove();
            refreshTable();
        },
        { once: true },
    );

    render();
}

/* ---------- Associazione schede manutenzione alla fattura cliente ---------- */

function renderMntPicker(list, sheets, linked, query = "") {
    list.innerHTML = "";
    const q = query.toLowerCase().trim();
    const filtered = sheets.filter((m) => !!m.linked === linked && `${m.plate || ""} ${m.note || ""} ${fmtDate(m.date)}`.toLowerCase().includes(q));
    if (filtered.length === 0) {
        list.innerHTML = `<div class="fb-picker-item fb-muted" style="cursor:default">${linked ? "Nessuna scheda associata." : "Nessuna scheda disponibile per questo automezzo."}</div>`;
        return;
    }
    for (const m of filtered) {
        const btn = document.createElement("button");
        btn.type = "button";
        btn.className = "fb-picker-item";
        btn.dataset.id = m.id_maintenance;
        const km = Number(m.km) > 0 ? ` · ${Number(m.km).toLocaleString("it-IT")}\u00A0km` : "";
        const meta = [m.plate ? `Targa ${m.plate}` : null, `${m.parts_count || 0} ricambi`, Number(m.tasks_count) > 0 ? `${m.tasks_count} lav.` : null, km ? km.slice(3) : null, fmtMoney(m.amount)].filter(Boolean).join(" · ");
        btn.innerHTML = `
            <span class="fb-picker-main">Scheda del ${fmtDate(m.date)}${m.note ? ` — ${escapeHtml(m.note)}` : ""}</span>
            <span class="fb-picker-sub">${meta}</span>
        `;
        if (linked) {
            const unlink = document.createElement("span");
            unlink.className = "fb-picker-unlink";
            unlink.title = "Scollega dalla fattura";
            unlink.dataset.action = "unlink";
            unlink.innerHTML = ICONS.unlink;
            btn.appendChild(unlink);
        }
        list.appendChild(btn);
    }
}

async function openAssignMaintenanceDialog(row) {
    if (Number(row.id_customer) <= 0) {
        await dialog.alert("Le schede manutenzione si associano solo alle fatture cliente.", "Associa manutenzioni");
        return;
    }
    const idInvoice = Number(row.id_invoice);

    const dlg = document.createElement("dialog");
    dlg.className = "fb-dialog fb-dialog-medium";
    dlg.innerHTML = `
        <div class="fb-dialog-header"><h3 class="fb-dialog-title">Associa manutenzioni — Fattura ${escapeHtml(row.number || row.id_invoice)}</h3></div>
        <div class="fb-dialog-body">
            <div class="fb-form-group">
                <label class="fb-form-label">Automezzo (targa)</label>
                <select class="fb-form-input" data-field="vehicle"></select>
            </div>
            <div class="fb-tabs fb-form-tabs" role="tablist">
                <button type="button" class="fb-tab active" data-tab="linked" role="tab" aria-selected="true">
                    Associate <span class="fb-picker-count" data-count="linked">0</span>
                </button>
                <button type="button" class="fb-tab" data-tab="available" role="tab" aria-selected="false">
                    Da associare <span class="fb-picker-count" data-count="available">0</span>
                </button>
                <button type="button" class="fb-tab" data-tab="search" role="tab" aria-selected="false">Ricerca</button>
            </div>
            <div class="fb-tab-panel active" data-panel="linked" role="tabpanel">
                <div class="fb-picker-list fb-picker-list-lg" data-field="linked"></div>
                <p class="fb-muted fb-picker-note">Clicca sull'icona a destra per scollegare la scheda (i dati restano intatti).</p>
            </div>
            <div class="fb-tab-panel" data-panel="available" role="tabpanel">
                <div class="fb-picker-list fb-picker-list-lg" data-field="available"></div>
                <p class="fb-muted fb-picker-note">Clicca su una scheda per associarla a questa fattura.</p>
            </div>
            <div class="fb-tab-panel" data-panel="search" role="tabpanel">
                <input type="search" class="fb-form-input fb-picker-search" placeholder="Cerca per targa, data o nota…" data-field="search">
                <div class="fb-picker-list fb-picker-list-lg" data-field="search-results"></div>
                <p class="fb-muted fb-picker-note">La ricerca filtra solo le schede non ancora associate.</p>
            </div>
            <div class="fb-form-actions">
                <button type="button" class="fb-btn fb-btn-secondary" data-action="close">Chiudi</button>
            </div>
        </div>
    `;
    document.body.appendChild(dlg);
    dlg.showModal();

    const linkedList = dlg.querySelector('[data-field="linked"]');
    const availableList = dlg.querySelector('[data-field="available"]');
    const resultsList = dlg.querySelector('[data-field="search-results"]');
    const searchInput = dlg.querySelector('[data-field="search"]');
    const vehicleEl = dlg.querySelector('[data-field="vehicle"]');

    let sheets = [];
    const render = () => {
        renderMntPicker(linkedList, sheets, true);
        renderMntPicker(availableList, sheets, false);
        renderMntPicker(resultsList, sheets, false, searchInput.value);
        dlg.querySelector('[data-count="linked"]').textContent = sheets.filter((m) => m.linked).length;
        dlg.querySelector('[data-count="available"]').textContent = sheets.filter((m) => !m.linked).length;
    };

    const loadSheets = async (idVehicle) => {
        const res = await FetchHelper.get(`${window.FB.baseUrl}api/maintenance/available`, {
            vehicle_id: idVehicle || 0,
            invoice_id: idInvoice,
        });
        sheets = res.rows || [];
        render();
    };

    populateVehicleSelect(vehicleEl, row.id_vehicle);
    const vehiclePicker = new SearchableSelect(vehicleEl, {
        placeholder: "Cerca targa…",
        emptyText: "— Seleziona targa —",
        onChange: (v) => loadSheets(Number(v) || 0).catch(async (e) => dialog.error(e)),
    });

    // elenco iniziale: veicolo salvato oppure solo le schede gia' collegate
    try {
        await loadSheets(Number(row.id_vehicle) || 0);
    } catch (err) {
        await dialog.error(err);
    }

    dlg.querySelectorAll(".fb-form-tabs .fb-tab").forEach((tab) => {
        tab.addEventListener("click", () => {
            dlg.querySelectorAll(".fb-form-tabs .fb-tab").forEach((t) => {
                t.classList.remove("active");
                t.setAttribute("aria-selected", "false");
            });
            tab.classList.add("active");
            tab.setAttribute("aria-selected", "true");
            dlg.querySelectorAll(".fb-tab-panel").forEach((p) => {
                p.classList.toggle("active", p.dataset.panel === tab.dataset.tab);
            });
        });
    });

    searchInput.addEventListener("input", () => {
        renderMntPicker(resultsList, sheets, false, searchInput.value);
    });

    dlg.addEventListener("click", async (ev) => {
        const item = ev.target.closest(".fb-picker-item[data-id]");
        if (!item || item.tagName !== "BUTTON") {
            return;
        }
        const mnt = sheets.find((x) => Number(x.id_maintenance) === Number(item.dataset.id));
        if (!mnt) {
            return;
        }
        const isUnlink = !!ev.target.closest('[data-action="unlink"]');
        if (mnt.linked && !isUnlink) {
            return; // le schede associate si possono solo scollegare
        }
        try {
            await FetchHelper.post(`${window.FB.baseUrl}api/invoices/${idInvoice}/assign-maintenance`, {
                id_maintenance: String(mnt.id_maintenance),
                remove: isUnlink ? "1" : "",
            });
            mnt.linked = !isUnlink;
            render();
        } catch (err) {
            await dialog.error(err);
        }
    });

    dlg.querySelector('[data-action="close"]').addEventListener("click", () => dlg.close());
    dlg.addEventListener(
        "close",
        () => {
            dlg.remove();
            refreshTable();
        },
        { once: true },
    );
}

/* ---------- Init ---------- */

async function loadOptions() {
    try {
        const [suppliers, customers, vehicles] = await Promise.all([FetchHelper.get(`${window.FB.baseUrl}api/suppliers`), FetchHelper.get(`${window.FB.baseUrl}api/customers`), FetchHelper.get(`${window.FB.baseUrl}api/vehicles`)]);
        suppliersCache = (suppliers.rows || []).filter((s) => Number(s.active) === 1);
        customersCache = (customers.rows || []).filter((c) => Number(c.active) === 1);
        vehiclesCache = (vehicles.rows || []).filter((v) => (v.status ?? "active") !== "retired");
    } catch (err) {
        await dialog.error(err);
    }
}

function initPage() {
    initInvoicesTable();
    document.querySelector('[data-action="new-invoice-supplier"]')?.addEventListener("click", () => openInvoiceForm(null, "supplier"));
    document.querySelector('[data-action="new-invoice-customer"]')?.addEventListener("click", () => openInvoiceForm(null, "customer"));
    PrintHelper.bindForceDownloadSwitch("#force-pdf-download");
    new PrintHelper({
        table: "#invoices-table",
        button: '[data-action="print-invoices"]',
        counter: "#invoices-selected-count",
        url: "admin/invoices/print",
        idField: "id_invoice",
        dialog,
    });
    new PrintHelper({
        table: "#invoices-table",
        button: '[data-action="print-invoice-details"]',
        counter: "#invoices-selected-count",
        url: "admin/invoices/print-details",
        idField: "id_invoice",
        dialog,
    });
    loadOptions();
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initPage);
} else {
    initPage();
}
