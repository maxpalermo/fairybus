import FetchHelper from "../core/FetchHelper.js";
import DialogHelper from "../core/DialogHelper.js";
import PrintHelper from "../components/PrintHelper.js";

const dialog = new DialogHelper();

// opzioni per la select della colonna Tipo (filter-control)
window.fbSubjectTypeOptions = {
    "": "Tutti",
    Fornitore: "Fornitore",
    Cliente: "Cliente",
    Veicolo: "Veicolo",
};
window.fbMovKindOptions = { "": "Tutti", in: "Carico", out: "Scarico" };

function initStocksTable() {
    if (!window.$ || !window.$.fn.bootstrapTable) {
        setTimeout(initStocksTable, 100);
        return;
    }

    window.$("#stocks-table").bootstrapTable({
        url: window.FB.baseUrl + "api/stocks",
        sidePagination: "client",
        pagination: true,
        search: false,
        filterControl: true,
        sortable: true,
        locale: "it-IT",
        pageSize: 15,
        rowStyle: stockRowStyle,
        onLoadSuccess: (data) => {
            stockRows = (data && data.rows) || [];
        },
        detailView: true,
        detailFormatter: (index, row) => `<div class="fb-stock-detail"><div class="fb-lots-wrap"></div><table class="fb-table stock-mov-table" data-id-product="${row.id_product}"></table></div>`,
        onExpandRow: (index, row, $detail) => {
            renderLotsCard($detail.find(".fb-lots-wrap"), row);
            $detail.find("table.stock-mov-table").bootstrapTable({
                url: `${window.FB.baseUrl}api/products/${row.id_product}/movements`,
                sidePagination: "client",
                pagination: true,
                pageSize: 10,
                search: false,
                sortable: true,
                filterControl: true,
                locale: "it-IT",
                responseHandler: (res) => res.rows || [],
                columns: movementColumns(row.unit_label),
                onClickRow: (r) => window.open(movementUrl(r), "_blank", "noopener"),
            });
        },
        columns: [
            { field: "state", checkbox: true, align: "center", valign: "middle" },
            { field: "sku", title: "Codice", sortable: true, searchable: true, formatter: formatSku, filterControl: "input", filterCustomSearch: stockSkuSearch },
            { field: "product_name", title: "Articolo", sortable: true, searchable: true, filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "unit_label", title: "um", sortable: true, searchable: true, filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "quantity", title: "Q.tà", sortable: true, align: "right", searchable: true, formatter: formatQuantity, filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "notification_limit", title: "Avvisi", sortable: true, align: "center", searchable: true, formatter: formatAlert, filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "actions", title: "Azioni", searchable: false, align: "center", formatter: formatActions, events: window.stockActionsEvents },
        ],
    });
}

/* ---------- Scheda giacenza per prezzo d'acquisto ---------- */

async function renderLotsCard($wrap, row) {
    if (!$wrap.length) {
        return;
    }
    try {
        const res = await FetchHelper.get(`${window.FB.baseUrl}api/products/${row.id_product}/lots`);
        const lots = (res && res.lots) || [];
        const um = escapeHtml(row.unit_label || "");
        const fmtQty = (v) => Number(v || 0).toLocaleString("it-IT", { maximumFractionDigits: 3 });
        const fmtPrice = (v) => `${Number(v || 0).toLocaleString("it-IT", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}\u00A0€`;

        const purchaseCard = (title, cls, p) => {
            if (!p) {
                return "";
            }
            const doc = `${escapeHtml(p.doc_number || "—")} del ${fmtDate(p.date)}`;
            const disc = Number(p.discount) ? ` · sconto ${Number(p.discount).toLocaleString("it-IT")}%` : "";
            return `
                <div class="fb-price-card fb-price-${cls}">
                    <div class="fb-pc-title">${title}</div>
                    <div class="fb-pc-price">${fmtPrice(p.price)}</div>
                    <div class="fb-pc-detail">${escapeHtml(p.supplier || "—")}</div>
                    <div class="fb-pc-detail">${doc}${disc}</div>
                </div>`;
        };
        const priceCards =
            res.last_purchase || res.best_purchase
                ? `<div class="fb-price-cards">
                    ${purchaseCard("Ultimo prezzo d'acquisto", "last", res.last_purchase)}
                    ${purchaseCard("Miglior prezzo d'acquisto", "best", res.best_purchase)}
                </div>`
                : "";

        const chips = lots.length
            ? lots
                  .map(
                      (l, i) => `
                <div class="fb-lot-chip ${Number(l.qty) < 0 ? "fb-lot-neg" : `fb-lot-${i % 4}`}" ${Number(l.qty) < 0 ? 'title="Scaricato a un prezzo mai caricato"' : ""}>
                    <span class="fb-lot-qty">${fmtQty(l.qty)} ${um}</span>
                    <span class="fb-lot-sep">×</span>
                    <span class="fb-lot-price">${fmtPrice(l.price)}</span>
                </div>`,
                  )
                  .join("")
            : '<span class="fb-muted">Nessuna giacenza</span>';

        const totalQty = lots.reduce((s, l) => s + Number(l.qty || 0), 0);
        const totalVal = lots.reduce((s, l) => s + Number(l.qty || 0) * Number(l.price || 0), 0);

        $wrap.html(`
            ${priceCards}
            <div class="fb-lots-card">
                <div class="fb-lots-head">
                    <span class="fb-lots-title">Giacenza per prezzo d'acquisto</span>
                    <span class="fb-lots-total" title="Valore totale a prezzo d'acquisto">${fmtQty(totalQty)} ${um} · ${fmtPrice(totalVal)}</span>
                </div>
                <div class="fb-lots-body">${chips}</div>
            </div>`);
    } catch (err) {
        $wrap.empty();
    }
}

