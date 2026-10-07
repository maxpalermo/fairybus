import FetchHelper from "../core/FetchHelper.js";
import DialogHelper from "../core/DialogHelper.js";
import Toast from "../core/Toast.js";
import tableSelectManager from "../components/TableSelectManager.js";
import PrintHelper from "../components/PrintHelper.js";
import { viewItem } from "../components/ViewGrid.js";
import KpiGrid from "../components/KpiGrid.js";

window.fbActiveFilterOptions = { 0: "No", 1: "Sì" };

const dialog = new DialogHelper();
const toast = new Toast();
const kpis = new KpiGrid("#products-kpis", `${window.FB.baseUrl}api/products/summary`, (s) => {
    const pct = s.total > 0 ? Math.round((s.active / s.total) * 100) : 0;
    return [
        { label: "Prodotti", value: KpiGrid.fmtNum(s.total), sub: `${KpiGrid.fmtNum(s.brands)} marche`, tone: "info" },
        { label: "Attivi", value: KpiGrid.fmtNum(s.active), sub: `${pct}% del totale`, tone: "success" },
        { label: "Non attivi", value: KpiGrid.fmtNum(s.inactive), tone: "danger" },
        { label: "Codici equivalenti", value: KpiGrid.fmtNum(s.aliases), sub: `su ${KpiGrid.fmtNum(s.with_alias)} prodotti`, tone: "warning" },
        { label: "Senza categoria", value: KpiGrid.fmtNum(s.no_category), tone: "secondary" },
        {
            split: {
                left: { label: "Prodotti con avvisi attivati", value: KpiGrid.fmtNum(s.alerts) },
                right: { label: "Prodotti sottoscorta", value: KpiGrid.fmtNum(s.low_stock) },
            },
            tone: Number(s.low_stock) > 0 ? "danger" : "secondary",
            button: { label: "VEDI SOTTOSCORTA", onClick: openAlertsDialog },
        },
    ];
});

const ICONS = {
    view: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>',
    edit: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.83 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>',
    trash: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>',
    box: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.29 7 12 12 20.71 7"/><line x1="12" y1="22" x2="12" y2="12"/></svg>',
};

const ICON_CHECK = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
const ICON_TIMES = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';

function toggleIcon(value, field) {
    const on = Number(value) === 1;
    return `<button type="button" class="fb-toggle ${on ? "fb-toggle-on" : "fb-toggle-off"}" data-field="${field}" title="${on ? "Attivo" : "Disattivo"} — clicca per cambiare">${on ? ICON_CHECK : ICON_TIMES}</button>`;
}

let productOptions = [];
let brandOptions = [];
let categoryOptions = [];
let allRows = [];
let viewMode = "all"; // "all" | "originals" | "aliases"

const ALIAS_HIDDEN_FIELDS = new Set(["alias_count", "name", "category_name"]);

window.productToggleEvents = {
    "click .fb-toggle": async (e, value, row) => {
        const el = e.currentTarget;
        el.disabled = true;
        try {
            const newVal = await window.toggleTrueFalse("fb_product", "active", row.id_product);
            const $ownTable = window.$(el).closest("table");
            if ($ownTable.length) {
                $ownTable.bootstrapTable("updateCellByUniqueId", { id: row.id_product, field: "active", value: newVal });
            }
            if (!$ownTable.is("#products-table")) {
                window.$("#products-table").bootstrapTable("updateCellByUniqueId", { id: row.id_product, field: "active", value: newVal });
            }
        } catch (err) {
            el.disabled = false;
            await dialog.error(err);
        }
    },
};

window.productActionsEvents = {
    "click .view": (e, value, row) => openProductView(row),
    "click .edit": (e, value, row) => openProductForm(row),
    "click .stock": (e, value, row) => openStockForm(row),
    "click .delete": async (e, value, row) => {
        const ok = await dialog.confirm(`Eliminare il prodotto <strong>${escapeHtml(row.sku || row.name || "")}</strong>?`, "Elimina");
        if (!ok) return;
        try {
            await FetchHelper.post(`${window.FB.baseUrl}api/products/${row.id_product}/delete`, {});
            await loadOptions();
            refreshTable();
            toast.showToastSuccess("Prodotto eliminato.");
        } catch (err) {
            await dialog.error(err);
        }
    },
};

