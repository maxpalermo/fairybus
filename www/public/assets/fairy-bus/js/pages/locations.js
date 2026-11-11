import FetchHelper from "../core/FetchHelper.js";
import DialogHelper from "../core/DialogHelper.js";
import PrintHelper from "../components/PrintHelper.js";

window.fbActiveFilterOptions = { 0: "No", 1: "Sì" };

const dialog = new DialogHelper();
const BASE = window.FB.baseUrl;

const ICON_CHECK = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
const ICON_TIMES = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';

const countriesData = [];
const statesData = [];

// Scheda attiva: il contatore e i bottoni Cerca/Stampa lavorano sempre
// sulla tabella del pannello visibile.
let activeTab = "countries";
const TABLE_IDS = { countries: "countries-table", states: "states-table", cities: "cities-table" };
const printers = {};

function activeTable() {
    return document.getElementById(TABLE_IDS[activeTab]);
}

function updateCounter() {
    const el = document.getElementById("locations-selected-count");
    if (!el || !printers[activeTab]) {
        return;
    }
    const n = printers[activeTab].getSelectedRows().length;
    el.textContent = `${n} selezionat${n === 1 ? "a" : "i"}`;
}

function bindSelectionCounter(tableSelector) {
    if (!window.$) {
        return;
    }
    const events = ["check.bs.table", "uncheck.bs.table", "check-all.bs.table", "uncheck-all.bs.table", "load-success.bs.table"].join(" ");
    window.$(tableSelector).on(events, updateCounter);
}

function initTabs() {
    document.querySelectorAll(".fb-tab").forEach((tab) => {
        tab.addEventListener("click", () => {
            document.querySelectorAll(".fb-tab").forEach((t) => t.classList.remove("active"));
            document.querySelectorAll(".fb-tab-panel").forEach((p) => p.classList.remove("active"));
            tab.classList.add("active");
            activeTab = tab.dataset.tab;
            const panel = document.querySelector(`.fb-tab-panel[data-panel="${tab.dataset.tab}"]`);
            if (panel) {
                panel.classList.add("active");
            }
            updateCounter();
        });
    });
}

// toggle Attiva condiviso da nazioni e province (whitelist lato server:
// fb_country.active / fb_state.active)
function makeToggleEvents(tableName, pk) {
    return {
        "click .fb-toggle": async (e, value, row) => {
            const el = e.currentTarget;
            el.disabled = true;
            try {
                const newVal = await window.toggleTrueFalse(tableName, "active", row[pk]);
                window.$(`#${TABLE_IDS[activeTab]}`).bootstrapTable("updateCellByUniqueId", {
                    id: row[pk],
                    field: "active",
                    value: newVal,
                });
            } catch (err) {
                el.disabled = false;
                await dialog.error(err);
            }
        },
    };
}

function toggleIcon(value) {
    const on = Number(value) === 1;
    return `<button type="button" class="fb-toggle ${on ? "fb-toggle-on" : "fb-toggle-off"}" title="${on ? "Attiva" : "Disattiva"} — clicca per cambiare">${on ? ICON_CHECK : ICON_TIMES}</button>`;
}

function initCountriesTable() {
    if (!window.$ || !window.$.fn.bootstrapTable) {
        setTimeout(initCountriesTable, 100);
        return;
    }

    window.countryToggleEvents = makeToggleEvents("fb_country", "id_country");

    window.$("#countries-table").bootstrapTable({
        url: BASE + "api/locations/countries",
        sidePagination: "client",
        pagination: true,
        search: false,
        searchOnEnterKey: true,
        searchTimeOut: 300,
        filterControl: true,
        sortable: true,
        locale: "it-IT",
        uniqueId: "id_country",
        columns: [
            { field: "state", checkbox: true, align: "center", valign: "middle" },
            { field: "id_country", title: "ID", sortable: true, width: 60, align: "center", filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "iso_code", title: "ISO", sortable: true, width: 80, filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "name", title: "Nazione", sortable: true, filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "call_prefix", title: "Prefisso", sortable: true, width: 100, align: "center", filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "active", title: "Attiva", sortable: true, width: 80, align: "center", formatter: toggleIcon, searchFormatter: false, filterControl: "select", filterData: "var:fbActiveFilterOptions", events: window.countryToggleEvents },
        ],
    });
}

