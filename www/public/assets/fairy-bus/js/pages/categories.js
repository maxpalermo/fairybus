import FetchHelper from "../core/FetchHelper.js";
import DialogHelper from "../core/DialogHelper.js";
import PrintHelper from "../components/PrintHelper.js";

window.fbActiveFilterOptions = { "0": "No", "1": "Sì" };

const dialog = new DialogHelper();

let categoryOptions = [];

function initCategoriesTable() {
    if (!window.$ || !window.$.fn.bootstrapTable) {
        setTimeout(initCategoriesTable, 100);
        return;
    }

    window.$("#categories-table").bootstrapTable({
        url: window.FB.baseUrl + "api/categories",
        sidePagination: "client",
        pagination: true,
        search: false,
        filterControl: true,
        sortable: true,
        locale: "it-IT",
        onLoadSuccess: function (res) {
            // Table data loaded.
        },
        columns: [
            { field: "state", checkbox: true, align: "center", valign: "middle" },
            { field: "id_category", title: "ID", sortable: true, width: 60, align: "center" , filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "name", title: "Nome", sortable: true, formatter: formatCategoryName , filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "id_parent", title: "Padre", sortable: true, formatter: formatParent , filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "active", title: "Attiva", sortable: true, formatter: formatActive , searchFormatter: false, filterControl: "select", filterData: "var:fbActiveFilterOptions" },
            { field: "position", title: "Pos.", sortable: true, width: 60, align: "center" , filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "actions", title: "Azioni", formatter: formatActions, events: window.categoryActionsEvents },
        ],
    });
}

function formatCategoryName(value, row) {
    const depth = parseInt(row.level_depth, 10) || 0;
    const indent = depth > 0 ? "&nbsp;".repeat(depth * 4) + "└&nbsp;" : "";
    return `${indent}${escapeHtml(value)}`;
}

function formatParent(value) {
    const option = categoryOptions.find((o) => o.id_category === Number(value));
    return option ? escapeHtml(option.name) : "—";
}

function formatActive(value) {
    return Number(value) === 1 ? '<span class="fb-badge fb-badge-success">Sì</span>' : '<span class="fb-badge fb-badge-danger">No</span>';
}

function formatActions(value, row) {
    const isRoot = Number(row.id_category) === 1;
    if (isRoot) {
        return '<span class="fb-muted">Root</span>';
    }

    return `
        <button type="button" class="fb-btn fb-btn-secondary fb-btn-sm" data-action="edit-category" data-id="${row.id_category}">Modifica</button>
        <button type="button" class="fb-btn fb-btn-secondary fb-btn-sm" data-action="toggle-active-category" data-id="${row.id_category}" data-active="${row.active}">${Number(row.active) === 1 ? "Disattiva" : "Attiva"}</button>
        <button type="button" class="fb-btn fb-btn-secondary fb-btn-sm" data-action="delete-category" data-id="${row.id_category}">Elimina</button>
    `;
}

window.categoryActionsEvents = {
    'click [data-action="edit-category"]': function (e, value, row) {
        openCategoryForm(row);
    },
    'click [data-action="toggle-active-category"]': function (e, value, row) {
        toggleCategoryActive(row.id_category);
    },
    'click [data-action="delete-category"]': function (e, value, row) {
        deleteCategory(row.id_category);
    },
};

async function loadCategoryOptions() {
    try {
        const res = await FetchHelper.get(`${window.FB.baseUrl}api/categories/options`);
        categoryOptions = res.options || [];
    } catch (err) {
        console.error(err);
    }
}

function buildParentOptions(selectedId = 1, excludeId = null) {
    return categoryOptions
        .filter((o) => o.id_category !== Number(excludeId))
        .map((o) => `<option value="${o.id_category}" ${Number(o.id_category) === Number(selectedId) ? "selected" : ""}>${escapeHtml(o.name)}</option>`)
        .join("");
}

function openCategoryForm(category = null) {
    const tpl = document.getElementById("tpl-category-form");
    const form = tpl.content.cloneNode(true).querySelector("form");
    const isEdit = !!category;
    const title = isEdit ? "Modifica categoria" : "Nuova categoria";

    if (isEdit) {
        form.querySelector('[name="id_category"]').value = category.id_category;
        form.querySelector('[name="name"]').value = category.name || "";
        form.querySelector('[name="description"]').value = category.description || "";
        form.querySelector('[name="active"]').checked = Number(category.active) === 1;
    }

    const parentSelect = form.querySelector('[name="id_parent"]');
    parentSelect.innerHTML = buildParentOptions(isEdit ? category.id_parent : 1, isEdit ? category.id_category : null);

    const d = document.createElement("dialog");
    d.className = "fb-dialog fb-dialog-sm";
    d.innerHTML = `<div class="fb-dialog-header"><h3 class="fb-dialog-title">${escapeHtml(title)}</h3></div><div class="fb-dialog-body"></div>`;
    d.querySelector(".fb-dialog-body").appendChild(form);
    document.body.appendChild(d);
    d.showModal();

    form.addEventListener("submit", async (ev) => {
        ev.preventDefault();
        const formData = new FormData(form);
        const data = Object.fromEntries(formData.entries());
        data.active = form.querySelector('[name="active"]').checked ? 1 : 0;
        const url = isEdit ? `${window.FB.baseUrl}api/categories/${category.id_category}/update` : `${window.FB.baseUrl}api/categories`;

        try {
            await FetchHelper.post(url, data);
            d.close();
            await loadCategoryOptions();
            refreshTable();
        } catch (err) {
            await dialog.error(err);
        }
    });

    form.querySelector('[data-action="close"]').addEventListener("click", () => d.close());
    d.addEventListener("close", () => d.remove(), { once: true });
}

async function toggleCategoryActive(id) {
    try {
        await FetchHelper.post(`${window.FB.baseUrl}api/categories/${id}/toggle-active`, {});
        refreshTable();
    } catch (err) {
        await dialog.error(err);
    }
}

async function deleteCategory(id) {
    const ok = await dialog.confirm("Eliminare questa categoria?", "Conferma");
    if (!ok) return;

    try {
        await FetchHelper.post(`${window.FB.baseUrl}api/categories/${id}/delete`, {});
        await loadCategoryOptions();
        refreshTable();
    } catch (err) {
        await dialog.error(err);
    }
}

function refreshTable() {
    const $table = window.$("#categories-table");
    if ($table && typeof $table.bootstrapTable === "function") {
        $table.bootstrapTable("refresh");
    }
}

function escapeHtml(text) {
    const div = document.createElement("div");
    div.textContent = String(text ?? "");
    return div.innerHTML;
}

async function initPage() {
    await loadCategoryOptions();
    initCategoriesTable();
    document.querySelector('[data-action="new-category"]')?.addEventListener("click", () => openCategoryForm());
    new PrintHelper({
        table: "#categories-table",
        button: '[data-action="print-categories"]',
        counter: "#categories-selected-count",
        url: "admin/categories/print",
        idField: "id_category",
        dialog,
    });
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initPage);
} else {
    initPage();
}