const productColumns = [
    { field: "state", checkbox: true, align: "center", valign: "middle" },
    { field: "sku", title: "SKU", sortable: true, width: 160, searchable: true, formatter: formatSku, filterControl: "input", filterCustomSearch: window.fbFilterSearch },
    { field: "alias_count", title: "Alias", sortable: true, width: 80, align: "center", searchable: true, formatter: formatAliasCount, filterControl: "input", filterCustomSearch: window.fbFilterSearch },
    { field: "name", title: "Nome", sortable: true, searchable: true, filterControl: "input", filterCustomSearch: window.fbFilterSearch },
    { field: "brand_name", title: "Marca", sortable: true, searchable: true, filterControl: "input", filterCustomSearch: window.fbFilterSearch },
    { field: "category_name", title: "Categoria", sortable: true, searchable: true, filterControl: "input", filterCustomSearch: window.fbFilterSearch },
    { field: "price", title: "Prezzo", sortable: true, align: "right", searchable: true, formatter: formatPrice, filterControl: "input", filterCustomSearch: window.fbFilterSearch },
    { field: "unit_label", title: "um", sortable: true, width: 70, align: "center", searchable: true, filterControl: "input", filterCustomSearch: window.fbFilterSearch },
    { field: "quantity", title: "Giacenza", sortable: true, align: "right", searchable: true, formatter: formatStockQty, filterControl: "input", filterCustomSearch: window.fbFilterSearch },
    { field: "notification_limit", title: "Avvisi", sortable: true, align: "center", searchable: true, formatter: formatStockAlert, filterControl: "input", filterCustomSearch: window.fbFilterSearch },
    { field: "active", title: "Attivo", sortable: true, width: 80, align: "center", searchable: true, formatter: formatActive, searchFormatter: false, filterControl: "select", filterData: "var:fbActiveFilterOptions", events: window.productToggleEvents },
    {
        field: "actions",
        title: "Azioni",
        searchable: false,
        formatter: () => `
            <div class="fb-btn-group">
                <button type="button" class="fb-btn-icon fb-btn-icon-info view" title="Anteprima">${ICONS.view}</button>
                <button type="button" class="fb-btn-icon edit" title="Modifica">${ICONS.edit}</button>
                <button type="button" class="fb-btn-icon fb-btn-icon-warning stock" title="Giacenza">${ICONS.box}</button>
                <button type="button" class="fb-btn-icon fb-btn-icon-danger delete" title="Elimina">${ICONS.trash}</button>
            </div>`,
        events: window.productActionsEvents,
    },
];

function collectAliases(data, rootId) {
    const result = [];
    const seen = new Set([String(rootId)]);
    const queue = [String(rootId)];
    while (queue.length > 0) {
        const parentId = queue.shift();
        data.forEach((row) => {
            const id = String(row.id_product);
            if (String(row.id_alias || "") === parentId && !seen.has(id)) {
                seen.add(id);
                queue.push(id);
                result.push(row);
            }
        });
    }
    return result;
}

function initAliasSubtable(row, $detail) {
    const all = allRows.length ? allRows : window.$("#products-table").bootstrapTable("getData");
    const aliases = collectAliases(all, row.id_product);
    $detail.find(".fb-alias-subtable").bootstrapTable({
        data: aliases,
        uniqueId: "id_product",
        locale: "it-IT",
        sortable: true,
        rowStyle: (r) => (r.low_stock ? { classes: "fb-stock-low" } : {}),
        columns: productColumns.map((col) => (ALIAS_HIDDEN_FIELDS.has(col.field) ? { ...col, visible: false } : { ...col })),
    });
}

function initProductsTable() {
    if (!window.$ || !window.$.fn.bootstrapTable) {
        setTimeout(initProductsTable, 100);
        return;
    }

    window.$("#products-table").bootstrapTable({
        url: window.FB.baseUrl + "api/products",
        sidePagination: "client",
        pagination: true,
        search: false,
        filterControl: true,
        sortable: true,
        locale: "it-IT",
        uniqueId: "id_product",
        pageSize: 25,
        customSearch: customAliasSearch,
        rowStyle: (row) => (row.low_stock ? { classes: "fb-stock-low" } : {}),
        detailView: true,
        icons: {
            detailOpen: "fb-detail-icon-open",
            detailClose: "fb-detail-icon-close",
        },
        detailFilter: (index, row) => !row.id_alias && Number(row.alias_count || 0) > 0,
        onLoadSuccess: (data) => {
            allRows = data && data.rows ? data.rows : [];
            if (viewMode !== "all") {
                applyViewFilter();
            }
            refreshAlertsModal();
        },
        detailFormatter: () => '<div class="fb-alias-detail"><table class="fb-alias-subtable"></table></div>',
        onExpandRow: (index, row, $detail) => initAliasSubtable(row, $detail),
        columns: productColumns,
    });
}

