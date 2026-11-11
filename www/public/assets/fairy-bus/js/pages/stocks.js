import FetchHelper from "../core/FetchHelper.js";
import DialogHelper from "../core/DialogHelper.js";
import PrintHelper from "../components/PrintHelper.js";

const dialog = new DialogHelper();

const ICONS = {
    edit: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.83 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5z"></path></svg>',
    align: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>',
    check: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>',
    trash: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>',
};

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
        searchOnEnterKey: true,
        searchTimeOut: 300,
        sortable: true,
        locale: "it-IT",
        rowStyle: stockRowStyle,
        onLoadSuccess: (data) => {
            stockRows = (data && data.rows) || [];
            rebuildStockSkuIndex();
        },
        detailView: true,
        detailFormatter: (index, row) => `<div class="fb-stock-detail"><div class="fb-lots-wrap"></div><table class="fb-table stock-mov-table" data-id-product="${row.id_product}"></table></div>`,
        onExpandRow: (index, row, $detail) => {
            renderLotsCard($detail.find(".fb-lots-wrap"), row);
            $detail.find("table.stock-mov-table").bootstrapTable({
                url: `${window.FB.baseUrl}api/products/${row.id_product}/movements`,
                sidePagination: "client",
                pagination: true,
                search: false,
                sortable: true,
                filterControl: true,
                searchOnEnterKey: true,
                searchTimeOut: 300,
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
            const disc = Number(p.discount) ? ` · sconto ${Math.abs(Number(p.discount)).toLocaleString("it-IT")}%` : "";
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
            formatter: (v) => (Number(v) ? `${Math.abs(Number(v)).toLocaleString("it-IT")}%` : "—"),
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

function fmtQtyN(value) {
    return Number(value || 0).toLocaleString("it-IT", { maximumFractionDigits: 3 });
}

function fmtPriceN(value) {
    return `${Number(value || 0).toLocaleString("it-IT", { minimumFractionDigits: 2, maximumFractionDigits: 4 })}\u00A0€`;
}

/* ---------- Riallineo scarichi ai carichi ---------- */

/**
 * Dialog: a sinistra tabella carichi (radio + filtri + detail view con gli
 * scarichi dello stesso prezzo), a destra tabella scarichi anomali con
 * checkbox. Anteprima mostra il residuo con movimenti vecchi+nuovi;
 * Conferma riscrive il prezzo dei movimenti selezionati.
 */
function openRealignDialog(productRow) {
    const d = document.createElement("dialog");
    d.className = "fb-dialog fb-dialog-wide";
    d.innerHTML = `
        <div class="fb-dialog-header"><h3 class="fb-dialog-title">Riallinea scarichi ai carichi</h3></div>
        <div class="fb-dialog-body">
            <div class="fb-realign-product">
                <div class="fb-stock-product" data-field="product-label"></div>
            </div>
            <div class="fb-realign-grid" data-field="grid" hidden>
                <div class="fb-realign-panel">
                    <div class="fb-realign-title">Carichi</div>
                    <table class="fb-table" data-field="loads-table"></table>
                </div>
                <div class="fb-realign-panel">
                    <div class="fb-realign-title">Scarichi anomali <span class="fb-rl-sel-count" data-field="outs-sel-count" hidden></span></div>
                    <table class="fb-table" data-field="outs-table"></table>
                </div>
            </div>
            <div class="fb-realign-preview" data-field="preview" hidden></div>
            <div class="fb-form-actions fb-realign-foot" data-field="foot" hidden>
                <button type="button" class="fb-btn fb-btn-secondary" data-action="preview" disabled>Anteprima</button>
                <button type="button" class="fb-btn fb-btn-primary" data-action="apply" disabled>Conferma associazione</button>
                <button type="button" class="fb-btn fb-btn-secondary" data-action="close">Chiudi</button>
            </div>
        </div>`;
    document.body.appendChild(d);
    d.showModal();

    const state = { productId: null, loads: [], assoc: [], unassoc: [] };
    const $loads = () => window.$(d).find('[data-field="loads-table"]');
    const $outs = () => window.$(d).find('[data-field="outs-table"]');

    d.querySelector('[data-field="product-label"]').textContent = `${productRow && productRow.sku ? "[" + productRow.sku + "] " : ""}${(productRow && productRow.product_name) || ""}`;

    const selLoad = () => ($loads().bootstrapTable("getSelections") || [])[0] || null;
    const selOuts = () => $outs().bootstrapTable("getSelections") || [];

    const hidePreview = () => {
        d.querySelector('[data-field="preview"]').hidden = true;
    };
    const updateButtons = () => {
        hidePreview();
        const outs = selOuts();
        const badge = d.querySelector('[data-field="outs-sel-count"]');
        const qty = outs.reduce((s, o) => s + Number(o.qty || 0), 0);
        badge.hidden = outs.length === 0;
        badge.textContent = outs.length ? `${outs.length} selezionat${outs.length === 1 ? "o" : "i"} · ${fmtQtyN(qty)}` : "";
        const ready = selLoad() && outs.length > 0;
        d.querySelector('[data-action="preview"]').disabled = !ready;
        d.querySelector('[data-action="apply"]').disabled = !ready;
    };

    /* Scarichi agganciati a un carico (id_load), per detail view e anteprima */
    const outsLinkedTo = (load) => state.assoc.filter((o) => Number(o.id_load) === Number(load.id_document_detail)).sort((a, b) => String(a.date).localeCompare(String(b.date)));

    // Link diretto alla scheda/documento sorgente dello scarico: apre la
    // pagina giusta con ?open=<id> (come i link della tabella movimenti).
    const outDocUrl = (o) => (o.source === "maintenance" ? `${window.FB.baseUrl}admin/maintenance?open=${o.ref_id}` : `${window.FB.baseUrl}admin/unloads?open=${o.ref_id}`);
    const docCell = (o) => `<a href="${outDocUrl(o)}" target="_blank" class="fb-doc-link" rel="noopener">${escapeHtml(o.doc_label || "—")}</a>`;

    const movRow = (o, extra) => `<tr>
        <td>${docCell(o)}</td>
        <td>${escapeHtml(o.subject || "—")}</td>
        <td>${fmtDate(o.date)}</td>
        <td class="num">${fmtQtyN(o.qty)}</td>
        <td class="num">${fmtPriceN(o.price)}</td>
        ${extra || ""}
    </tr>`;

    function initLoadsTable() {
        $loads().bootstrapTable({
            data: state.loads,
            sidePagination: "client",
            pagination: true,
            search: false,
            filterControl: true,
            searchOnEnterKey: true,
            searchTimeOut: 300,
            sortable: true,
            locale: "it-IT",
            detailView: true,
            detailFormatter: (index, row) => {
                const rows = outsLinkedTo(row)
                    .map(
                        (o) => `<tr>
                            <td class="num"><input type="checkbox" class="fb-rl-detach-cb" data-source="${o.source}" data-id="${o.id_row}"></td>
                            <td>${docCell(o)}</td>
                            <td>${escapeHtml(o.subject || "—")}</td>
                            <td>${fmtDate(o.date)}</td>
                            <td class="num">${fmtQtyN(o.qty)}</td>
                            <td class="num">${fmtPriceN(o.price)}</td>
                        </tr>`,
                    )
                    .join("");
                return `<div class="fb-rl-detail"><table class="fb-table"><thead><tr><th style="width:2rem"></th><th>Movimento</th><th>Targa</th><th>Data</th><th class="num">Q.tà</th><th class="num">Prezzo</th></tr></thead><tbody>${rows || '<tr><td colspan="6" class="fb-muted" style="text-align:center">Nessuno scarico agganciato.</td></tr>'}</tbody></table>
                    <div class="fb-rl-detail-foot"><button type="button" class="fb-btn fb-btn-warning fb-btn-sm" data-action="detach-outs" disabled>Sgancia selezionati (prezzo 0,00 €)</button></div></div>`;
            },
            onCheck: updateButtons,
            onUncheck: updateButtons,
            columns: [
                { field: "state", radio: true, align: "center", valign: "middle" },
                { field: "id_document_detail", title: "ID", sortable: true, align: "center", width: 70, filterControl: "input", filterCustomSearch: window.fbFilterSearch },
                { field: "date", title: "Data", sortable: true, formatter: (v) => fmtDate(v), filterControl: "input", filterCustomSearch: window.fbFilterSearch },
                { field: "doc_number", title: "Numero", sortable: true, formatter: (v) => escapeHtml(v || "—"), filterControl: "input", filterCustomSearch: window.fbFilterSearch },
                { field: "supplier_name", title: "Fornitore", sortable: true, formatter: (v) => escapeHtml(v || "—"), filterControl: "input", filterCustomSearch: window.fbFilterSearch },
                { field: "qty", title: "Q.tà", sortable: true, align: "right", formatter: (v) => fmtQtyN(v), filterControl: "input", filterCustomSearch: window.fbFilterSearch },
                { field: "price", title: "Prezzo", sortable: true, align: "right", formatter: (v) => fmtPriceN(v), filterControl: "input", filterCustomSearch: window.fbFilterSearch },
                {
                    field: "residuo",
                    title: "Residuo",
                    sortable: true,
                    align: "right",
                    formatter: (v) => `<span class="fb-res-badge ${Number(v) <= 0.0001 ? "fb-res-zero" : "fb-res-pos"}">${fmtQtyN(v)}</span>`,
                    filterControl: "input",
                    filterCustomSearch: window.fbFilterSearch,
                },
            ],
        });
    }

    function initOutsTable() {
        $outs().bootstrapTable({
            data: state.unassoc,
            sidePagination: "client",
            pagination: true,
            search: false,
            filterControl: true,
            searchOnEnterKey: true,
            searchTimeOut: 300,
            sortable: true,
            locale: "it-IT",
            onCheck: updateButtons,
            onUncheck: updateButtons,
            onCheckAll: updateButtons,
            onUncheckAll: updateButtons,
            columns: [
                { field: "state", checkbox: true, align: "center", valign: "middle" },
                { field: "ref_id", title: "ID Mov.", sortable: true, align: "center", width: 64, formatter: (v) => `#${v}`, filterControl: "input", filterCustomSearch: window.fbFilterSearch },
                { field: "subject", title: "Targa", sortable: true, formatter: (v) => escapeHtml(v || "—"), filterControl: "input", filterCustomSearch: window.fbFilterSearch },
                { field: "date", title: "Data", sortable: true, formatter: (v) => fmtDate(v), filterControl: "input", filterCustomSearch: window.fbFilterSearch },
                {
                    field: "qty",
                    title: "Q.tà",
                    sortable: true,
                    align: "center",
                    width: 132,
                    formatter: (v, row) => `<div class="fb-qty-edit"><input type="number" class="fb-qty-input" step="0.001" min="0.001" value="${Number(v || 0)}" data-source="${row.source}" data-id="${row.id_row}"><button type="button" class="fb-btn-icon fb-btn-icon-success" title="Aggiorna quantità" data-action="out-qty" data-source="${row.source}" data-id="${row.id_row}">${ICONS.check}</button></div>`,
                    filterControl: "input",
                    filterCustomSearch: window.fbFilterSearch,
                },
                { field: "price", title: "Prezzo", sortable: true, align: "right", formatter: (v) => fmtPriceN(v), filterControl: "input", filterCustomSearch: window.fbFilterSearch },
                {
                    field: "ops",
                    title: "",
                    align: "center",
                    width: 36,
                    formatter: (v, row) => `<button type="button" class="fb-btn-icon fb-btn-icon-danger" title="Elimina movimento" data-action="out-delete" data-source="${row.source}" data-id="${row.id_row}">${ICONS.trash}</button>`,
                },
            ],
        });
    }

    const renderPreview = () => {
        const load = selLoad();
        const outs = selOuts();
        if (!load || outs.length === 0) {
            return;
        }
        const oldRows = outsLinkedTo(load).filter((o) => Number(o.qty) > 0);
        const selQty = outs.reduce((s, o) => s + Number(o.qty || 0), 0);
        const newRes = Number(load.residuo) - selQty;
        const movTable = (title, rows, extraCls) => `
            <div class="fb-rl-preview-block">
                <div class="fb-rl-preview-sub">${title} (${rows.length})</div>
                <table class="fb-table"><thead><tr><th>Movimento</th><th>Targa</th><th>Data</th><th class="num">Q.tà</th><th class="num">Prezzo</th></tr></thead>
                <tbody>${rows.map((o) => movRow(o, "")).join("") || '<tr><td colspan="5" class="fb-muted" style="text-align:center">Nessun movimento.</td></tr>'}</tbody></table>
            </div>`;
        d.querySelector('[data-field="preview"]').innerHTML = `
            <div class="fb-rl-preview-head">
                Carico <strong>#${load.id_document_detail}</strong> · ${escapeHtml(load.doc_number || "doc")} del ${fmtDate(load.date)}
                · ${fmtQtyN(load.qty)} × ${fmtPriceN(load.price)} · residuo attuale <strong>${fmtQtyN(load.residuo)}</strong>
            </div>
            <div class="fb-rl-preview-cols">
                ${movTable("Movimenti già associati", oldRows)}
                ${movTable("Movimenti selezionati", outs)}
            </div>
            <div class="fb-rl-preview-res">Residuo dopo l'associazione: <strong class="${newRes < 0 ? "fb-out-anomaly" : ""}">${fmtQtyN(newRes)}</strong>${newRes < 0 ? ' <span class="fb-out-anomaly">(andrebbe in negativo)</span>' : ""}</div>`;
        d.querySelector('[data-field="preview"]').hidden = false;
        d.querySelector('[data-field="preview"]').dataset.neg = newRes < 0 ? "1" : "";
    };

    const renderTables = () => {
        if (typeof $loads().bootstrapTable === "function" && $loads().data("bootstrap.table")) {
            $loads().bootstrapTable("destroy");
            $outs().bootstrapTable("destroy");
        }
        initLoadsTable();
        initOutsTable();
        d.querySelector('[data-field="grid"]').hidden = false;
        d.querySelector('[data-field="foot"]').hidden = false;
        hidePreview();
        updateButtons();
    };

    const loadProduct = async (idProduct) => {
        state.productId = Number(idProduct);
        try {
            const data = await FetchHelper.get(`${window.FB.baseUrl}api/products/${idProduct}/lots-detail`);
            state.loads = data.loads || [];
            state.assoc = data.outs_associated || [];
            state.unassoc = data.outs_unassociated || [];
            renderTables();
        } catch (err) {
            await dialog.error(err);
        }
    };

    d.querySelector('[data-action="preview"]').addEventListener("click", renderPreview);

    // checkbox scarichi nel dettaglio carico: abilitano il bottone Sgancia
    d.addEventListener("change", (ev) => {
        if (ev.target.matches(".fb-rl-detach-cb")) {
            const detail = ev.target.closest(".fb-rl-detail");
            const btn = detail && detail.querySelector('[data-action="detach-outs"]');
            if (btn) {
                btn.disabled = !detail.querySelector(".fb-rl-detach-cb:checked");
            }
        }
    });

    // sgancio / modifica qta / elimina movimento: handler delegato nel dialog
    d.addEventListener("click", async (ev) => {
        // aggiorna quantita' dello scarico anomalo
        const qtyBtn = ev.target.closest('[data-action="out-qty"]');
        if (qtyBtn) {
            const input = qtyBtn.closest(".fb-qty-edit").querySelector(".fb-qty-input");
            const qty = Number(input.value);
            if (!qty || qty <= 0) {
                await dialog.alert("Inserisci una quantità maggiore di zero.", "Attenzione");
                return;
            }
            try {
                await FetchHelper.post(`${window.FB.baseUrl}api/products/${state.productId}/out-qty`, {
                    source: qtyBtn.dataset.source,
                    id_row: qtyBtn.dataset.id,
                    qty,
                });
                refreshTable();
                await loadProduct(state.productId);
            } catch (err) {
                await dialog.error(err);
            }
            return;
        }

        // elimina il movimento
        const delBtn = ev.target.closest('[data-action="out-delete"]');
        if (delBtn) {
            if (!(await dialog.confirm("Eliminare definitivamente questo movimento? L'operazione non è annullabile.", "Attenzione"))) {
                return;
            }
            try {
                await FetchHelper.post(`${window.FB.baseUrl}api/products/${state.productId}/out-delete`, {
                    source: delBtn.dataset.source,
                    id_row: delBtn.dataset.id,
                });
                refreshTable();
                await loadProduct(state.productId);
            } catch (err) {
                await dialog.error(err);
            }
            return;
        }

        const btn = ev.target.closest('[data-action="detach-outs"]');
        if (!btn) {
            return;
        }
        const detail = btn.closest(".fb-rl-detail");
        const moves = [...detail.querySelectorAll(".fb-rl-detach-cb:checked")].map((cb) => ({
            source: cb.dataset.source,
            id_row: Number(cb.dataset.id),
        }));
        if (moves.length === 0) {
            return;
        }
        if (!(await dialog.confirm(`Sganciare ${moves.length} scarich${moves.length === 1 ? "o" : "i"}? Il prezzo diventerà 0,00 € e ${moves.length === 1 ? "risulterà" : "risulteranno"} anomalo.`, "Conferma"))) {
            return;
        }
        try {
            const res = await FetchHelper.post(`${window.FB.baseUrl}api/products/${state.productId}/realign`, {
                moves: JSON.stringify(moves),
                detach: "1",
            });
            await dialog.alert(`${res.updated} moviment${res.updated === 1 ? "o sganciato" : "i sganciati"}.`, "Fatto");
            refreshTable();
            await loadProduct(state.productId);
        } catch (err) {
            await dialog.error(err);
        }
    });

    d.querySelector('[data-action="apply"]').addEventListener("click", async () => {
        const load = selLoad();
        const outs = selOuts();
        if (!load || outs.length === 0) {
            return;
        }
        const simNeg = d.querySelector('[data-field="preview"]').dataset.neg === "1";
        if (simNeg && !(await dialog.confirm("Il carico scelto andrebbe in negativo con queste quantità. Applicare comunque?", "Attenzione"))) {
            return;
        }
        try {
            const res = await FetchHelper.post(`${window.FB.baseUrl}api/products/${state.productId}/realign`, {
                moves: JSON.stringify(outs.map((o) => ({ source: o.source, id_row: Number(o.id_row) }))),
                load_id: load.id_document_detail,
            });
            await dialog.alert(`${res.updated} moviment${res.updated === 1 ? "o riallineato" : "i riallineati"}.`, "Fatto");
            refreshTable();
            await loadProduct(state.productId);
        } catch (err) {
            await dialog.error(err);
        }
    });

    d.querySelector('[data-action="close"]').addEventListener("click", () => d.close());
    d.addEventListener("close", () => d.remove(), { once: true });

    loadProduct(productRow && productRow.id_product);
}

function stockRowStyle(row) {
    if (row.low_stock) {
        return { classes: "fb-stock-low" };
    }
    return {};
}

let stockRows = [];

// Indice sku per il filtro colonna Codice: ricostruito a ogni
// caricamento dati, evita scansioni O(n) per riga nel filterCustomSearch.
let skuIndexBySku = new Map();
let skuIndexByRoot = new Map();

function rebuildStockSkuIndex() {
    skuIndexBySku = new Map();
    skuIndexByRoot = new Map();
    stockRows.forEach((r) => {
        if (r.sku == null) return;
        if (!skuIndexBySku.has(r.sku)) skuIndexBySku.set(r.sku, r);
        const root = String(r.id_alias || r.id_product);
        const list = skuIndexByRoot.get(root);
        if (list) list.push(r.sku);
        else skuIndexByRoot.set(root, [r.sku]);
    });
}

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
    const row = skuIndexBySku.get(value);
    if (!row) {
        return false;
    }
    const rootId = String(row.id_alias || row.id_product);
    return (skuIndexByRoot.get(rootId) || []).some((sku) => window.fbFilterSearch(text, sku));
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
    return `
        <div class="fb-btn-group">
            <button type="button" class="fb-btn-icon" title="Modifica giacenza" data-action="edit-stock" data-id="${row.id_stock}">${ICONS.edit}</button>
            <button type="button" class="fb-btn-icon fb-btn-icon-info" title="Riallinea scarichi ai carichi" data-action="realign-stock" data-id="${row.id_product}">${ICONS.align}</button>
        </div>`;
}

window.stockActionsEvents = {
    'click [data-action="edit-stock"]': function (e, value, row) {
        e.stopPropagation();
        openStockForm(row);
    },
    'click [data-action="realign-stock"]': function (e, value, row) {
        e.stopPropagation();
        openRealignDialog(row);
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

/* ---------- Allinea quantità (segni movimenti + ricalcolo giacenze) ---------- */

const ALIGN_BLOCKS = 24;

function openAlignDialog() {
    const d = document.createElement("dialog");
    d.className = "fb-dialog";
    d.innerHTML = `
        <div class="fb-dialog-header"><h3 class="fb-dialog-title">Allinea quantità</h3></div>
        <div class="fb-dialog-body">
            <p class="fb-muted" style="margin:0 0 0.75rem">Corregge il segno dei movimenti (carichi +, scarichi −), aggancia gli scarichi ai carichi dello stesso prezzo e ricalcola le giacenze dai movimenti registrati.</p>
            <div class="fb-align-steps">
                <div class="fb-align-step" data-step="signs"><span class="fb-align-dot"></span><span class="fb-align-name">Segni movimenti</span><span class="fb-align-info" data-field="info-signs"></span></div>
                <div class="fb-align-step" data-step="links"><span class="fb-align-dot"></span><span class="fb-align-name">Aggancio scarichi ai carichi</span><span class="fb-align-info" data-field="info-links"></span></div>
                <div class="fb-align-step" data-step="stock"><span class="fb-align-dot"></span><span class="fb-align-name">Ricalcolo giacenze</span><span class="fb-align-info" data-field="info-stock"></span></div>
            </div>
            <div class="fb-align-blocks" data-field="blocks"></div>
            <div class="fb-align-label" data-field="label">In attesa…</div>
            <div class="fb-form-actions" style="margin-top:1rem">
                <button type="button" class="fb-btn fb-btn-primary" data-action="run">Avvia</button>
                <button type="button" class="fb-btn fb-btn-secondary" data-action="close">Chiudi</button>
            </div>
        </div>`;
    document.body.appendChild(d);
    d.showModal();

    const blocks = d.querySelector('[data-field="blocks"]');
    const label = d.querySelector('[data-field="label"]');
    const runBtn = d.querySelector('[data-action="run"]');
    const setStep = (name, cls) => (d.querySelector(`.fb-align-step[data-step="${name}"]`).className = `fb-align-step ${cls}`);

    const renderBlocks = (pct) => {
        const filled = Math.round(pct * ALIGN_BLOCKS);
        blocks.innerHTML = Array.from({ length: ALIGN_BLOCKS }, (_, i) => `<span class="fb-align-block${i < filled ? " filled" : ""}"></span>`).join("");
    };
    renderBlocks(0);

    runBtn.addEventListener("click", async () => {
        runBtn.disabled = true;
        try {
            // 1) normalizza i segni dei movimenti
            setStep("signs", "running");
            label.textContent = "Normalizzazione segni…";
            const s = await FetchHelper.post(`${window.FB.baseUrl}api/stocks/align`, { step: "signs" });
            setStep("signs", "done");
            d.querySelector('[data-field="info-signs"]').textContent = `${s.maintenance + s.loads + s.outs} corrett${s.maintenance + s.loads + s.outs === 1 ? "o" : "i"}`;

            const { total } = await FetchHelper.post(`${window.FB.baseUrl}api/stocks/align`, { step: "count" });
            const LIMIT = 100;

            // 2) aggancio scarichi ai carichi (id_load) a blocchi
            setStep("links", "running");
            let offset = 0;
            let done = false;
            let linked = 0;
            while (!done) {
                const r = await FetchHelper.post(`${window.FB.baseUrl}api/stocks/align`, { step: "links", offset, limit: LIMIT });
                offset = r.processed;
                done = r.done;
                linked += r.linked || 0;
                renderBlocks(total ? offset / total / 2 : 0.5);
                label.textContent = `Aggancio scarichi — prodotti ${offset} / ${total}`;
            }
            setStep("links", "done");
            d.querySelector('[data-field="info-links"]').textContent = `${linked} scarich${linked === 1 ? "o agganciato" : "i agganciati"}`;

            // 3) ricalcolo giacenze a blocchi
            setStep("stock", "running");
            offset = 0;
            done = false;
            let touched = 0;
            while (!done) {
                const r = await FetchHelper.post(`${window.FB.baseUrl}api/stocks/align`, { step: "stock", offset, limit: LIMIT });
                offset = r.processed;
                done = r.done;
                touched += r.updated + r.created;
                renderBlocks(total ? 0.5 + offset / total / 2 : 1);
                label.textContent = `Giacenze — prodotti ${offset} / ${total}`;
            }
            setStep("stock", "done");
            d.querySelector('[data-field="info-stock"]').textContent = `${touched} giacenz${touched === 1 ? "a aggiornata" : "e aggiornate"}`;
            label.textContent = "Completato.";
            renderBlocks(1);
            refreshTable();
        } catch (err) {
            setStep("signs", "");
            setStep("stock", "");
            await dialog.error(err);
        }
        runBtn.disabled = false;
    });

    d.querySelector('[data-action="close"]').addEventListener("click", () => d.close());
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
    // i filtri colonna si applicano solo con Invio o col bottone Cerca
    window.$("#stocks-table").on("post-header.bs.table", () => window.fbBindSearchHints(document.getElementById("stocks-table")));
    document.querySelector('[data-action="run-filters"]')?.addEventListener("click", () => window.triggerColumnSearch(document.getElementById("stocks-table")));
    document.querySelector('[data-action="align-quantities"]')?.addEventListener("click", openAlignDialog);
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
