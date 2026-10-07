import FetchHelper from "../core/FetchHelper.js";
import DialogHelper from "../core/DialogHelper.js";
import PrintHelper from "../components/PrintHelper.js";
import TableSelect from "../components/TableSelect.js?v=2";
import { openPurchasePicker, loadProductPurchases } from "../components/PurchasePicker.js";
import { viewItem } from "../components/ViewGrid.js";

const dialog = new DialogHelper();
let suppliersCache = [];
let productsCache = [];
let docTypesCache = null;
let invoicesCache = null;
let lastVat = null;

// 'in' = carichi da fornitori (stock +), 'out' = scarichi verso clienti (stock -)
const DIRECTION = document.getElementById("documents-table")?.dataset.direction === "out" ? "out" : "in";
const PARTNER_FIELD = DIRECTION === "out" ? "id_customer" : "id_supplier";
const DOC_LABEL = DIRECTION === "out" ? "Scarico" : "Carico";

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

const ICONS = {
    check: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>',
    times: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>',
    eye: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>',
    edit: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.83 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5z"></path></svg>',
    link: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>',
    trash: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>',
};

function partnerName(row) {
    return escapeHtml(row.supplier_name || row.customer_name || "—");
}

function invoiceBadge(value, row) {
    if (row.id_invoice) {
        const title = row.invoice_number ? `Fattura ${row.invoice_number}` : "Fatturato";
        return `<span class="fb-ico fb-ico-ok" title="${escapeHtml(title)}">${ICONS.check}</span>`;
    }
    return `<span class="fb-ico fb-ico-no" title="Senza fattura">${ICONS.times}</span>`;
}

function formatActions(value, row) {
    const assignBtn = row.id_invoice ? "" : `<button type="button" class="fb-btn-icon fb-btn-icon-success" title="Associa a fattura" data-action="assign-invoice" data-id="${row.id_document}">${ICONS.link}</button>`;
    return `
        <div class="fb-btn-group">
            <button type="button" class="fb-btn-icon fb-btn-icon-info" title="Visualizza" data-action="view-document" data-id="${row.id_document}">${ICONS.eye}</button>
            <button type="button" class="fb-btn-icon" title="Modifica" data-action="edit-document" data-id="${row.id_document}">${ICONS.edit}</button>
            <button type="button" class="fb-btn-icon fb-btn-icon-danger" title="Elimina" data-action="delete-document" data-id="${row.id_document}">${ICONS.trash}</button>
            ${assignBtn}
        </div>
    `;
}

window.documentActionsEvents = {
    "click [data-action=view-document]": function (ev, value, row) {
        ev.stopPropagation();
        openDocumentView(row);
    },
    "click [data-action=edit-document]": function (ev, value, row) {
        ev.stopPropagation();
        openDocumentForm(row);
    },
    "click [data-action=assign-invoice]": function (ev, value, row) {
        ev.stopPropagation();
        openAssignInvoiceDialog(row);
    },
    "click [data-action=delete-document]": async function (ev, value, row) {
        ev.stopPropagation();
        if (!(await dialog.confirm(`Eliminare il documento "${escapeHtml(row.number || row.id_document)}"? Verranno eliminate le righe dettaglio e la giacenza verrà aggiornata.`, "Conferma"))) {
            return;
        }
        try {
            await FetchHelper.post(`${window.FB.baseUrl}api/documents/${row.id_document}/delete`, {});
            refreshTable();
        } catch (err) {
            await dialog.error(err);
        }
    },
};

async function loadDocTypes() {
    if (docTypesCache !== null) {
        return docTypesCache;
    }
    try {
        const res = await FetchHelper.get(`${window.FB.baseUrl}api/settings/document-types`);
        docTypesCache = res.rows || [];
    } catch (err) {
        docTypesCache = [];
    }
    window.fbDocTypeOptions = Object.fromEntries(docTypesCache.map((t) => [t.id, t.name]));
    return docTypesCache;
}