function customAliasSearch(data, text) {
    const term = (text || "").toLowerCase().trim();
    if (!term) {
        return data;
    }

    const idToRow = new Map();
    data.forEach((row) => idToRow.set(String(row.id_product), row));

    const matchedIds = new Set();
    data.forEach((row) => {
        const haystack = [String(row.id_product || ""), String(row.legacy_id || ""), row.sku || "", row.name || "", row.brand_name || "", row.alias_sku || "", row.alias_name || ""].join(" ").toLowerCase();
        if (haystack.includes(term)) {
            matchedIds.add(String(row.id_product));
        }
    });

    const rootIds = new Set();
    matchedIds.forEach((id) => {
        let row = idToRow.get(id);
        while (row && row.id_alias) {
            const parent = idToRow.get(String(row.id_alias));
            if (!parent || String(parent.id_product) === String(row.id_product)) {
                break;
            }
            row = parent;
        }
        if (row) {
            rootIds.add(String(row.id_product));
        }
    });

    const resultIds = new Set();
    rootIds.forEach((rootId) => {
        resultIds.add(rootId);
        const queue = [rootId];
        while (queue.length > 0) {
            const currentId = queue.shift();
            data.forEach((row) => {
                if (String(row.id_alias || "") === currentId && !resultIds.has(String(row.id_product))) {
                    resultIds.add(String(row.id_product));
                    queue.push(String(row.id_product));
                }
            });
        }
    });

    return data
        .filter((row) => resultIds.has(String(row.id_product)))
        .sort((a, b) => {
            const aId = String(a.id_product);
            const bId = String(b.id_product);
            const aIsRoot = rootIds.has(aId);
            const bIsRoot = rootIds.has(bId);
            if (aIsRoot && !bIsRoot) return -1;
            if (!aIsRoot && bIsRoot) return 1;
            return String(a.name || "").localeCompare(String(b.name || ""));
        });
}

function formatSku(value, row) {
    if (!value) {
        return "—";
    }
    if (!row.id_alias) {
        return escapeHtml(value);
    }
    return `
        <div>${escapeHtml(value)}</div>
        <div class="fb-sku-root">${escapeHtml(row.alias_sku || "—")}</div>
    `;
}

function formatAliasCount(value) {
    const count = Number(value || 0);
    return count > 0 ? `<span class="fb-badge fb-badge-info">${count}</span>` : "—";
}

