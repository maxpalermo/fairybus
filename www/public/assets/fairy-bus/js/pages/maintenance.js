import FetchHelper from "../core/FetchHelper.js";
import DialogHelper from "../core/DialogHelper.js";
import PrintHelper from "../components/PrintHelper.js";
import SearchableSelect from "../components/SearchableSelect.js?v=4";
import TableSelect from "../components/TableSelect.js?v=2";
import { openPurchasePicker, loadProductPurchases } from "../components/PurchasePicker.js";
import { viewItem } from "../components/ViewGrid.js";

const dialog = new DialogHelper();
let vehiclesCache = [];
let productsCache = [];
let lotsCache = [];
let lastDetailsRows = [];
const PRICE_SEARCH_KEY = "fb_mnt_price_search";

function priceSearchOn(form) {
    const sw = form?.querySelector("#mnt-price-search");
    return sw ? sw.checked : localStorage.getItem(PRICE_SEARCH_KEY) !== "0";
}
let invoicesCache = null;
// Ultima aliquota IVA usata nei ricambi (fallback per prodotti senza IVA)
let lastVat = null;
// Costo orario globale (fb_configuration.hourly_cost)
let hourlyCost = null;

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
    select.innerHTML = '<option value="">— Seleziona —</option>' + vehiclesCache.map((v) => `<option value="${v.id_vehicle}" ${Number(selectedId) === Number(v.id_vehicle) ? "selected" : ""} data-search="${escapeHtml(`${v.plate || ""} ${v.brand_name || ""} ${v.description || ""}`)}">${escapeHtml(v.plate)}${v.brand_name ? " — " + escapeHtml(v.brand_name) : ""}</option>`).join("");
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

/**
 * Items per la ricerca "per prezzo d'acquisto": ogni prodotto compare una
 * volta per lotto residuo (stesso articolo a prezzi diversi = righe diverse).
 */
function buildLotItems() {
    const byId = new Map(lotsCache.map((p) => [Number(p.id_product), p]));
    const childrenOf = new Map();
    for (const p of lotsCache) {
        const pid = Number(p.id_alias || 0);
        if (pid > 0) {
            if (!childrenOf.has(pid)) {
                childrenOf.set(pid, []);
            }
            childrenOf.get(pid).push(p);
        }
    }
    return lotsCache
        .filter((p) => Number(p.lot_qty ?? p.stock_qty ?? 0) > 0)
        .map((p) => {
            const pid = Number(p.id_alias || 0);
            const root = pid > 0 ? byId.get(pid) : p;
            const members = root ? [root, ...(childrenOf.get(Number(root.id_product)) || [])] : [p];
            const search = members
                .map((m) => `${m.sku || ""} ${m.name || ""}`)
                .join(" ")
                .toLowerCase();
            const price = Number(p.lot_price ?? 0);
            return { value: String(p.id_product), label: `${p.label} · €\u00A0${price.toFixed(2)}`, search, data: p };
        });
}

function detailTotals(detail) {
    const qty = Number(detail.quantity || 0);
    const price = Number(detail.price || 0);
    const discount = Number(detail.discount || 0);
    const vat = detail.vat_rate !== null && detail.vat_rate !== undefined ? Number(detail.vat_rate) : 0;
    const importo = qty * price * (1 + discount / 100);
    const manodopera = Number(detail.labor_manpower || 0);
    return { importo, manodopera, totale: (importo + manodopera) * (1 + vat / 100) };
}

function vatLabel(detail) {
    const vat = detail.vat_code ?? detail.vat_rate;
    if (vat === null || vat === undefined || vat === "") {
        return "—";
    }
    const num = Number(vat);
    return isNaN(num) ? escapeHtml(String(vat)) : `${num.toLocaleString("it-IT", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}%`;
}

/**
 * Aggiorna le giacenze in cache dopo un movimento ricambi:
 * delta < 0 = scarico, delta > 0 = rientro. Il delta si applica al
 * lotto con lo stesso prezzo; senza corrispondenza crea una nuova
 * voce di lotto (anche negativa, come fa il backend).
 */
