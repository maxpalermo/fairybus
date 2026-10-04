import PrintHelper from "../components/PrintHelper.js";

window.fbActiveFilterOptions = { "0": "No", "1": "Sì" };

function initCustomersTable() {
    if (!window.$ || !window.$.fn.bootstrapTable) {
        setTimeout(initCustomersTable, 100);
        return;
    }

    window.$("#customers-table").bootstrapTable({
        url: window.FB.baseUrl + "api/customers",
        sidePagination: "client",
        pagination: true,
        search: false,
        filterControl: true,
        sortable: true,
        locale: "it-IT",
        pageSize: 25,
        columns: [
            { field: "state", checkbox: true, align: "center", valign: "middle" },
            { field: "id_customer", title: "ID", sortable: true, width: 60, align: "center" , filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "company", title: "Ragione sociale", sortable: true , filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "vat_number", title: "P. IVA / CF", sortable: true , filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "email", title: "Email", sortable: true , filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "contact_name", title: "Referente", sortable: true , filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "address1", title: "Indirizzo", sortable: true, formatter: formatAddress , filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "postcode", title: "CAP", sortable: true, width: 80 , filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "city", title: "Città", sortable: true , filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "state_name", title: "Provincia", sortable: true , filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "active", title: "Attivo", sortable: true, width: 80, align: "center", formatter: formatActive , searchFormatter: false, filterControl: "select", filterData: "var:fbActiveFilterOptions" },
        ],
    });
}

function formatAddress(value, row) {
    return [row.address1, row.address2].filter(Boolean).join(" ") || "—";
}

function formatActive(value) {
    return Number(value) === 1 ? '<span class="fb-badge fb-badge-success">Sì</span>' : '<span class="fb-badge fb-badge-danger">No</span>';
}

function initPrint() {
    new PrintHelper({
        table: "#customers-table",
        button: '[data-action="print-customers"]',
        counter: "#customers-selected-count",
        url: "admin/customers/print",
        idField: "id_customer",
    });
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", () => {
        initCustomersTable();
        initPrint();
    });
} else {
    initCustomersTable();
    initPrint();
}