/* ---------- Dettaglio movimenti ---------- */

function movementColumns(unitLabel) {
    const filter = { filterControl: "input", filterCustomSearch: window.fbFilterSearch };
    return [
        { field: "date", title: "Data", sortable: true, formatter: (v) => fmtDate(v), ...filter },
        {
            field: "kind",
            title: "Movimento",
            sortable: true,
            align: "center",
            searchFormatter: false,
            filterControl: "select",
            filterData: "var:fbMovKindOptions",
            formatter: (v) => (v === "in" ? '<span class="fb-badge fb-badge-success">Carico</span>' : '<span class="fb-badge fb-badge-danger">Scarico</span>'),
        },
        {
            field: "subject_type",
            title: "Tipo",
            sortable: true,
            align: "center",
            searchFormatter: false,
            filterControl: "select",
            filterData: "var:fbSubjectTypeOptions",
            formatter: (v) => subjectIcon(v),
        },
        { field: "subject_name", title: "Soggetto", sortable: true, formatter: (v) => escapeHtml(v || "—"), ...filter },
        {
            field: "doc_label",
            title: "Documento",
            sortable: true,
            formatter: (v, row) => {
                const label = `${escapeHtml(v || "—")}${row.date ? ` <span class="fb-muted">del ${fmtDate(row.date)}</span>` : ""}`;
                return `${docIcon(row.source)} <a href="${movementUrl(row)}" target="_blank" class="fb-doc-link" rel="noopener">${label}</a>`;
            },
            ...filter,
        },
        {
            field: "quantity",
            title: "Q.tà",
            sortable: true,
            align: "right",
            ...filter,
            formatter: (v, row) => {
                const q = Math.abs(Number(v || 0));
                const text = (row.kind === "in" ? "+" : "−") + q.toLocaleString("it-IT", { maximumFractionDigits: 3 });
                return `<span class="fb-mov-qty fb-mov-${row.kind}">${text}</span>`;
            },
        },
        { field: "unit", title: "UM", align: "center", formatter: () => escapeHtml(unitLabel || "—"), ...filter },
        {
            field: "price",
            title: "Prezzo",
            sortable: true,
            align: "right",
            ...filter,
            formatter: (v) => `${Number(v || 0).toLocaleString("it-IT", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}\u00A0€`,
        },
        {
            field: "discount",
            title: "Sconto",
            sortable: true,
            align: "right",
            ...filter,
            formatter: (v) => (Number(v) ? `${Number(v).toLocaleString("it-IT")}%` : "—"),
        },
    ];
}

/* Icona colorata per tipo soggetto (coerente col tema) */
function subjectIcon(type) {
    const user = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>';
    const truck = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 3v5h-7z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>';
    const icons = {
        Fornitore: user,
        Cliente: user,
        Veicolo: truck,
    };
    const cls = type === "Fornitore" ? "sup" : type === "Cliente" ? "cust" : "veh";
    const icon = icons[type] || icons.Veicolo;
    return `<span class="fb-subj-ico fb-subj-${cls}" title="${escapeHtml(type || "")}">${icon}</span>`;
}

/* URL della pagina sorgente del movimento (aperta in nuovo tab) */
function movementUrl(row) {
    return row.source === "maintenance" ? `${window.FB.baseUrl}admin/maintenance?open=${row.id_ref}` : `${window.FB.baseUrl}${row.kind === "in" ? "admin/documents" : "admin/unloads"}?open=${row.id_ref}`;
}

/* Icona fonte del movimento: documento / manutenzione */
function docIcon(source) {
    const doc = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="13" y2="17"/></svg>';
    const wrench = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>';
    const cls = source === "maintenance" ? "maint" : "doc";
    return `<span class="fb-doc-ico fb-doc-${cls}">${source === "maintenance" ? wrench : doc}</span>`;
}