function adjustStockCaches(idProduct, delta, price = null) {
    const prod = productsCache.find((p) => Number(p.id_product) === Number(idProduct));
    if (prod) {
        prod.stock_qty = Number(prod.stock_qty ?? 0) + delta;
    }
    const lots = lotsCache.filter((p) => Number(p.id_product) === Number(idProduct));
    let target = price !== null ? lots.find((l) => Math.abs(Number(l.lot_price) - Number(price)) < 0.0001) : null;
    if (!target && price === null && lots.length > 0) {
        target = delta < 0 ? lots.find((l) => Number(l.lot_qty) > 0) || lots[0] : lots[lots.length - 1];
    }
    if (!target && prod) {
        target = { ...prod, lot_price: Number(price ?? prod.price ?? 0), lot_qty: 0, stock_qty: 0 };
        lotsCache.push(target);
    }
    if (target) {
        target.lot_qty = Number(target.lot_qty ?? 0) + delta;
        target.stock_qty = target.lot_qty;
    }
}

function renderDetails(form, details) {
    lastDetailsRows = details || [];
    const tbody = form.querySelector('[data-field="details-body"]');
    tbody.innerHTML = "";

    let total = 0;
    let qty = 0;
    for (const d of details) {
        const t = detailTotals(d);
        // Righe legacy importate con quantità negativa: i ricambi sono uno
        // scarico, si mostrano sempre in valore assoluto come negli scarichi.
        total += Math.abs(t.totale);
        qty += Math.abs(Number(d.quantity || 0));
        const tr = document.createElement("tr");
        tr.innerHTML = `
            <td>${escapeHtml(d.sku || "—")}</td>
            <td>${escapeHtml(d.product_name || "—")}</td>
            <td class="num">${fmtQty(Math.abs(d.quantity || 0))}</td>
            <td class="num">${fmtMoney(d.price)}</td>
            <td class="num">${Number(d.discount || 0)}%</td>
            <td class="num">${fmtMoney(Math.abs(t.importo))}</td>
            <td class="num">${d.labor_hours !== null && d.labor_hours !== undefined ? fmtQty(Math.abs(d.labor_hours)) : "—"}</td>
            <td class="num">${d.labor_price_per_hour !== null && d.labor_price_per_hour !== undefined ? fmtMoney(d.labor_price_per_hour) : "—"}</td>
            <td class="num">${d.labor_manpower !== null && d.labor_manpower !== undefined ? fmtMoney(Math.abs(d.labor_manpower)) : "—"}</td>
            <td>${vatLabel(d)}</td>
            <td class="num">${fmtMoney(Math.abs(t.totale))}</td>
            <td><button type="button" class="fb-btn fb-btn-secondary fb-btn-sm" data-action="del-detail" data-id="${d.id_maintenance_detail}">✕</button></td>
        `;
        tbody.appendChild(tr);
    }
    if (details.length === 0) {
        tbody.innerHTML = '<tr><td colspan="12" class="fb-muted" style="text-align:center">Nessun ricambio.</td></tr>';
    }

    form.querySelector('[data-kpi="total"]').textContent = fmtMoney(total);
    form.querySelector('[data-kpi="qty"]').textContent = fmtQty(qty);
    form.querySelector('[data-kpi="rows"]').textContent = String(details.length);

    // Ultima aliquota usata nei ricambi: fallback per i prodotti senza IVA
    const last = details[details.length - 1];
    lastVat = last && Number(last.vat_rate) > 0 ? Number(last.vat_rate) : lastVat;
}

async function loadDetails(form, idMaintenance) {
    try {
        const res = await FetchHelper.get(`${window.FB.baseUrl}api/maintenance/${idMaintenance}/details`);
        renderDetails(form, res.rows || []);
        updateLaborBox(form, res.labor);
    } catch (err) {
        await dialog.error(err);
    }
}

function updateLaborBox(form, labor) {
    const hoursEl = form.querySelector('[data-kpi="labor-hours"]');
    const manpowerEl = form.querySelector('[data-kpi="labor-manpower"]');
    if (hoursEl) {
        hoursEl.textContent = `${fmtQty(labor?.hours || 0)} h`;
    }
    if (manpowerEl) {
        manpowerEl.textContent = fmtMoney(labor?.manpower || 0);
    }
}

/* ---------- Km: ultimi registrati / attuali / differenza ---------- */

