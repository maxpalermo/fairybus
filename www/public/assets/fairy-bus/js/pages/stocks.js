import FetchHelper from "../core/FetchHelper.js";
import DialogHelper from "../core/DialogHelper.js";
import PrintHelper from "../components/PrintHelper.js";

const dialog = new DialogHelper();

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
        columns: [
            { field: "state", checkbox: true, align: "center", valign: "middle" },
            { field: "sku", title: "Codice", sortable: true, searchable: true, formatter: formatSku , filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "product_name", title: "Articolo", sortable: true, searchable: true , filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "unit_label", title: "um", sortable: true, searchable: true , filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "quantity", title: "Q.tà", sortable: true, align: "right", searchable: true, formatter: formatQuantity , filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "notification_limit", title: "Avvisi", sortable: true, align: "center", searchable: true, formatter: formatAlert , filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "actions", title: "Azioni", searchable: false, align: "center", formatter: formatActions, events: window.stockActionsEvents },
        ],
    });
}

function stockRowStyle(row) {
    if (row.low_stock) {
        return { classes: "fb-stock-low" };
    }
    return {};
}

function formatSku(value) {
    return value ? escapeHtml(value) : "—";
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