function formatPrice(value) {
    return Number(value || 0).toLocaleString("it-IT", { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + "\u00A0€";
}

function formatActive(value) {
    return toggleIcon(value, "active");
}

function formatStockQty(value, row) {
    if (value === null || value === undefined || value === "") {
        return '<span class="fb-muted">—</span>';
    }
    const text = Number(value).toLocaleString("it-IT", { maximumFractionDigits: 3 });
    return row.low_stock ? `<strong class="fb-stock-low-qty">${text}</strong>` : text;
}

function formatStockAlert(value, row) {
    if (value === null || value === undefined || value === "") {
        return '<span class="fb-muted">—</span>';
    }
    const limit = Number(value).toLocaleString("it-IT", { maximumFractionDigits: 3 });
    if (row.low_stock) {
        return `<span class="fb-badge fb-badge-danger" title="Giacenza sotto la soglia di avviso">Sotto ${limit}</span>`;
    }
    return `<span class="fb-muted" title="Soglia di avviso">${limit}</span>`;
}

function openStockForm(row) {
    const tpl = document.getElementById("tpl-product-stock-form");
    const form = tpl.content.cloneNode(true).querySelector("form");

    form.querySelector('[name="id_stock"]').value = row.id_stock || "";
    form.querySelector('[name="quantity"]').value = Number(row.quantity || 0);
    form.querySelector('[name="unit"]').value = String(row.unit ?? 0);
    form.querySelector('[name="notification_limit"]').value = row.notification_limit !== null && row.notification_limit !== undefined ? Number(row.notification_limit) : "";
    form.querySelector('[name="note"]').value = row.stock_note || "";
    form.querySelector('[data-field="product-label"]').textContent = `${row.sku ? "[" + row.sku + "] " : ""}${row.name || ""}`;

    const d = buildDialog(`Giacenza — ${row.sku || row.name || ""}`);
    d.querySelector(".fb-dialog-body").appendChild(form);

    form.addEventListener("submit", async (ev) => {
        ev.preventDefault();
        const data = Object.fromEntries(new FormData(form).entries());
        try {
            await FetchHelper.post(`${window.FB.baseUrl}api/stocks/product/${row.id_product}`, data);
            d.close();
            refreshTable();
            toast.showToastSuccess("Giacenza aggiornata.");
        } catch (err) {
            await dialog.error(err);
        }
    });

    form.querySelector('[data-action="close"]').addEventListener("click", () => d.close());
}

function openProductView(row) {
    const d = buildDialog(`Prodotto — ${row.sku || ""}`);
    d.querySelector(".fb-dialog-body").innerHTML = `
        <dl class="fb-view-grid">
            ${viewItem("SKU", row.sku)}
            ${viewItem("Nome", row.name)}
            ${viewItem("Marca", row.brand_name)}
            ${viewItem("Categoria", row.category_name)}
            ${viewItem("Prezzo vendita", formatPrice(row.price))}
            ${viewItem("Prezzo acquisto", formatPrice(row.wholesale_price))}
            ${viewItem("IVA", `${Number(row.tax_rate || 0).toLocaleString("it-IT")}%`)}
            ${viewItem("Alias di", row.alias_name ? `${row.alias_sku || ""} ${row.alias_name}`.trim() : null)}
            ${viewItem("Codici equivalenti", Number(row.alias_count || 0) > 0 ? `${row.alias_count}` : null)}
            ${viewItem("Attivo", Number(row.active) === 1 ? "Sì" : "No")}
        </dl>
        <div class="fb-form-actions">
            <button type="button" class="fb-btn fb-btn-secondary" data-action="close-view">Chiudi</button>
        </div>`;
    d.querySelector('[data-action="close-view"]').addEventListener("click", () => d.close());
}

async function loadOptions() {
    try {
        const res = await FetchHelper.get(`${window.FB.baseUrl}api/products/options`);
        productOptions = res.products || [];
        brandOptions = res.brands || [];
        categoryOptions = res.categories || [];
    } catch (err) {
        console.error(err);
    }
}

function buildOptions(items, valueField, labelField, selectedId = null) {
    return items.map((item) => `<option value="${item[valueField]}" ${selectedId !== null && Number(item[valueField]) === Number(selectedId) ? "selected" : ""}>${escapeHtml(item[labelField])}</option>`).join("");
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

function findBrandName(idBrand) {
    const brand = brandOptions.find((b) => Number(b.id_brand) === Number(idBrand));
    return brand ? brand.name : "—";
}

async function openProductForm(product = null) {
    const tpl = document.getElementById("tpl-product-form");
    const form = tpl.content.cloneNode(true).querySelector("form");
    const isEdit = !!product;
    const isAlias = isEdit && product && product.id_alias;
    const productLabel = isEdit ? [product.sku ? `[${product.sku}]` : null, product.name].filter(Boolean).join(" ") : "";
    const title = isEdit ? `${isAlias ? "Modifica alias" : "Modifica prodotto"} — ${productLabel}` : "Nuovo prodotto";

    const brandSelect = form.querySelector('[name="id_brand"]');
    brandSelect.innerHTML = '<option value="">—</option>' + buildOptions(brandOptions, "id_brand", "name", isEdit ? product.id_brand : null);

    const categorySelect = form.querySelector('[name="id_category"]');
    categorySelect.innerHTML = '<option value="">—</option>' + buildOptions(categoryOptions, "id_category", "name", isEdit ? product.id_category : null);

    const aliasTab = form.querySelector("[data-tab-alias]");
    const mainSection = form.querySelector(".fb-product-form-main");

    // la tab "Codici alias" esiste solo in modifica di un prodotto radice
    const showAliasPanel = isEdit && !isAlias;
    aliasTab.style.display = showAliasPanel ? "" : "none";
    initFormTabs(form);

    if (isEdit) {
        form.querySelector('[name="id_product"]').value = product.id_product;
        form.querySelector('[name="sku"]').value = product.sku || "";
        form.querySelector('[name="name"]').value = product.name || "";
        form.querySelector('[name="price"]').value = product.price || 0;
        form.querySelector('[name="wholesale_price"]').value = product.wholesale_price || 0;
        form.querySelector('[name="tax_rate"]').value = product.tax_rate || 0;
        form.querySelector('[name="unit"]').value = String(product.unit ?? 0);
        form.querySelector('[name="active"]').checked = Number(product.active) === 1;

        if (isAlias) {
            const root = productOptions.find((p) => Number(p.id_product) === Number(product.id_alias));
            const rootLabel = root ? `${root.sku ? "[" + root.sku + "] " : ""}${root.name}` : `#${product.id_alias}`;
            const note = document.createElement("div");
            note.className = "fb-alias-note";
            note.innerHTML = `Alias di: <strong>${escapeHtml(rootLabel)}</strong>`;
            mainSection.insertBefore(note, mainSection.firstElementChild);

            categorySelect.disabled = true;
            form.querySelector('[name="name"]').readOnly = true;
        }
    }

    // Anteprima live dei prezzi comprensivi di IVA
    const preview = form.querySelector("[data-price-preview]");
    const updatePricePreview = () => {
        if (!preview) {
            return;
        }
        const vat = Number(form.querySelector('[name="tax_rate"]').value || 0);
        const buy = Number(form.querySelector('[name="wholesale_price"]').value || 0);
        const sell = Number(form.querySelector('[name="price"]').value || 0);
        const fmt = (v) => `${v.toLocaleString("it-IT", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}\u00A0€`;
        preview.innerHTML = `
            <span class="fb-pp-label">Prezzi IVA ${vat.toLocaleString("it-IT", { maximumFractionDigits: 2 })}% inclusa</span>
            <span class="fb-pp-chip fb-pp-buy"><span class="fb-pp-name">Acquisto</span> ${fmt(buy * (1 + vat / 100))}</span>
            <span class="fb-pp-chip fb-pp-sell"><span class="fb-pp-name">Vendita</span> ${fmt(sell * (1 + vat / 100))}</span>`;
    };
    ["tax_rate", "wholesale_price", "price"].forEach((name) => {
        form.querySelector(`[name="${name}"]`)?.addEventListener("input", updatePricePreview);
    });
    updatePricePreview();

    const d = document.createElement("dialog");
    if (isAlias) {
        d.className = "fb-dialog fb-dialog-medium";
        form.classList.add("fb-product-form-alias");
    } else {
        d.className = "fb-dialog fb-dialog-wide";
        form.classList.add("fb-product-form-original");
        if (isEdit) {
            await renderAliases(form, product.id_product);
        }
    }

    d.innerHTML = `<div class="fb-dialog-header"><h3 class="fb-dialog-title">${escapeHtml(title)}</h3></div><div class="fb-dialog-body"></div>`;
    d.querySelector(".fb-dialog-body").appendChild(form);
    document.body.appendChild(d);
    d.showModal();

    tableSelectManager.init(d);

    form.addEventListener("submit", async (ev) => {
        ev.preventDefault();
        const formData = new FormData(form);
        const data = Object.fromEntries(formData.entries());
        data.active = form.querySelector('[name="active"]').checked ? 1 : 0;
        data.id_category = data.id_category || "";
        data.id_brand = data.id_brand || "";

        const url = isEdit ? `${window.FB.baseUrl}api/products/${product.id_product}/update` : `${window.FB.baseUrl}api/products`;

        try {
            await FetchHelper.post(url, data);
            d.close();
            await loadOptions();
            refreshTable();
        } catch (err) {
            await dialog.error(err);
        }
    });

    form.querySelector('[data-action="close"]').addEventListener("click", () => d.close());
    d.addEventListener("close", () => d.remove(), { once: true });

    bindAliasAdd(form, isEdit ? product.id_product : null, d);
}

async function renderAliases(form, rootId) {
    const listEl = form.querySelector("[data-alias-list]");
    listEl.innerHTML = '<div class="fb-alias-loading">Caricamento...</div>';

    try {
        const res = await FetchHelper.get(`${window.FB.baseUrl}api/products/${rootId}/aliases`);
        const aliases = res.aliases || [];
        listEl.innerHTML = "";

        if (aliases.length === 0) {
            listEl.innerHTML = '<div class="fb-alias-empty">Nessun codice equivalente.</div>';
            return;
        }

        aliases.forEach((alias) => {
            const row = document.createElement("div");
            row.className = "fb-alias-row";
            row.innerHTML = `
                <span class="fb-alias-sku">${escapeHtml(alias.sku || "—")}</span>
                <span class="fb-alias-brand">${escapeHtml(findBrandName(alias.id_brand))}</span>
                <span class="fb-alias-price">${formatPrice(alias.price)}</span>
                <button type="button" class="fb-btn fb-btn-secondary fb-btn-sm" data-action="detach-alias" data-id="${alias.id_product}" title="Scollega alias">−</button>
            `;
            row.querySelector('[data-action="detach-alias"]').addEventListener("click", async () => {
                const ok = await dialog.confirm("Scollegare questo codice dal prodotto originale? Diventerà un prodotto autonomo.", "Conferma");
                if (!ok) return;
                try {
                    await FetchHelper.post(`${window.FB.baseUrl}api/products/${alias.id_product}/detach-alias`, {});
                    await renderAliases(form, rootId);
                    await loadOptions();
                    refreshTable();
                } catch (err) {
                    await dialog.error(err);
                }
            });
            listEl.appendChild(row);
        });
    } catch (err) {
        listEl.innerHTML = '<div class="fb-alias-error">Errore caricamento.</div>';
        console.error(err);
    }
}

function bindAliasAdd(form, rootId, dialogEl) {
    const btn = form.querySelector('[data-action="add-alias"]');
    if (!btn) return;

    // La select può essere già stata wrappata da TableSelect: in quel caso
    // il campo trovato è l'hidden input (valore) e le opzioni arrivano dal manager.
    const brandField = form.querySelector('[data-alias-field="id_brand"]');
    if (brandField && brandField.tagName === "SELECT") {
        brandField.innerHTML = '<option value="">Marca</option>' + buildOptions(brandOptions, "id_brand", "name");
    }
    tableSelectManager.init(dialogEl);

    btn.addEventListener("click", async () => {
        if (!rootId) {
            await dialog.alert("Salva prima il prodotto per aggiungere codici equivalenti.", "Attenzione");
            return;
        }

        const sku = form.querySelector('[data-alias-field="sku"]').value.trim();
        const idBrand = form.querySelector('[data-alias-field="id_brand"]')?.value || "";
        const price = form.querySelector('[data-alias-field="price"]').value || 0;

        if (!sku) {
            await dialog.alert("Inserisci il codice del prodotto equivalente.", "Attenzione");
            return;
        }

        try {
            await FetchHelper.post(`${window.FB.baseUrl}api/products/${rootId}/aliases`, {
                sku,
                id_brand: idBrand,
                price,
                wholesale_price: 0,
            });
            form.querySelector('[data-alias-field="sku"]').value = "";
            const bf = form.querySelector('[data-alias-field="id_brand"]');
            if (bf) {
                bf.value = "";
                bf.closest(".fb-tableselect")?._tableSelect?.clear();
            }
            form.querySelector('[data-alias-field="price"]').value = "";
            await renderAliases(form, rootId);
            await loadOptions();
            refreshTable();
        } catch (err) {
            await dialog.error(err);
        }
    });
}

function refreshTable() {
    const $table = window.$("#products-table");
    if ($table && typeof $table.bootstrapTable === "function") {
        $table.bootstrapTable("refresh");
    }
    kpis.refresh();
}

function alertRows() {
    return allRows.filter((r) => !!r.low_stock);
}

function refreshAlertsModal() {
    const $t = window.$("#fb-alerts-table");
    if ($t.length && $t.data("bootstrap.table")) {
        $t.bootstrapTable("load", alertRows());
    }
}

function openAlertsDialog() {
    const d = buildDialog("Prodotti sotto scorta", "fb-dialog-wide");
    const body = d.querySelector(".fb-dialog-body");
    body.innerHTML = `
        <table id="fb-alerts-table"></table>
        <div class="fb-form-actions">
            <span class="fb-muted" data-alerts-selected></span>
            <button type="button" class="fb-btn fb-btn-secondary" data-action="print-alerts">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                Stampa PDF
            </button>
            <button type="button" class="fb-btn fb-btn-secondary" data-action="close-alerts">Chiudi</button>
        </div>`;

    window.$("#fb-alerts-table").bootstrapTable({
        data: alertRows(),
        uniqueId: "id_product",
        locale: "it-IT",
        sortable: true,
        pagination: true,
        pageSize: 15,
        rowStyle: (r) => (r.low_stock ? { classes: "fb-stock-low" } : {}),
        columns: [
            { field: "state", checkbox: true, align: "center", valign: "middle" },
            { field: "sku", title: "SKU", sortable: true, formatter: formatSku },
            { field: "name", title: "Nome", sortable: true },
            { field: "price", title: "Prezzo", sortable: true, align: "right", formatter: formatPrice },
            { field: "unit_label", title: "um", sortable: true, align: "center", width: 70 },
            { field: "quantity", title: "Giacenza", sortable: true, align: "right", formatter: formatStockQty },
            { field: "notification_limit", title: "Avviso", sortable: true, align: "center", formatter: formatStockAlert },
            {
                field: "actions",
                title: "",
                align: "center",
                width: 60,
                formatter: () => `<button type="button" class="fb-btn-icon fb-btn-icon-warning stock" title="Regola giacenza">${ICONS.box}</button>`,
                events: { "click .stock": (e, v, row) => openStockForm(row) },
            },
        ],
    });

    const counter = body.querySelector("[data-alerts-selected]");
    window.$("#fb-alerts-table").on("check.bs.table uncheck.bs.table check-all.bs.table uncheck-all.bs.table load-success.bs.table", () => {
        const n = window.$("#fb-alerts-table").bootstrapTable("getSelections").length;
        counter.textContent = n > 0 ? `${n} selezionat${n === 1 ? "o" : "i"} — la stampa includerà solo i selezionati` : "";
    });

    body.querySelector('[data-action="close-alerts"]').addEventListener("click", () => d.close());
    body.querySelector('[data-action="print-alerts"]').addEventListener("click", printAlerts);
}

const ALERT_PRINT_CHUNK = 200;

async function printAlerts() {
    const $t = window.$("#fb-alerts-table");
    const selected = $t.length && $t.data("bootstrap.table") ? $t.bootstrapTable("getSelections") : [];
    const source = selected.length > 0 ? selected : alertRows();
    const ids = source.map((r) => Number(r.id_product)).filter((i) => i > 0);
    if (ids.length === 0) {
        await dialog.alert("Nessun prodotto con avvisi da stampare.", "Attenzione");
        return;
    }
    const chunks = [];
    for (let i = 0; i < ids.length; i += ALERT_PRINT_CHUNK) {
        chunks.push(ids.slice(i, i + ALERT_PRINT_CHUNK));
    }
    chunks.forEach((chunk, idx) => setTimeout(() => submitAlertPrint(chunk, idx + 1, chunks.length), idx * 400));
}

function submitAlertPrint(ids, part, parts) {
    const form = document.createElement("form");
    form.method = "POST";
    form.action = `${window.FB.baseUrl}admin/products/print-alerts`;
    form.target = "_blank";
    form.style.display = "none";
    for (const [name, value] of Object.entries({ ids: JSON.stringify(ids), part, parts })) {
        const input = document.createElement("input");
        input.type = "hidden";
        input.name = name;
        input.value = String(value);
        form.appendChild(input);
    }
    document.body.appendChild(form);
    form.submit();
    form.remove();
}

function applyViewFilter() {
    const rows = viewMode === "all" ? allRows : allRows.filter((r) => (viewMode === "originals" ? !r.id_alias : !!r.id_alias));
    window.$("#products-table").bootstrapTable("load", rows);
}

function initViewSwitches() {
    const swOriginals = document.getElementById("products-only-originals");
    const swAliases = document.getElementById("products-only-aliases");
    swOriginals?.addEventListener("change", () => {
        if (swOriginals.checked) swAliases.checked = false;
        viewMode = swOriginals.checked ? "originals" : "all";
        applyViewFilter();
    });
    swAliases?.addEventListener("change", () => {
        if (swAliases.checked) swOriginals.checked = false;
        viewMode = swAliases.checked ? "aliases" : "all";
        applyViewFilter();
    });
}

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

async function initPage() {
    await loadOptions();
    initProductsTable();
    initViewSwitches();
    kpis.load().catch(() => {});
    document.querySelector('[data-action="new-product"]')?.addEventListener("click", () => openProductForm());
    new PrintHelper({
        table: "#products-table",
        button: '[data-action="print-products"]',
        counter: "#products-selected-count",
        url: "admin/products/print",
        idField: "id_product",
        dialog,
    });
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initPage);
} else {
    initPage();
}
