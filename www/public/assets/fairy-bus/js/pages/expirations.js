import FetchHelper from "../core/FetchHelper.js";
import DialogHelper from "../core/DialogHelper.js";
import PrintHelper from "../components/PrintHelper.js";

window.fbKindFilterOptions = { km: "Km", date: "Data" };

const dialog = new DialogHelper();
let tagsCache = [];

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

function fmtKm(value) {
    return value ? `${Number(value).toLocaleString("it-IT")} km` : "—";
}

const ICONS = {
    edit: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.83 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5z"></path></svg>',
    trash: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>',
};

const PERIODICITY_LABELS = {
    once: "Una tantum",
    yearly: "Annuale",
    days: "Ogni N giorni",
    km: "Chilometrica",
};
window.fbPeriodicityFilterOptions = PERIODICITY_LABELS;

function kindBadge(kind) {
    return kind === "km"
        ? '<span class="fb-kind-badge fb-kind-km">Km</span>'
        : '<span class="fb-kind-badge fb-kind-date">Data</span>';
}

/* ---------- Tabella voci di scadenza ---------- */

function formatTagActions(value, row) {
    return `
        <div class="fb-btn-group">
            <button type="button" class="fb-btn-icon" title="Modifica" data-action="edit-tag" data-id="${row.id_expiration_tag}">${ICONS.edit}</button>
            <button type="button" class="fb-btn-icon fb-btn-icon-danger" title="Elimina" data-action="delete-tag" data-id="${row.id_expiration_tag}">${ICONS.trash}</button>
        </div>
    `;
}

window.tagActionsEvents = {
    "click [data-action=edit-tag]": function (ev, value, row) {
        ev.stopPropagation();
        openTagForm(row);
    },
    "click [data-action=delete-tag]": async function (ev, value, row) {
        ev.stopPropagation();
        if (!(await dialog.confirm(`Eliminare la voce "${escapeHtml(row.name)}"?`, "Conferma"))) {
            return;
        }
        try {
            await FetchHelper.post(`${window.FB.baseUrl}api/expiration-tags/${row.id_expiration_tag}/delete`, {});
            refreshTags();
        } catch (err) {
            await dialog.error(err);
        }
    },
};

function initTagsTable() {
    if (!window.$ || !window.$.fn.bootstrapTable) {
        setTimeout(initTagsTable, 100);
        return;
    }

    window.$("#expiration-tags-table").bootstrapTable({
        url: window.FB.baseUrl + "api/expiration-tags",
        sidePagination: "client",
        pagination: true,
        search: false,
        filterControl: true,
        sortable: true,
        locale: "it-IT",
        onLoadSuccess: (data) => {
            tagsCache = data.rows || data || [];
        },
        columns: [
            { field: "state", checkbox: true, align: "center", valign: "middle" },
            { field: "name", title: "Nome", sortable: true, formatter: (v) => escapeHtml(v || "—") , filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "kind", title: "Tipo", sortable: true, align: "center", formatter: (v) => kindBadge(v) , searchFormatter: false, filterControl: "select", filterData: "var:fbKindFilterOptions" },
            { field: "interval_label", title: "Intervallo", sortable: true, formatter: (v) => escapeHtml(v || "—") , filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "applies_to", title: "Applica a", formatter: (v) => escapeHtml(v || "—") , filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "actions", title: "Azioni", searchable: false, formatter: formatTagActions, events: window.tagActionsEvents },
        ],
    });
}

function refreshTags() {
    const $table = window.$("#expiration-tags-table");
    if ($table.length && typeof $table.bootstrapTable === "function") {
        $table.bootstrapTable("refresh");
    }
}

/* ---------- Tabella scadenze veicoli ---------- */

function formatExpirationActions(value, row) {
    return `
        <div class="fb-btn-group">
            <button type="button" class="fb-btn-icon" title="Modifica" data-action="edit-expiration" data-id="${row.id_expiration}">${ICONS.edit}</button>
        </div>
    `;
}

function expirationDeadline(row) {
    if (row.expires_atkm) {
        return fmtKm(row.expires_atkm);
    }
    return fmtDate(row.expiration_date);
}

window.expirationActionsEvents = {
    "click [data-action=edit-expiration]": function (ev, value, row) {
        ev.stopPropagation();
        openExpirationForm(row);
    },
};

function initExpirationsTable() {
    if (!window.$ || !window.$.fn.bootstrapTable) {
        setTimeout(initExpirationsTable, 100);
        return;
    }

    window.$("#expirations-table").bootstrapTable({
        url: window.FB.baseUrl + "api/expirations",
        sidePagination: "client",
        pagination: true,
        search: false,
        filterControl: true,
        sortable: true,
        locale: "it-IT",
        columns: [
            { field: "vehicle_plate", title: "Veicolo", sortable: true, formatter: (v) => escapeHtml(v || "—") , filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "tag_name", title: "Voce", sortable: true, formatter: (v, r) => escapeHtml(r.tag_name || r.description || "—") , filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "kind", title: "Tipo", sortable: true, align: "center", formatter: (v) => kindBadge(v) , searchFormatter: false, filterControl: "select", filterData: "var:fbKindFilterOptions" },
            { field: "expires_atkm", title: "Scadenza", sortable: true, formatter: (v, r) => expirationDeadline(r) , filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "periodicity", title: "Periodicità", sortable: true, formatter: (v) => `<span class="fb-periodicity">${PERIODICITY_LABELS[v] || escapeHtml(v || "—")}</span>` , searchFormatter: false, filterControl: "select", filterData: "var:fbPeriodicityFilterOptions" },
            { field: "actions", title: "Azioni", searchable: false, formatter: formatExpirationActions, events: window.expirationActionsEvents },
        ],
    });
}

