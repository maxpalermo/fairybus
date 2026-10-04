import FetchHelper from "../core/FetchHelper.js";
import DialogHelper from "../core/DialogHelper.js";
import PrintHelper from "../components/PrintHelper.js";

const dialog = new DialogHelper();

function escapeHtml(text) {
    const div = document.createElement("div");
    div.textContent = String(text ?? "");
    return div.innerHTML;
}

function numericSorter(a, b) {
    return Number(a || 0) - Number(b || 0);
}

function formatActions(value, row) {
    return `
        <button type="button" class="fb-btn fb-btn-secondary fb-btn-sm" data-action="edit-brand" data-row='${escapeHtml(JSON.stringify(row))}'>Modifica</button>
        <button type="button" class="fb-btn fb-btn-secondary fb-btn-sm" data-action="delete-brand" data-id="${row.id_brand}">Elimina</button>
    `;
}

window.brandActionsEvents = {
    "click [data-action=edit-brand]": function (ev, value, row) {
        ev.stopPropagation();
        openBrandForm(row);
    },
    "click [data-action=delete-brand]": async function (ev, value, row) {
        ev.stopPropagation();
        if (!(await dialog.confirm(`Eliminare la marca "${escapeHtml(row.name)}"?`, "Conferma"))) {
            return;
        }
        try {
            await FetchHelper.post(`${window.FB.baseUrl}api/brands/${row.id_brand}/delete`, {});
            refreshTable();
        } catch (err) {
            await dialog.error(err);
        }
    },
};

function initBrandsTable() {
    if (!window.$ || !window.$.fn.bootstrapTable) {
        setTimeout(initBrandsTable, 100);
        return;
    }

    window.$("#brands-table").bootstrapTable({
        url: window.FB.baseUrl + "api/brands",
        sidePagination: "client",
        pagination: true,
        search: false,
        filterControl: true,
        sortable: true,
        locale: "it-IT",
        columns: [
            { field: "state", checkbox: true, align: "center", valign: "middle" },
            { field: "id_brand", title: "ID", sortable: true, sorter: numericSorter, width: 60, align: "center" , filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "name", title: "Marca", sortable: true , filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "actions", title: "Azioni", formatter: formatActions, events: window.brandActionsEvents },
        ],
    });
}

function refreshTable() {
    const $table = window.$("#brands-table");
    if ($table.length && typeof $table.bootstrapTable === "function") {
        $table.bootstrapTable("refresh");
    }
}

function openBrandForm(brand = null) {
    const tpl = document.getElementById("tpl-brand-form");
    const form = tpl.content.cloneNode(true).querySelector("form");
    const title = brand ? "Modifica marca" : "Nuova marca";

    if (brand) {
        form.querySelector('[name="id_brand"]').value = brand.id_brand;
        form.querySelector('[name="name"]').value = brand.name;
    }

    const d = document.createElement("dialog");
    d.className = "fb-dialog";
    d.innerHTML = `<div class="fb-dialog-header"><h3 class="fb-dialog-title">${escapeHtml(title)}</h3></div><div class="fb-dialog-body"></div>`;
    d.querySelector(".fb-dialog-body").appendChild(form);
    document.body.appendChild(d);
    d.showModal();

    form.addEventListener("submit", async (ev) => {
        ev.preventDefault();
        const data = Object.fromEntries(new FormData(form).entries());
        const url = brand ? `${window.FB.baseUrl}api/brands/${brand.id_brand}/update` : `${window.FB.baseUrl}api/brands`;
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

function initToolbar() {
    document.querySelector('[data-action="new-brand"]')?.addEventListener("click", () => openBrandForm());
}

function initPrint() {
    new PrintHelper({
        table: "#brands-table",
        button: '[data-action="print-brands"]',
        counter: "#brands-selected-count",
        url: "admin/brands/print",
        idField: "id_brand",
        dialog,
    });
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", () => {
        initBrandsTable();
        initToolbar();
        initPrint();
    });
} else {
    initBrandsTable();
    initToolbar();
    initPrint();
}
