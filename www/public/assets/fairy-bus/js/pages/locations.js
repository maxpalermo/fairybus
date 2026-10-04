import FetchHelper from "../core/FetchHelper.js";

window.fbActiveFilterOptions = { "0": "No", "1": "Sì" };

const countriesData = [];
const statesData = [];

function initTabs() {
    document.querySelectorAll(".fb-tab").forEach((tab) => {
        tab.addEventListener("click", () => {
            document.querySelectorAll(".fb-tab").forEach((t) => t.classList.remove("active"));
            document.querySelectorAll(".fb-tab-panel").forEach((p) => p.classList.remove("active"));
            tab.classList.add("active");
            const panel = document.querySelector(`.fb-tab-panel[data-panel="${tab.dataset.tab}"]`);
            if (panel) {
                panel.classList.add("active");
            }
        });
    });
}

function initCountriesTable() {
    if (!window.$ || !window.$.fn.bootstrapTable) {
        setTimeout(initCountriesTable, 100);
        return;
    }

    window.$("#countries-table").bootstrapTable({
        url: window.FB.baseUrl + "api/locations/countries",
        sidePagination: "client",
        pagination: true,
        search: false,
        filterControl: true,
        sortable: true,
        locale: "it-IT",
        pageSize: 25,
        columns: [
            { field: "state", checkbox: true, align: "center", valign: "middle" },
            { field: "id_country", title: "ID", sortable: true, width: 60, align: "center" , filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "iso_code", title: "ISO", sortable: true, width: 80 , filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "name", title: "Nazione", sortable: true , filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "call_prefix", title: "Prefisso", sortable: true, width: 100, align: "center" , filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "active", title: "Attiva", sortable: true, width: 80, align: "center", formatter: formatActive , searchFormatter: false, filterControl: "select", filterData: "var:fbActiveFilterOptions" },
        ],
    });
}

function initStatesTable() {
    if (!window.$ || !window.$.fn.bootstrapTable) {
        setTimeout(initStatesTable, 100);
        return;
    }

    const $table = window.$("#states-table");
    $table.bootstrapTable({
        url: window.FB.baseUrl + "api/locations/states",
        sidePagination: "client",
        pagination: true,
        search: false,
        filterControl: true,
        sortable: true,
        locale: "it-IT",
        pageSize: 25,
        queryParams: function () {
            return { country_id: document.getElementById("state-country-filter")?.value || "" };
        },
        columns: [
            { field: "state", checkbox: true, align: "center", valign: "middle" },
            { field: "id_state", title: "ID", sortable: true, width: 60, align: "center" , filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "iso_code", title: "ISO", sortable: true, width: 80 , filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "name", title: "Provincia", sortable: true , filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "id_country", title: "ID Nazione", sortable: true, width: 100, align: "center" , filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "active", title: "Attiva", sortable: true, width: 80, align: "center", formatter: formatActive , searchFormatter: false, filterControl: "select", filterData: "var:fbActiveFilterOptions" },
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
        url: window.FB.baseUrl + "api/locations/cities",
        sidePagination: "server",
        pagination: true,
        search: false,
        sortable: true,
        locale: "it-IT",
        pageSize: 50,
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
            { field: "id_city", title: "ID", sortable: true, width: 60, align: "center" , filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "postcode", title: "CAP", sortable: true, width: 90 , filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "city", title: "Città", sortable: true , filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "state", title: "Provincia", sortable: true, width: 120 },
            { field: "iso_code", title: "ISO", sortable: true, width: 80 , filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "status", title: "Stato", sortable: true, width: 80, align: "center" , filterControl: "input", filterCustomSearch: window.fbFilterSearch },
        ],
    });

    const refresh = () => $table.bootstrapTable("refresh", { pageNumber: 1 });
    document.getElementById("city-state-filter")?.addEventListener("change", refresh);
    document.getElementById("city-search")?.addEventListener("input", debounce(refresh, 300));
}

function debounce(fn, ms) {
    let t;
    return function (...args) {
        clearTimeout(t);
        t = setTimeout(() => fn.apply(this, args), ms);
    };
}

function formatActive(value) {
    return Number(value) === 1 ? '<span class="fb-badge fb-badge-success">Sì</span>' : '<span class="fb-badge fb-badge-danger">No</span>';
}

async function loadOptions() {
    try {
        const res = await FetchHelper.get(`${window.FB.baseUrl}api/locations/options`);
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
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initPage);
} else {
    initPage();
}