function refreshExpirations() {
    const $table = window.$("#expirations-table");
    if ($table.length && typeof $table.bootstrapTable === "function") {
        $table.bootstrapTable("refresh");
    }
}

/* ---------- Dialog voce di scadenza ---------- */

function openTagForm(tag = null) {
    const tpl = document.getElementById("tpl-tag-form");
    const form = tpl.content.cloneNode(true).querySelector("form");

    if (tag) {
        form.querySelector('[name="name"]').value = tag.name || "";
        form.querySelector('[name="kind"]').value = tag.kind || "date";
        form.querySelector('[name="interval_value"]').value = tag.interval_value ?? "";
        form.querySelector('[name="interval_unit"]').value = tag.interval_unit || "months";
        form.querySelector('[name="note"]').value = tag.note || "";
    }

    const d = document.createElement("dialog");
    d.className = "fb-dialog";
    d.innerHTML = `<div class="fb-dialog-header"><h3 class="fb-dialog-title">${tag ? "Modifica voce" : "Nuova voce di scadenza"}</h3></div><div class="fb-dialog-body"></div>`;
    d.querySelector(".fb-dialog-body").appendChild(form);
    document.body.appendChild(d);
    d.showModal();

    // unita' coerente col tipo
    const kindSel = form.querySelector('[name="kind"]');
    const unitSel = form.querySelector('[name="interval_unit"]');
    kindSel.addEventListener("change", () => {
        unitSel.value = kindSel.value === "km" ? "km" : "months";
        form.querySelector('[data-field="unit-wrap"]').style.display = kindSel.value === "km" ? "none" : "";
    });
    form.querySelector('[data-field="unit-wrap"]').style.display = kindSel.value === "km" ? "none" : "";

    form.addEventListener("submit", async (ev) => {
        ev.preventDefault();
        const data = Object.fromEntries(new FormData(form).entries());
        const url = tag ? `${window.FB.baseUrl}api/expiration-tags/${tag.id_expiration_tag}/update` : `${window.FB.baseUrl}api/expiration-tags`;
        try {
            await FetchHelper.post(url, data);
            d.close();
            refreshTags();
        } catch (err) {
            await dialog.error(err);
        }
    });

    form.querySelector('[data-action="close"]').addEventListener("click", () => d.close());
    d.addEventListener("close", () => d.remove(), { once: true });
}

/* ---------- Dialog scadenza veicolo ---------- */

async function openExpirationForm(row) {
    if (!tagsCache || tagsCache.length === 0) {
        try {
            const res = await FetchHelper.get(`${window.FB.baseUrl}api/expiration-tags`);
            tagsCache = res.rows || [];
        } catch (err) {
            tagsCache = [];
        }
    }

    const tpl = document.getElementById("tpl-expiration-form");
    const form = tpl.content.cloneNode(true).querySelector("form");

    form.querySelector('[name="id_expiration"]').value = row.id_expiration;
    form.querySelector('[data-field="vehicle"]').value = row.vehicle_plate || "—";
    form.querySelector('[name="kind"]').value = row.kind || "date";
    form.querySelector('[name="periodicity"]').value = row.periodicity || "once";
    form.querySelector('[name="expiration_date"]').value = row.expiration_date || "";
    form.querySelector('[name="expires_atkm"]').value = row.expires_atkm ?? "";
    form.querySelector('[name="everyxdays"]').value = row.everyxdays ?? "";
    form.querySelector('[name="yearly_month"]').value = row.yearly_month ?? "";
    form.querySelector('[name="yearly_day"]').value = row.yearly_day ?? "";
    form.querySelector('[name="description"]').value = row.description || "";

    const tagSel = form.querySelector('[data-field="tag"]');
    tagSel.innerHTML =
        '<option value="">— Nessuna —</option>' +
        tagsCache.map((t) => `<option value="${t.id_expiration_tag}" ${Number(row.id_expiration_tag) === Number(t.id_expiration_tag) ? "selected" : ""}>${escapeHtml(t.name)}</option>`).join("");

    const d = document.createElement("dialog");
    d.className = "fb-dialog";
    d.innerHTML = `<div class="fb-dialog-header"><h3 class="fb-dialog-title">Scadenza — ${escapeHtml(row.vehicle_plate || "")}</h3></div><div class="fb-dialog-body"></div>`;
    d.querySelector(".fb-dialog-body").appendChild(form);
    document.body.appendChild(d);
    d.showModal();

    form.addEventListener("submit", async (ev) => {
        ev.preventDefault();
        const data = Object.fromEntries(new FormData(form).entries());
        try {
            await FetchHelper.post(`${window.FB.baseUrl}api/expirations/${row.id_expiration}/update`, data);
            d.close();
            refreshExpirations();
        } catch (err) {
            await dialog.error(err);
        }
    });

    form.querySelector('[data-action="close"]').addEventListener("click", () => d.close());
    d.addEventListener("close", () => d.remove(), { once: true });
}

/* ---------- Init ---------- */

function initPage() {
    initTagsTable();
    initExpirationsTable();
    document.querySelector('[data-action="new-tag"]')?.addEventListener("click", () => openTagForm());
    new PrintHelper({
        table: "#expiration-tags-table",
        button: '[data-action="print-tags"]',
        counter: "#tags-selected-count",
        url: "admin/expirations/print",
        idField: "id_expiration_tag",
        dialog,
    });
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initPage);
} else {
    initPage();
}