async function initDocumentsTable() {
    if (!window.$ || !window.$.fn.bootstrapTable) {
        setTimeout(initDocumentsTable, 100);
        return;
    }

    await loadDocTypes();

    window.$("#documents-table").bootstrapTable({
        url: window.FB.baseUrl + `api/documents?direction=${DIRECTION}`,
        sidePagination: "client",
        pagination: true,
        search: false,
        filterControl: true,
        sortable: true,
        locale: "it-IT",
        columns: [
            { field: "state", checkbox: true, align: "center", valign: "middle" },
            { field: "date", title: "Data", sortable: true, formatter: (v) => fmtDate(v), filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "number", title: "Numero", sortable: true, formatter: (v) => (v ? escapeHtml(v) : "—"), filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "supplier_name", title: DIRECTION === "out" ? "Cliente" : "Fornitore", sortable: true, formatter: (v, row) => partnerName(row), filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "invoice_number", title: "Fattura", sortable: true, align: "center", formatter: invoiceBadge, filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "type", title: "Tipo", sortable: true, align: "center", formatter: (v, row) => escapeHtml(row.type_name || "—"), searchFormatter: false, filterControl: "select", filterData: "var:fbDocTypeOptions" },
            { field: "actions", title: "Azioni", searchable: false, formatter: formatActions, events: window.documentActionsEvents },
        ],
    });
}

function refreshTable() {
    const $table = window.$("#documents-table");
    if ($table.length && typeof $table.bootstrapTable === "function") {
        $table.bootstrapTable("refresh");
    }
}

async function applyUninvoicedFilter() {
    const onlyUninvoiced = document.getElementById("documents-only-uninvoiced")?.checked;
    const $table = window.$("#documents-table");
    if (!$table.length) {
        return;
    }
    try {
        const res = await FetchHelper.get(`${window.FB.baseUrl}api/documents?direction=${DIRECTION}`);
        const rows = onlyUninvoiced ? res.rows.filter((r) => !r.id_invoice) : res.rows;
        $table.bootstrapTable("load", rows);
    } catch (err) {
        await dialog.error(err);
    }
}

/* ---------- Dialog documento ---------- */

function populateSupplierSelect(select, selectedId) {
    select.innerHTML = '<option value="">— Seleziona —</option>' + suppliersCache.map((s) => `<option value="${s[PARTNER_FIELD]}" ${Number(selectedId) === Number(s[PARTNER_FIELD]) ? "selected" : ""}>${escapeHtml(s.company)}</option>`).join("");
}

function populateTypeSelect(select, selectedId) {
    select.innerHTML = (docTypesCache || []).map((t) => `<option value="${t.id}" ${Number(selectedId ?? 0) === Number(t.id) ? "selected" : ""}>${escapeHtml(t.name)}</option>`).join("");
}

/**
 * Item per TableSelect: ricerca "a famiglia" — ogni riga (radice o alias)
 * porta in search i termini di tutta la famiglia, così cercando un codice
 * alias compaiono radice, alias trovato e fratelli.
 */