function fmtDate(value) {
    if (!value) {
        return "—";
    }
    const d = new Date(String(value).replace(" ", "T"));
    return isNaN(d) ? escapeHtml(value) : d.toLocaleDateString("it-IT");
}

function stockRowStyle(row) {
    if (row.low_stock) {
        return { classes: "fb-stock-low" };
    }
    return {};
}

let stockRows = [];

function formatSku(value, row) {
    if (!value) {
        return "—";
    }
    if (!row.id_alias) {
        return escapeHtml(value);
    }
    return `<div>${escapeHtml(value)}</div><div class="fb-sku-root">${escapeHtml(row.alias_sku || "—")}</div>`;
}

/* Cerca per codice: se il filtro matcha un membro della famiglia
   (originale o alias), mostra TUTTE le righe della famiglia. */
function stockSkuSearch(text, value) {
    if (window.fbFilterSearch(text, value)) {
        return true;
    }
    const row = stockRows.find((r) => r.sku === value);
    if (!row) {
        return false;
    }
    const rootId = String(row.id_alias || row.id_product);
    return stockRows.some((r) => {
        const rRoot = String(r.id_alias || r.id_product);
        return rRoot === rootId && window.fbFilterSearch(text, r.sku);
    });
}

function formatQuantity(value, row) {
    const qty = Number(value || 0);
    const text = qty.toLocaleString("it-IT", { maximumFractionDigits: 3 });
    return row.low_stock ? `<strong class="fb-stock-low-qty">${text}</strong>` : text;
}

function formatAlert(value, row) {
    if (value === null || value === undefined || value === "") {
        return '<span class="fb-muted">—</span>';
    }
    const limit = Number(value).toLocaleString("it-IT", { maximumFractionDigits: 3 });
    if (row.low_stock) {
        return `<span class="fb-badge fb-badge-danger" title="Giacenza sotto la soglia di avviso">Sotto ${limit}</span>`;
    }
    return `<span class="fb-muted" title="Soglia di avviso">${limit}</span>`;
}

function formatActions(value, row) {
    return `<button type="button" class="fb-btn fb-btn-secondary fb-btn-sm" data-action="edit-stock" data-id="${row.id_stock}">Modifica</button>`;
}

window.stockActionsEvents = {
    'click [data-action="edit-stock"]': function (e, value, row) {
        openStockForm(row);
    },
};

function openStockForm(row) {
    const tpl = document.getElementById("tpl-stock-form");
    const form = tpl.content.cloneNode(true).querySelector("form");

    form.querySelector('[name="id_stock"]').value = row.id_stock;
    form.querySelector('[name="quantity"]').value = Number(row.quantity || 0);
    form.querySelector('[name="unit"]').value = String(row.unit ?? 0);
    form.querySelector('[name="notification_limit"]').value = row.notification_limit !== null && row.notification_limit !== undefined ? Number(row.notification_limit) : "";
    form.querySelector('[name="note"]').value = row.note || "";

    const productLabel = form.querySelector('[data-field="product-label"]');
    productLabel.textContent = `${row.sku ? "[" + row.sku + "] " : ""}${row.product_name || ""}`;

    const d = document.createElement("dialog");
    d.className = "fb-dialog";
    d.innerHTML = `<div class="fb-dialog-header"><h3 class="fb-dialog-title">Modifica giacenza</h3></div><div class="fb-dialog-body"></div>`;
    d.querySelector(".fb-dialog-body").appendChild(form);
    document.body.appendChild(d);
    d.showModal();

    form.addEventListener("submit", async (ev) => {
        ev.preventDefault();
        const data = Object.fromEntries(new FormData(form).entries());
        try {
            await FetchHelper.post(`${window.FB.baseUrl}api/stocks/${row.id_stock}/update`, data);
            d.close();
            refreshTable();
        } catch (err) {
            await dialog.error(err);
        }
    });

    form.querySelector('[data-action="close"]').addEventListener("click", () => d.close());
    d.addEventListener("close", () => d.remove(), { once: true });
}

function refreshTable() {
    const $table = window.$("#stocks-table");
    if ($table && typeof $table.bootstrapTable === "function") {
        $table.bootstrapTable("refresh");
    }
}

function escapeHtml(text) {
    const div = document.createElement("div");
    div.textContent = String(text ?? "");
    return div.innerHTML;
}

function initPage() {
    initStocksTable();
    new PrintHelper({
        table: "#stocks-table",
        button: '[data-action="print-stocks"]',
        counter: "#stocks-selected-count",
        url: "admin/stocks/print",
        idField: "id_stock",
        dialog,
    });
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initPage);
} else {
    initPage();
}
