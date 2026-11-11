import FetchHelper from "../core/FetchHelper.js";
import DialogHelper from "../core/DialogHelper.js";
import Toast from "../core/Toast.js";
import PrintHelper from "../components/PrintHelper.js";

window.fbFeatureTypeFilterOptions = { switch: "Interruttore", select: "Select", value: "Valore" };

const dialog = new DialogHelper();
const toast = new Toast();
const BASE = window.FB.baseUrl;

const ICONS = {
    edit: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.83 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>',
    trash: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>',
};

function escapeHtml(text) {
    const div = document.createElement("div");
    div.textContent = String(text ?? "");
    return div.innerHTML;
}

function initFeaturesTable() {
    if (!window.$ || !window.$.fn.bootstrapTable) {
        setTimeout(initFeaturesTable, 100);
        return;
    }

    window.featuresActionsEvents = {
        "click .edit": (e, value, row) => openFeatureForm(row),
        "click .delete": async (e, value, row) => {
            const ok = await dialog.confirm(`Eliminare la caratteristica <strong>${escapeHtml(row.label || row.name)}</strong>?`, "Elimina");
            if (!ok) return;
            try {
                await FetchHelper.post(`${BASE}api/features/${row.id_feature}/delete`, {});
                refreshTable();
                toast.showToastSuccess("Caratteristica eliminata.");
            } catch (err) {
                await dialog.error(err);
            }
        },
    };

    window.$("#features-table").bootstrapTable({
        url: BASE + "api/features",
        sidePagination: "client",
        pagination: true,
        search: false,
        searchOnEnterKey: true,
        searchTimeOut: 300,
        filterControl: true,
        sortable: true,
        locale: "it-IT",
        uniqueId: "id_feature",
        columns: [
            { field: "state", checkbox: true, align: "center", valign: "middle" },
            { field: "id_feature", title: "#", sortable: true, width: 60, filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "name", title: "Nome", sortable: true, filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "label", title: "Etichetta", sortable: true, filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "type", title: "Tipo", sortable: true, formatter: formatType, searchFormatter: false, filterControl: "select", filterData: "var:fbFeatureTypeFilterOptions" },
            {
                field: "actions",
                title: "Azioni",
                searchable: false,
                formatter: () => `
                    <div class="fb-btn-group">
                        <button type="button" class="fb-btn-icon edit" title="Modifica">${ICONS.edit}</button>
                        <button type="button" class="fb-btn-icon fb-btn-icon-danger delete" title="Elimina">${ICONS.trash}</button>
                    </div>`,
                events: window.featuresActionsEvents,
            },
        ],
    });
}

function refreshTable() {
    const $t = window.$("#features-table");
    if ($t.length && typeof $t.bootstrapTable === "function") {
        $t.bootstrapTable("refresh");
    }
}

function formatType(value) {
    const labels = {
        switch: "Interruttore",
        select: "Select",
        value: "Valore",
    };
    return `<span class="fb-badge">${escapeHtml(labels[value] ?? value)}</span>`;
}

function openFeatureForm(row = null) {
    const tpl = document.getElementById("tpl-feature-form");
    const form = tpl.content.cloneNode(true).querySelector("form");
    const isEdit = !!row;

    if (isEdit) {
        form.id_feature.value = row.id_feature;
        form.name.value = row.name || "";
        form.label.value = row.label || "";
        form.type.value = row.type || "switch";
    }

    const d = document.createElement("dialog");
    d.className = "fb-dialog fb-dialog-sm";
    d.innerHTML = `<div class="fb-dialog-header"><h3 class="fb-dialog-title">${escapeHtml(isEdit ? "Modifica caratteristica" : "Nuova caratteristica")}</h3></div><div class="fb-dialog-body"></div>`;
    d.querySelector(".fb-dialog-body").appendChild(form);
    document.body.appendChild(d);
    d.showModal();
    d.addEventListener("close", () => d.remove(), { once: true });
    form.querySelector('[data-action="close"]').addEventListener("click", () => d.close());

    form.addEventListener("submit", async (e) => {
        e.preventDefault();
        const data = Object.fromEntries(new FormData(form).entries());
        const url = isEdit ? `${BASE}api/features/${row.id_feature}/update` : `${BASE}api/features`;
        try {
            await FetchHelper.post(url, data);
            d.close();
            refreshTable();
            toast.showToastSuccess(isEdit ? "Caratteristica aggiornata." : "Caratteristica creata.");
        } catch (err) {
            await dialog.error(err);
        }
    });
}

async function loadConfigurations() {
    const container = document.getElementById("features-config-forms");
    if (!container) {
        return;
    }

    try {
        const res = await FetchHelper.get(`${BASE}api/features/configurations`);
        container.innerHTML = "";

        const labels = {
            engine_type: "Tipo motore",
            fuel_type: "Tipo alimentazione",
            euro: "Euro",
        };

        Object.entries(res.configurations).forEach(([name, values]) => {
            const wrapper = document.createElement("div");
            wrapper.className = "fb-config-item";
            wrapper.innerHTML = `
                <label class="fb-form-label">${escapeHtml(labels[name] ?? name)}</label>
                <textarea name="${escapeHtml(name)}" class="fb-form-input" rows="6">${escapeHtml(JSON.stringify(values, null, 2))}</textarea>
                <p class="fb-config-help">Array JSON con oggetti {"value": "...", "label": "..."}</p>
                <button type="button" class="fb-btn fb-btn-primary fb-btn-sm" data-action="save-config" data-name="${escapeHtml(name)}">Salva</button>
            `;
            container.appendChild(wrapper);
        });

        container.querySelectorAll('[data-action="save-config"]').forEach((btn) => {
            btn.addEventListener("click", async () => {
                const name = btn.dataset.name;
                const value = container.querySelector(`[name="${CSS.escape(name)}"]`).value;
                try {
                    await FetchHelper.post(`${BASE}api/features/configurations/${encodeURIComponent(name)}`, { value });
                    await dialog.alert("Configurazione salvata.", "OK");
                } catch (err) {
                    await dialog.error(err);
                }
            });
        });
    } catch (err) {
        container.innerHTML = `<p class="fb-error">Errore caricamento: ${escapeHtml(err.message)}</p>`;
    }
}

function initPage() {
    initFeaturesTable();
    loadConfigurations();
    // i filtri colonna si applicano solo con Invio o col bottone Cerca
    window.$("#features-table").on("post-header.bs.table", () => window.fbBindSearchHints(document.getElementById("features-table")));
    document.querySelector('[data-action="run-filters"]')?.addEventListener("click", () => window.triggerColumnSearch(document.getElementById("features-table")));
    document.querySelector('[data-action="new-feature"]')?.addEventListener("click", () => openFeatureForm());
    new PrintHelper({
        table: "#features-table",
        button: '[data-action="print-features"]',
        counter: "#features-selected-count",
        url: "admin/features/print",
        idField: "id_feature",
        dialog,
    });
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initPage);
} else {
    initPage();
}