function setKmLast(form, idVehicle) {
    const v = vehiclesCache.find((x) => Number(x.id_vehicle) === Number(idVehicle));
    const el = form.querySelector('[data-field="km-last"]');
    if (!el) {
        return;
    }
    const km = v ? Number(v.current_km || 0) : NaN;
    el.dataset.km = isNaN(km) ? "" : String(km);
    el.value = isNaN(km) ? "—" : `${km.toLocaleString("it-IT")} km`;
    updateKmDiff(form);
}

function kmDiffNegative(form) {
    const last = Number(form.querySelector('[data-field="km-last"]')?.dataset.km);
    const kmInput = form.querySelector('[name="km"]');
    const cur = Number(kmInput?.value);
    return kmInput?.value !== "" && !isNaN(last) && !isNaN(cur) && cur < last;
}

function updateKmDiff(form) {
    const lastEl = form.querySelector('[data-field="km-last"]');
    const diffEl = form.querySelector('[data-field="km-diff"]');
    const warn = form.querySelector('[data-field="km-warn"]');
    const kmInput = form.querySelector('[name="km"]');
    if (!lastEl || !diffEl || !kmInput) {
        return;
    }
    const last = Number(lastEl.dataset.km);
    diffEl.classList.remove("fb-km-diff-pos", "fb-km-diff-neg");
    if (kmInput.value === "" || isNaN(last)) {
        diffEl.value = "—";
        if (warn) {
            warn.hidden = true;
        }
        return;
    }
    const diff = Number(kmInput.value) - last;
    diffEl.value = `${diff > 0 ? "+" : ""}${diff.toLocaleString("it-IT")} km`;
    diffEl.classList.add(diff >= 0 ? "fb-km-diff-pos" : "fb-km-diff-neg");
    if (warn) {
        warn.hidden = diff >= 0;
    }
}

/* ---------- Riga inserimento: importo / manodopera / totale live ---------- */

function updateDetailRow(form) {
    const qty = Number(form.querySelector('[data-field="detail-qty"]')?.value || 0);
    const price = Number(form.querySelector('[data-field="detail-price"]')?.value || 0);
    const discount = Number(form.querySelector('[data-field="detail-discount"]')?.value || 0);
    const vat = Number(form.querySelector('[data-field="detail-vat"]')?.value || 0);
    const hours = Number(form.querySelector('[data-field="detail-hours"]')?.value || 0);
    const priceHour = Number(form.querySelector('[data-field="detail-price-hour"]')?.value || 0);
    const importo = qty * price * (1 + discount / 100);
    const manodopera = hours * priceHour;
    form.querySelector('[data-field="detail-importo"]').value = importo > 0 ? fmtMoney(importo) : "";
    form.querySelector('[data-field="detail-manpower"]').value = manodopera > 0 ? fmtMoney(manodopera) : "";
    form.querySelector('[data-field="detail-total"]').value = importo > 0 || manodopera > 0 ? fmtMoney((importo + manodopera) * (1 + vat / 100)) : "";
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
    // L'overlay copre il contenuto dei tab Ricambi/Fatture finché la
    // manutenzione non è stata salvata; il contenuto resta visibile sotto.
    form.querySelectorAll('[data-field="locked-overlay"]').forEach((o) => {
        o.hidden = enabled;
    });
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
            // I bottoni Salva/Annulla servono solo nel tab Informazioni: nei
            // tab Ricambi/Fatture le azioni scrivono subito sul database.
            const actions = form.querySelector(".fb-form-actions");
            if (actions) {
                actions.style.display = panelName === "info" ? "" : "none";
            }
        });
    });
}