function initStatesTable() {
    if (!window.$ || !window.$.fn.bootstrapTable) {
        setTimeout(initStatesTable, 100);
        return;
    }

    window.stateToggleEvents = makeToggleEvents("fb_state", "id_state");

    const $table = window.$("#states-table");
    $table.bootstrapTable({
        url: BASE + "api/locations/states",
        sidePagination: "client",
        pagination: true,
        search: false,
        searchOnEnterKey: true,
        searchTimeOut: 300,
        filterControl: true,
        sortable: true,
        locale: "it-IT",
        uniqueId: "id_state",
        queryParams: function () {
            return { country_id: document.getElementById("state-country-filter")?.value || "" };
        },
        columns: [
            { field: "state", checkbox: true, align: "center", valign: "middle" },
            { field: "id_state", title: "ID", sortable: true, width: 60, align: "center", filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "iso_code", title: "ISO", sortable: true, width: 80, filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "name", title: "Provincia", sortable: true, filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "id_country", title: "ID Nazione", sortable: true, width: 100, align: "center", filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "active", title: "Attiva", sortable: true, width: 80, align: "center", formatter: toggleIcon, searchFormatter: false, filterControl: "select", filterData: "var:fbActiveFilterOptions", events: window.stateToggleEvents },
        ],
    });

    document.getElementById("state-country-filter")?.addEventListener("change", () => {
        $table.bootstrapTable("refresh");
    });
}

function initCitiesTable() {
    if (!window.$ || !window.$.fn.bootstrapTable) {
        setTimeout(initCitiesTable, 100);
        return;
    }

    const $table = window.$("#cities-table");
    $table.bootstrapTable({
        url: BASE + "api/locations/cities",
        sidePagination: "server",
        pagination: true,
        search: false,
        sortable: true,
        locale: "it-IT",
        queryParams: function (params) {
            return {
                limit: params.limit,
                offset: params.offset,
                iso_code: document.getElementById("city-state-filter")?.value || "",
                search: document.getElementById("city-search")?.value || "",
            };
        },
        columns: [
            { field: "state", checkbox: true, align: "center", valign: "middle" },
            { field: "id_city", title: "ID", sortable: true, width: 60, align: "center" },
            { field: "postcode", title: "CAP", sortable: true, width: 90 },
            { field: "city", title: "Città", sortable: true },
            { field: "state", title: "Provincia", sortable: true, width: 120 },
            { field: "iso_code", title: "ISO", sortable: true, width: 80 },
            { field: "status", title: "Stato", sortable: true, width: 80, align: "center" },
        ],
    });

    const refresh = () => $table.bootstrapTable("refresh", { pageNumber: 1 });
    document.getElementById("city-state-filter")?.addEventListener("change", refresh);
    // la ricerca città scatta su Invio o col bottone Cerca della toolbar
    document.getElementById("city-search")?.addEventListener("keydown", (e) => {
        if (e.key === "Enter") {
            e.preventDefault();
            refresh();
        }
    });
}

async function loadOptions() {
    try {
        const res = await FetchHelper.get(`${BASE}api/locations/options`);
        const countries = res.countries || [];
        const states = res.states || [];

        const countryFilter = document.getElementById("state-country-filter");
        if (countryFilter) {
            countryFilter.innerHTML = '<option value="">Tutte</option>' + countries.map((c) => `<option value="${c.id_country}">${escapeHtml(c.name)}</option>`).join("");
        }

        const stateFilter = document.getElementById("city-state-filter");
        if (stateFilter) {
            stateFilter.innerHTML = '<option value="">Tutte</option>' + states.map((s) => `<option value="${escapeHtml(s.iso_code)}">${escapeHtml(s.iso_code)} - ${escapeHtml(s.name)}</option>`).join("");
        }

        countriesData.push(...countries);
        statesData.push(...states);
    } catch (err) {
        console.error(err);
    }
}

function escapeHtml(text) {
    const div = document.createElement("div");
    div.textContent = String(text ?? "");
    return div.innerHTML;
}

async function initPage() {
    initTabs();
    await loadOptions();
    initCountriesTable();
    initStatesTable();
    initCitiesTable();

    // un helper di stampa per tabella: il bottone Stampa usa quello della
    // scheda attiva e invia `type` all'endpoint unico admin/locations/print
    for (const [type, tableId] of Object.entries(TABLE_IDS)) {
        printers[type] = new PrintHelper({
            table: `#${tableId}`,
            url: "admin/locations/print",
            idField: { countries: "id_country", states: "id_state", cities: "id_city" }[type],
            extraParams: { type },
            dialog,
        });
        bindSelectionCounter(`#${tableId}`);
    }
    document.querySelector('[data-action="print-locations"]')?.addEventListener("click", () => printers[activeTab]?.print());

    // bottone Cerca: filtri colonna su nazioni/province, refresh su città
    window.$("#countries-table, #states-table").on("post-header.bs.table", (e) => window.fbBindSearchHints(e.currentTarget));
    document.querySelector('[data-action="run-filters"]')?.addEventListener("click", () => {
        if (activeTab === "cities") {
            window.$("#cities-table").bootstrapTable("refresh", { pageNumber: 1 });
        } else {
            window.triggerColumnSearch(activeTable());
        }
    });
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initPage);
} else {
    initPage();
}