function buildProductItems() {
    const byId = new Map(productsCache.map((p) => [Number(p.id_product), p]));
    const childrenOf = new Map();
    for (const p of productsCache) {
        const pid = Number(p.id_alias || 0);
        if (pid > 0) {
            if (!childrenOf.has(pid)) {
                childrenOf.set(pid, []);
            }
            childrenOf.get(pid).push(p);
        }
    }
    return productsCache.map((p) => {
        const pid = Number(p.id_alias || 0);
        const root = pid > 0 ? byId.get(pid) : p;
        const members = root ? [root, ...(childrenOf.get(Number(root.id_product)) || [])] : [p];
        const search = members
            .map((m) => `${m.sku || ""} ${m.name || ""}`)
            .join(" ")
            .toLowerCase();
        return { value: String(p.id_product), label: p.label, search, data: p };
    });
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
            <td><button type="button" class="fb-btn fb-btn-secondary fb-btn-sm" data-action="del-detail" data-id="${d.id_document_detail}">✕</button></td>
        `;
        tbody.appendChild(tr);
    }
    if (details.length === 0) {
        tbody.innerHTML = '<tr><td colspan="9" class="fb-muted" style="text-align:center">Nessun movimento.</td></tr>';
    }

    form.querySelector('[data-kpi="total"]').textContent = fmtMoney(total);
    form.querySelector('[data-kpi="qty"]').textContent = fmtQty(qty);
    form.querySelector('[data-kpi="rows"]').textContent = String(details.length);

    // Ultima aliquota usata nel documento: fallback per i prodotti senza IVA
    const last = details[details.length - 1];
    lastVat = last && Number(last.vat_rate) > 0 ? Number(last.vat_rate) : lastVat;
}

async function loadDetails(form, idDocument) {
    try {
        const res = await FetchHelper.get(`${window.FB.baseUrl}api/documents/${idDocument}/details`);
        renderDetails(form, res.rows || []);
    } catch (err) {
        await dialog.error(err);
    }
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

function openDocumentForm(doc = null) {
    const tpl = document.getElementById("tpl-document-form");
    const form = tpl.content.cloneNode(true).querySelector("form");
    const title = doc ? `${DOC_LABEL} ${doc.number || ""}` : `Nuovo ${DOC_LABEL.toLowerCase()}`;

    populateSupplierSelect(form.querySelector('[data-field="supplier"]'), doc?.[PARTNER_FIELD]);
    const productSelEl = form.querySelector('[data-field="detail-product"]');
    const productById = new Map(productsCache.map((p) => [Number(p.id_product), p]));
    populateTypeSelect(form.querySelector('[data-field="type"]'), doc?.type);

    // Articolo → TableSelect: tabella con codice (+ radice per gli alias),
    // nome e giacenza colorata; ricerca a famiglia su tutta la gerarchia alias.
    const vatInput = form.querySelector('[data-field="detail-vat"]');
    const priceInput = form.querySelector('[data-field="detail-price"]');
    const qtyInput = form.querySelector('[data-field="detail-qty"]');
    const discountInput = form.querySelector('[data-field="detail-discount"]');
    // Carico → prezzo d'acquisto, Scarico → prezzo di vendita
    const priceField = DIRECTION === "out" ? "price" : "wholesale_price";
    let purchasesData = null;
    const productPicker = new TableSelect(productSelEl, {
        items: buildProductItems(),
        maxRows: 25,
        placeholder: "Cerca articolo per codice, alias o nome…",
        emptyText: "— Articolo —",
        columns: [
            {
                title: "Codice",
                width: "10rem",
                render: (item) => {
                    const p = item.data;
                    if (Number(p.id_alias || 0) > 0) {
                        const root = productById.get(Number(p.id_alias));
                        return `<div class="fb-ts-code-alias">${escapeHtml(p.sku || "—")}</div>` + (root ? `<div class="fb-ts-code-root">${escapeHtml(root.sku || "")}</div>` : "");
                    }
                    return `<span class="fb-ts-code">${escapeHtml(p.sku || "—")}</span>`;
                },
            },
            { title: "Articolo", render: (item) => escapeHtml(item.data.name || "—") },
            {
                title: "Giacenza",
                width: "5.5rem",
                align: "right",
                render: (item) => {
                    const q = Number(item.data.stock_qty ?? 0);
                    const cls = q > 0 ? "pos" : q < 0 ? "neg" : "zero";
                    return `<span class="fb-ts-qty fb-ts-qty-${cls}">${q.toLocaleString("it-IT")}</span>`;
                },
            },
        ],
        // Prima il match diretto (il codice/nome cercato), poi il resto
        // della famiglia e gli altri risultati per giacenza decrescente.
        sortResults: (items, term) => {
            const isDirect = (i) => i.data && `${i.data.sku || ""} ${i.data.name || ""}`.toLowerCase().includes(term);
            const direct = [];
            const rest = [];
            items.forEach((i) => (isDirect(i) ? direct : rest).push(i));
            rest.sort((a, b) => Number(b.data?.stock_qty ?? 0) - Number(a.data?.stock_qty ?? 0));
            return [...direct, ...rest];
        },
        onChange: async (value) => {
            const p = productsCache.find((pr) => Number(pr.id_product) === Number(value));
            purchasesData = null;
            if (!p) {
                return;
            }
            vatInput.value = Number(p.tax_rate) > 0 ? Number(p.tax_rate) : "0.00";
            qtyInput.focus();
            // Carico: il prezzo proposto è quello dell'ultimo acquisto effettuato
            if (DIRECTION === "in") {
                try {
                    purchasesData = await loadProductPurchases(p.id_product);
                    const lastPrice = purchasesData?.last ? Number(purchasesData.last.price) : null;
                    priceInput.value = Number(lastPrice ?? p[priceField] ?? 0).toFixed(2);
                } catch (err) {
                    priceInput.value = Number(p[priceField] ?? 0).toFixed(2);
                }
            } else {
                priceInput.value = Number(p[priceField] ?? 0).toFixed(2);
            }
        },
    });

    // Sui carichi, il focus sul prezzo apre lo storico acquisti per fornitore
    // La lente accanto al prezzo apre lo storico acquisti per fornitore
    // (solo sui carichi: nascosta sugli scarichi)
    const searchBtn = form.querySelector('[data-action="search-price"]');
    if (DIRECTION !== "in" && searchBtn) {
        searchBtn.style.display = "none";
    }
    searchBtn?.addEventListener("click", () => {
        if (DIRECTION !== "in" || !productPicker.value) {
            return;
        }
        openPurchasePicker(purchasesData, (row) => {
            priceInput.value = Number(row.price).toFixed(2);
            discountInput.focus();
        });
    });

    if (doc) {
        form.querySelector('[name="id_document"]').value = doc.id_document;
        form.querySelector('[name="number"]').value = doc.number || "";
        form.querySelector('[name="date"]').value = doc.date || "";
        form.querySelector('[name="note"]').value = doc.note || "";
    }

    const isNew = !doc;
    setDetailsEnabled(form, !isNew);

    const d = document.createElement("dialog");
    d.className = "fb-dialog fb-dialog-medium";
    d.innerHTML = `<div class="fb-dialog-header"><h3 class="fb-dialog-title">${escapeHtml(title)}</h3></div><div class="fb-dialog-body"></div>`;
    d.querySelector(".fb-dialog-body").appendChild(form);
    document.body.appendChild(d);
    d.showModal();

    initFormTabs(form);

    if (doc) {
        loadDetails(form, doc.id_document);
    } else {
        renderDetails(form, []);
    }

    form.addEventListener("submit", async (ev) => {
        ev.preventDefault();
        const data = Object.fromEntries(new FormData(form).entries());
        const currentId = form.querySelector('[name="id_document"]').value;
        const url = currentId ? `${window.FB.baseUrl}api/documents/${currentId}/update` : `${window.FB.baseUrl}api/documents`;
        try {
            const res = await FetchHelper.post(url, data);
            refreshTable();
            if (currentId) {
                d.close();
            } else {
                // Dopo il salvataggio iniziale, abilita la gestione dei movimenti
                form.querySelector('[name="id_document"]').value = res.id;
                setDetailsEnabled(form, true);
                await dialog.alert("Documento salvato. Ora puoi aggiungere i movimenti.", "OK");
            }
        } catch (err) {
            await dialog.error(err);
        }
    });

    form.querySelector('[data-action="add-detail"]').addEventListener("click", async () => {
        const idDocument = form.querySelector('[name="id_document"]').value;
        if (!idDocument) {
            return;
        }
        const data = {
            id_product: productPicker.value,
            quantity: form.querySelector('[data-field="detail-qty"]').value,
            price: form.querySelector('[data-field="detail-price"]').value,
            discount: form.querySelector('[data-field="detail-discount"]').value,
            vat_rate: form.querySelector('[data-field="detail-vat"]').value,
        };
        try {
            await FetchHelper.post(`${window.FB.baseUrl}api/documents/${idDocument}/details`, data);
            productPicker.clear();
            purchasesData = null;
            form.querySelector('[data-field="detail-qty"]').value = "";
            form.querySelector('[data-field="detail-price"]').value = "";
            form.querySelector('[data-field="detail-discount"]').value = "";
            form.querySelector('[data-field="detail-vat"]').value = "";
            await loadDetails(form, idDocument);
        } catch (err) {
            await dialog.error(err);
        }
    });

    form.querySelector('[data-field="details-body"]').addEventListener("click", async (ev) => {
        const btn = ev.target.closest('[data-action="del-detail"]');
        if (!btn) {
            return;
        }
        if (!(await dialog.confirm("Eliminare questa riga?", "Conferma"))) {
            return;
        }
        try {
            await FetchHelper.post(`${window.FB.baseUrl}api/documents/details/${btn.dataset.id}/delete`, {});
            await loadDetails(form, form.querySelector('[name="id_document"]').value);
        } catch (err) {
            await dialog.error(err);
        }
    });

    form.querySelector('[data-action="close"]').addEventListener("click", () => d.close());
    d.addEventListener("close", () => d.remove(), { once: true });
}

/* ---------- Visualizzazione documento (sola lettura + stampa) ---------- */

async function openDocumentView(row) {
    let doc;
    try {
        const res = await FetchHelper.get(`${window.FB.baseUrl}api/documents/${row.id_document}`);
        doc = res.document;
    } catch (err) {
        await dialog.error(err);
        return;
    }

    let total = 0;
    const rowsHtml = (doc.details || [])
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

    const dlg = document.createElement("dialog");
    dlg.className = "fb-dialog";
    dlg.innerHTML = `
        <div class="fb-dialog-header"><h3 class="fb-dialog-title">${DOC_LABEL} ${escapeHtml(doc.number || doc.id_document)}</h3></div>
        <div class="fb-dialog-body">
            <dl class="fb-view-grid">
                ${viewItem(DIRECTION === "out" ? "Cliente" : "Fornitore", doc.supplier_name || doc.customer_name)}
                ${viewItem("Data", fmtDate(doc.date))}
                ${viewItem("Numero", doc.number)}
                ${viewItem("Fattura", doc.invoice_number, { icon: "hash" })}
                ${viewItem("Tipo", doc.type_name)}
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
                    <tbody>${rowsHtml || '<tr><td colspan="8" class="fb-muted" style="text-align:center">Nessun movimento.</td></tr>'}</tbody>
                </table>
            </div>
            <div class="fb-details-total"><span class="fb-muted">Totale documento</span><strong>${fmtMoney(total)}</strong></div>
            <div class="fb-form-actions">
                <button type="button" class="fb-btn fb-btn-primary" data-action="print">Stampa PDF</button>
                <button type="button" class="fb-btn fb-btn-secondary" data-action="close">Chiudi</button>
            </div>
        </div>
    `;
    document.body.appendChild(dlg);
    dlg.showModal();

    dlg.querySelector('[data-action="print"]').addEventListener("click", () => {
        window.open(`${window.FB.baseUrl}admin/documents/${doc.id_document}/print`, "_blank");
    });
    dlg.querySelector('[data-action="close"]').addEventListener("click", () => dlg.close());
    dlg.addEventListener("close", () => dlg.remove(), { once: true });
}

/* ---------- Associazione a fattura ---------- */

async function loadInvoices() {
    if (invoicesCache !== null) {
        return invoicesCache;
    }
    const res = await FetchHelper.get(`${window.FB.baseUrl}api/invoices`);
    invoicesCache = res.rows || [];
    return invoicesCache;
}

function renderInvoicePicker(list, invoices, docPartnerId) {
    list.innerHTML = "";
    const filtered = docPartnerId ? invoices.filter((i) => Number(i[PARTNER_FIELD]) === Number(docPartnerId)) : invoices;
    if (filtered.length === 0) {
        list.innerHTML = '<div class="fb-picker-item fb-muted" style="cursor:default">Nessuna fattura disponibile.</div>';
        return;
    }
    for (const inv of filtered) {
        const btn = document.createElement("button");
        btn.type = "button";
        btn.className = "fb-picker-item";
        btn.dataset.id = inv.id_invoice;
        btn.innerHTML = `
            <span class="fb-picker-main">${escapeHtml(inv.number || "s.n.")}</span>
            <span class="fb-picker-sub">${escapeHtml(inv.supplier_name || inv.customer_name || "")} — ${fmtDate(inv.date)}</span>
        `;
        btn.dataset.search = `${inv.number || ""} ${inv.supplier_name || inv.customer_name || ""} ${inv.date || ""} ${fmtDate(inv.date)}`.toLowerCase();
        list.appendChild(btn);
    }
}

async function openAssignInvoiceDialog(row) {
    let invoices;
    try {
        invoices = await loadInvoices();
    } catch (err) {
        await dialog.error(err);
        return;
    }

    const d = document.createElement("dialog");
    d.className = "fb-dialog";
    d.innerHTML = `
        <div class="fb-dialog-header"><h3 class="fb-dialog-title">Associa a fattura — ${DOC_LABEL.toLowerCase()} ${escapeHtml(row.number || row.id_document)}</h3></div>
        <div class="fb-dialog-body">
            <input type="search" class="fb-form-input fb-picker-search" placeholder="Cerca per numero, data o fornitore…" data-field="search">
            <div class="fb-picker-list" data-field="list"></div>
            <div class="fb-form-actions">
                ${row.id_invoice ? '<button type="button" class="fb-btn fb-btn-secondary" data-action="unlink">Scollega dalla fattura</button>' : ""}
                <button type="button" class="fb-btn fb-btn-secondary" data-action="close">Annulla</button>
            </div>
        </div>
    `;
    document.body.appendChild(d);
    d.showModal();

    const list = d.querySelector('[data-field="list"]');
    renderInvoicePicker(list, invoices, row[PARTNER_FIELD]);

    d.querySelector('[data-field="search"]').addEventListener("input", (ev) => {
        const q = ev.target.value.toLowerCase().trim();
        list.querySelectorAll(".fb-picker-item[data-id]").forEach((item) => {
            item.style.display = item.dataset.search.includes(q) ? "" : "none";
        });
    });

    list.addEventListener("click", async (ev) => {
        const item = ev.target.closest(".fb-picker-item[data-id]");
        if (!item) {
            return;
        }
        try {
            await FetchHelper.post(`${window.FB.baseUrl}api/documents/${row.id_document}/assign-invoice`, { id_invoice: item.dataset.id });
            d.close();
            refreshTable();
        } catch (err) {
            await dialog.error(err);
        }
    });

    d.querySelector('[data-action="unlink"]')?.addEventListener("click", async () => {
        try {
            await FetchHelper.post(`${window.FB.baseUrl}api/documents/${row.id_document}/assign-invoice`, { id_invoice: "" });
            d.close();
            refreshTable();
        } catch (err) {
            await dialog.error(err);
        }
    });

    d.querySelector('[data-action="close"]').addEventListener("click", () => d.close());
    d.addEventListener("close", () => d.remove(), { once: true });
}

/* ---------- Init ---------- */

async function loadOptions() {
    const partnersUrl = DIRECTION === "out" ? "api/customers" : "api/suppliers";
    try {
        const [partners, products] = await Promise.all([FetchHelper.get(`${window.FB.baseUrl}${partnersUrl}`), FetchHelper.get(`${window.FB.baseUrl}api/products/options`)]);
        suppliersCache = (partners.rows || []).filter((s) => Number(s.active) === 1);
        productsCache = [...(products.products || []), ...(products.alias_products || [])].sort((a, b) => String(a.label).localeCompare(String(b.label), "it"));
    } catch (err) {
        await dialog.error(err);
    }
}

function initPage() {
    initDocumentsTable();
    document.querySelector('[data-action="new-document"]')?.addEventListener("click", () => openDocumentForm());
    document.getElementById("documents-only-uninvoiced")?.addEventListener("change", applyUninvoicedFilter);
    new PrintHelper({
        table: "#documents-table",
        button: '[data-action="print-documents"]',
        counter: "#documents-selected-count",
        url: DIRECTION === "out" ? "admin/unloads/print" : "admin/documents/print",
        idField: "id_document",
        dialog,
    });
    loadOptions();

    // ?open=<id> — apre direttamente la scheda del documento (link dalle giacenze)
    const openId = new URLSearchParams(location.search).get("open");
    if (openId) {
        FetchHelper.get(`${window.FB.baseUrl}api/documents/${openId}`)
            .then((res) => res?.document && openDocumentForm(res.document))
            .catch(() => {});
    }
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initPage);
} else {
    initPage();
}
