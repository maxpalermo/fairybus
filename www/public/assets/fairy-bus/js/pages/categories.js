import FetchHelper from "../core/FetchHelper.js";
import DialogHelper from "../core/DialogHelper.js";
import PrintHelper from "../components/PrintHelper.js";

window.fbActiveFilterOptions = { 0: "No", 1: "Sì" };

const dialog = new DialogHelper();

let categoryOptions = [];

const ICONS = {
    edit: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.83 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>',
    trash: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>',
};
const ICON_CHECK = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
const ICON_TIMES = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';

function initCategoriesTable() {
    if (!window.$ || !window.$.fn.bootstrapTable) {
        setTimeout(initCategoriesTable, 100);
        return;
    }

    window.categoryToggleEvents = {
        "click .fb-toggle": async (e, value, row) => {
            const el = e.currentTarget;
            el.disabled = true;
            try {
                const newVal = await window.toggleTrueFalse("fb_category", "active", row.id_category);
                window.$("#categories-table").bootstrapTable("updateCellByUniqueId", {
                    id: row.id_category,
                    field: "active",
                    value: newVal,
                });
            } catch (err) {
                el.disabled = false;
                await dialog.error(err);
            }
        },
    };

    window.$("#categories-table").bootstrapTable({
        url: window.FB.baseUrl + "api/categories",
        sidePagination: "client",
        pagination: true,
        search: false,
        searchOnEnterKey: true,
        searchTimeOut: 300,
        filterControl: true,
        sortable: true,
        locale: "it-IT",
        uniqueId: "id_category",
        columns: [
            { field: "state", checkbox: true, align: "center", valign: "middle" },
            { field: "id_category", title: "ID", sortable: true, width: 60, align: "center", filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "name", title: "Nome", sortable: true, formatter: formatCategoryName, filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "id_parent", title: "Padre", sortable: true, formatter: formatParent, filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "active", title: "Attiva", sortable: true, formatter: formatActive, searchFormatter: false, filterControl: "select", filterData: "var:fbActiveFilterOptions", events: window.categoryToggleEvents },
            { field: "position", title: "Pos.", sortable: true, width: 60, align: "center", filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "actions", title: "Azioni", searchable: false, formatter: formatActions, events: window.categoryActionsEvents },
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
    const on = Number(value) === 1;
    return `<button type="button" class="fb-toggle ${on ? "fb-toggle-on" : "fb-toggle-off"}" title="${on ? "Attiva" : "Disattiva"} — clicca per cambiare">${on ? ICON_CHECK : ICON_TIMES}</button>`;
}

function formatActions(value, row) {
    const isRoot = Number(row.id_category) === 1;
    if (isRoot) {
        return '<span class="fb-muted">Root</span>';
    }

    return `
        <div class="fb-btn-group">
            <button type="button" class="fb-btn-icon edit" data-action="edit-category" title="Modifica">${ICONS.edit}</button>
            <button type="button" class="fb-btn-icon fb-btn-icon-danger delete" data-action="delete-category" title="Elimina">${ICONS.trash}</button>
        </div>
    `;
}

window.categoryActionsEvents = {
    'click [data-action="edit-category"]': function (e, value, row) {
        openCategoryForm(row);
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
    // i filtri colonna si applicano solo con Invio o col bottone Cerca
    window.$("#categories-table").on("post-header.bs.table", () => window.fbBindSearchHints(document.getElementById("categories-table")));
    document.querySelector('[data-action="run-filters"]')?.addEventListener("click", () => window.triggerColumnSearch(document.getElementById("categories-table")));
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