async function openMaintenanceForm(row = null) {
    const tpl = document.getElementById("tpl-maintenance-form");
    const form = tpl.content.cloneNode(true).querySelector("form");
    const title = row ? `Manutenzione ${row.vehicle_plate || ""}` : "Nuova manutenzione";

    const vehicleSelEl = form.querySelector('[data-field="vehicle"]');
    populateVehicleSelect(vehicleSelEl, row?.id_vehicle);
    const productSelEl = form.querySelector('[data-field="detail-product"]');
    const productById = new Map(productsCache.map((p) => [Number(p.id_product), p]));
    populateInvoiceSelect(form.querySelector('[data-field="invoice-select"]'));

    // Articolo → TableSelect: tabella con codice (+ radice per gli alias),
    // nome e giacenza colorata; ricerca a famiglia su tutta la gerarchia alias.
    const vatInput = form.querySelector('[data-field="detail-vat"]');
    const priceInput = form.querySelector('[data-field="detail-price"]');
    const qtyInput = form.querySelector('[data-field="detail-qty"]');
    let purchasesData = null;
    // Switch "Cerca per prezzo d'acquisto": persiste in localStorage (default ON).
    const priceSwitch = form.querySelector("#mnt-price-search");
    if (priceSwitch) {
        priceSwitch.checked = localStorage.getItem(PRICE_SEARCH_KEY) !== "0";
    }
    const pickerColumns = (lotMode) => [
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
        ...(lotMode
            ? [
                  {
                      title: "Prezzo acq.",
                      width: "6rem",
                      align: "right",
                      render: (item) => `<span class="fb-ts-price">€\u00A0${Number(item.data.lot_price ?? 0).toFixed(2)}</span>`,
                  },
              ]
            : []),
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
    ];
    const productPicker = new TableSelect(productSelEl, {
        items: priceSearchOn(form) ? buildLotItems() : buildProductItems(),
        maxRows: 25,
        placeholder: "Cerca articolo per codice, alias o nome…",
        emptyText: "— Articolo —",
        columns: pickerColumns(priceSearchOn(form)),
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
        onChange: async (value, item) => {
            const p = productsCache.find((pr) => Number(pr.id_product) === Number(value));
            purchasesData = null;
            if (!p) {
                return;
            }
            vatInput.value = Number(p.tax_rate) > 0 ? Number(p.tax_rate) : "0.00";
            qtyInput.focus();
            // In modalità "per prezzo d'acquisto" il prezzo è quello del lotto scelto
            const lotPrice = item?.data?.lot_price;
            if (priceSwitch?.checked && lotPrice !== null && lotPrice !== undefined) {
                priceInput.value = Number(lotPrice).toFixed(2);
                updateDetailRow(form);
                loadProductPurchases(p.id_product)
                    .then((d) => (purchasesData = d))
                    .catch(() => {});
                return;
            }
            // Altrimenti il prezzo proposto è quello dell'ultimo acquisto effettuato
            try {
                purchasesData = await loadProductPurchases(p.id_product);
                const lastPrice = purchasesData?.last ? Number(purchasesData.last.price) : null;
                priceInput.value = Number(lastPrice ?? p.price ?? 0).toFixed(2);
            } catch (err) {
                priceInput.value = Number(p.price ?? 0).toFixed(2);
            }
            updateDetailRow(form);
        },
    });

    const refreshPickerItems = () => {
        productPicker.setItems(priceSwitch?.checked ? buildLotItems() : buildProductItems());
    };
    priceSwitch?.addEventListener("change", () => {
        localStorage.setItem(PRICE_SEARCH_KEY, priceSwitch.checked ? "1" : "0");
        const lot = priceSwitch.checked;
        productPicker.columns = pickerColumns(lot);
        productPicker.setItems(lot ? buildLotItems() : buildProductItems());
        productPicker.clear();
    });

    // Il focus sul prezzo apre lo storico acquisti per fornitore
    // La lente accanto al prezzo apre lo storico acquisti per fornitore
    form.querySelector('[data-action="search-price"]')?.addEventListener("click", () => {
        if (!productPicker.value) {
            return;
        }
        openPurchasePicker(purchasesData, (row) => {
            priceInput.value = Number(row.price).toFixed(2);
            updateDetailRow(form);
            form.querySelector('[data-field="detail-discount"]')?.focus();
        });
    });

    if (row) {
        form.querySelector('[name="id_maintenance"]').value = row.id_maintenance;
        form.querySelector('[name="date"]').value = row.date || "";
        form.querySelector('[name="km"]').value = row.km || "";
        form.querySelector('[name="note"]').value = row.note || "";

        // carica il task generale (id_maintenance_detail = 0) per la descrizione
        try {
            const res = await FetchHelper.get(`${window.FB.baseUrl}api/maintenance/${row.id_maintenance}`);
            const task = (res.maintenance?.tasks || []).find((t) => Number(t.id_maintenance_detail ?? 0) === 0) || res.maintenance?.tasks?.[0];
            if (task) {
                form.querySelector('[name="task_description"]').value = task.description || "";
            }
        } catch (err) {
            /* ignora: i campi restano vuoti */
        }
    }

    // Veicolo → autocomplete (targa, marca, descrizione) come i movimenti;
    // alla selezione carica gli ultimi km registrati del veicolo.
    const vehiclePicker = new SearchableSelect(vehicleSelEl, {
        placeholder: "Cerca veicolo per targa o nome…",
        emptyText: "— Seleziona —",
        onChange: (value) => setKmLast(form, value),
    });

    // Km: ultimi registrati dal veicolo, differenza live e avviso se negativa
    form.querySelector('[name="km"]').addEventListener("input", () => updateKmDiff(form));
    setKmLast(form, row?.id_vehicle);

    // Costo orario: precarica il default globale di configurazione
    const priceHourInput = form.querySelector('[data-field="detail-price-hour"]');
    if (hourlyCost !== null) {
        priceHourInput.value = hourlyCost;
    }

    // Riga inserimento: importo / manodopera / totale calcolati live
    ["detail-qty", "detail-price", "detail-discount", "detail-vat", "detail-hours", "detail-price-hour"].forEach((f) => {
        form.querySelector(`[data-field="${f}"]`).addEventListener("input", () => updateDetailRow(form));
    });

    const isNew = !row;
    setDetailsEnabled(form, !isNew);

    const d = document.createElement("dialog");
    d.className = "fb-dialog fb-dialog-large";
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
        if (kmDiffNegative(form) && !(await dialog.confirm("I km attuali sono inferiori agli ultimi registrati. Salvare comunque?", "Attenzione"))) {
            return;
        }
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
            id_product: productPicker.value,
            quantity: form.querySelector('[data-field="detail-qty"]').value,
            price: form.querySelector('[data-field="detail-price"]').value,
            discount: form.querySelector('[data-field="detail-discount"]').value,
            vat_rate: form.querySelector('[data-field="detail-vat"]').value,
            hours: form.querySelector('[data-field="detail-hours"]').value,
            price_per_hour: form.querySelector('[data-field="detail-price-hour"]').value,
        };
        try {
            await FetchHelper.post(`${window.FB.baseUrl}api/maintenance/${idMaintenance}/details`, data);
            // Scarico locale: la giacenza del picker si aggiorna subito
            adjustStockCaches(Number(data.id_product), -Math.abs(Number(data.quantity || 0)), Number(data.price || 0));
            refreshPickerItems();
            // Il backend salva il nuovo costo orario in configurazione se diverso:
            // allinea il default locale per i prossimi inserimenti.
            if (Number(data.price_per_hour) > 0 && Number(data.price_per_hour) !== hourlyCost) {
                hourlyCost = Number(data.price_per_hour);
            }
            productPicker.clear();
            purchasesData = null;
            form.querySelector('[data-field="detail-qty"]').value = "";
            form.querySelector('[data-field="detail-price"]').value = "";
            form.querySelector('[data-field="detail-discount"]').value = "";
            form.querySelector('[data-field="detail-vat"]').value = "";
            form.querySelector('[data-field="detail-hours"]').value = "";
            form.querySelector('[data-field="detail-price-hour"]').value = hourlyCost !== null ? hourlyCost : "";
            form.querySelector('[data-field="detail-importo"]').value = "";
            form.querySelector('[data-field="detail-manpower"]').value = "";
            form.querySelector('[data-field="detail-total"]').value = "";
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
            // Rientro locale: ripristina la giacenza del picker (lotto stesso prezzo)
            const detail = lastDetailsRows.find((dd) => String(dd.id_maintenance_detail) === String(btn.dataset.id));
            if (detail) {
                adjustStockCaches(Number(detail.id_product), Math.abs(Number(detail.quantity || 0)), Number(detail.price || 0));
                refreshPickerItems();
            }
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
            total += Math.abs(t.totale);
            return `
            <tr>
                <td>${escapeHtml(d.sku || "—")}</td>
                <td>${escapeHtml(d.product_name || "—")}</td>
                <td class="num">${fmtQty(Math.abs(d.quantity || 0))}</td>
                <td class="num">${fmtMoney(d.price)}</td>
                <td class="num">${Number(d.discount || 0)}%</td>
                <td class="num">${fmtMoney(Math.abs(t.importo))}</td>
                <td class="num">${d.labor_hours !== null && d.labor_hours !== undefined ? fmtQty(Math.abs(d.labor_hours)) : "—"}</td>
                <td class="num">${d.labor_price_per_hour !== null && d.labor_price_per_hour !== undefined ? fmtMoney(d.labor_price_per_hour) : "—"}</td>
                <td class="num">${d.labor_manpower !== null && d.labor_manpower !== undefined ? fmtMoney(Math.abs(d.labor_manpower)) : "—"}</td>
                <td>${vatLabel(d)}</td>
                <td class="num">${fmtMoney(Math.abs(t.totale))}</td>
            </tr>
        `;
        })
        .join("");

    const tasks = m.tasks || [];
    const task = tasks.find((t) => Number(t.id_maintenance_detail ?? 0) === 0) || tasks[0];
    const totHours = tasks.reduce((s, t) => s + Number(t.hours || 0), 0);
    const totManpower = tasks.reduce((s, t) => s + Number(t.manpower || 0), 0);
    const lavoroHtml = task ? viewItem("Lavoro", `${task.description || "—"}`, { span: 2 }) : "";
    const laborHtml = tasks.length ? viewItem("Manodopera", `${fmtQty(totHours)} h · ${fmtMoney(totManpower)}`, { span: 2 }) : "";

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
                ${laborHtml}
                ${m.note ? viewItem("Nota", m.note, { span: 2 }) : ""}
            </dl>
            <div class="fb-details-scroll">
                <table class="fb-table fb-details-table">
                    <thead>
                        <tr>
                            <th class="num">Q.tà</th><th>Codice</th><th>Articolo</th>
                            <th class="num">Prezzo</th><th class="num">Sconto</th><th class="num">Importo</th>
                            <th class="num">Ore</th><th class="num">€/h</th><th class="num">Manodopera</th>
                            <th>I.v.a.</th><th class="num">Totale</th>
                        </tr>
                    </thead>
                    <tbody>${rowsHtml || '<tr><td colspan="11" class="fb-muted" style="text-align:center">Nessun ricambio.</td></tr>'}</tbody>
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
        const [vehicles, products, invoices, config] = await Promise.all([FetchHelper.get(`${window.FB.baseUrl}api/vehicles`), FetchHelper.get(`${window.FB.baseUrl}api/products/options`, { lots: 1 }), FetchHelper.get(`${window.FB.baseUrl}api/invoices`), FetchHelper.get(`${window.FB.baseUrl}api/settings/config`)]);
        vehiclesCache = (vehicles.rows || []).filter((v) => (v.status ?? "active") !== "retired");
        productsCache = [...(products.products || []), ...(products.alias_products || [])].sort((a, b) => String(a.label).localeCompare(String(b.label), "it"));
        lotsCache = (products.lot_products || []).sort((a, b) => String(a.label).localeCompare(String(b.label), "it"));
        invoicesCache = invoices.rows || [];
        hourlyCost = Number(config.hourly_cost) > 0 ? Number(config.hourly_cost) : null;
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
    PrintHelper.bindForceDownloadSwitch("#force-pdf-download");
    new PrintHelper({
        table: "#maintenance-table",
        button: '[data-action="print-details"]',
        counter: "#maintenance-selected-count",
        url: "admin/maintenance/print-details",
        idField: "id_maintenance",
        dialog,
    });
    loadOptions();

    // ?open=<id> — apre direttamente la scheda della manutenzione (link dalle giacenze)
    const openId = new URLSearchParams(location.search).get("open");
    if (openId) {
        FetchHelper.get(`${window.FB.baseUrl}api/maintenance/${openId}`)
            .then((res) => res?.maintenance && openMaintenanceForm(res.maintenance))
            .catch(() => {});
    }
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initPage);
} else {
    initPage();
}
