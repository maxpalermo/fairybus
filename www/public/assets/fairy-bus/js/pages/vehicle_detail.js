import FetchHelper from "../core/FetchHelper.js";
import DialogHelper from "../core/DialogHelper.js";

const dialog = new DialogHelper();
const idVehicle = window.FB.idVehicle;

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

function fmtQty(value) {
    const n = Number(value) || 0;
    return n.toLocaleString("it-IT", { minimumFractionDigits: 0, maximumFractionDigits: 3 });
}

function fmtMoney(value) {
    const n = Number(value) || 0;
    return `${n.toLocaleString("it-IT", { minimumFractionDigits: 2, maximumFractionDigits: 2 })} €`;
}

function vatLabel(row) {
    const vat = row.vat_code ?? row.vat_rate;
    if (vat === null || vat === undefined || vat === "") {
        return "—";
    }
    const num = Number(vat);
    return isNaN(num) ? escapeHtml(String(vat)) : `${num.toLocaleString("it-IT", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}%`;
}

function lineTotals(row) {
    const qty = Math.abs(Number(row.quantity || 0));
    const price = Number(row.price || 0);
    const discount = Number(row.discount || 0);
    const vat = row.vat_rate !== null && row.vat_rate !== undefined ? Number(row.vat_rate) : 0;
    const importo = qty * price * (1 + discount / 100);
    const manodopera = Math.abs(Number(row.labor_manpower || 0));
    return { importo, manodopera, totale: (importo + manodopera) * (1 + vat / 100) };
}

/* ---------- Tabella scadenze ---------- */

const PERIODICITY_LABELS = {
    once: "Una tantum",
    yearly: "Annuale",
    days: "Ogni N giorni",
    km: "Chilometrica",
};

const OCCURRENCE_STATE_LABELS = {
    0: "In attesa",
    1: "Notificata",
    2: "Pianificata",
    3: "Scaduta",
    4: "Eseguita",
};

window.fbVehKindFilterOptions = { km: "Km", date: "Data" };
window.fbVehPeriodicityFilterOptions = PERIODICITY_LABELS;
window.fbVehStateFilterOptions = OCCURRENCE_STATE_LABELS;

function kindBadge(kind) {
    return kind === "km" ? '<span class="fb-kind-badge fb-kind-km">Km</span>' : '<span class="fb-kind-badge fb-kind-date">Data</span>';
}

function stateBadge(state) {
    if (state === null || state === undefined || state === "") {
        return '<span class="fb-muted">—</span>';
    }
    const label = OCCURRENCE_STATE_LABELS[Number(state)] || escapeHtml(state);
    return `<span class="fb-state-badge fb-state-${Number(state)}">${label}</span>`;
}

function expirationDeadline(row) {
    // prossima occorrenza aperta, altrimenti la data/km della definizione
    if (row.next_state !== null && row.next_state !== undefined) {
        if (row.kind === "km" || (row.next_km && !row.next_date)) {
            return fmtKm(row.next_km);
        }
        if (row.next_date) {
            return fmtDate(row.next_date);
        }
    }
    return row.kind === "km" ? fmtKm(row.expires_atkm) : fmtDate(row.expiration_date);
}

function initExpirationsTable() {
    if (!window.$ || !window.$.fn.bootstrapTable) {
        setTimeout(initExpirationsTable, 100);
        return;
    }

    window.$("#vehicle-expirations-table").bootstrapTable({
        url: `${window.FB.baseUrl}api/expirations?vehicle_id=${idVehicle}`,
        sidePagination: "client",
        pagination: true,
        search: false,
        filterControl: true,
        sortable: true,
        locale: "it-IT",
        columns: [
            { field: "tag_name", title: "Voce", sortable: true, formatter: (v, r) => escapeHtml(r.tag_name || r.description || "—"), filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "kind", title: "Tipo", sortable: true, align: "center", formatter: (v) => kindBadge(v), filterControl: "select", filterData: "var:fbVehKindFilterOptions" },
            { field: "next_date", title: "Scadenza", sortable: true, formatter: (v, r) => expirationDeadline(r), filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "periodicity", title: "Periodicità", sortable: true, formatter: (v) => escapeHtml(PERIODICITY_LABELS[v] || v || "—"), filterControl: "select", filterData: "var:fbVehPeriodicityFilterOptions" },
            { field: "next_state", title: "Stato", sortable: true, align: "center", formatter: (v) => stateBadge(v), filterControl: "select", filterData: "var:fbVehStateFilterOptions" },
            { field: "note", title: "Nota", formatter: (v) => escapeHtml(v || "—"), filterControl: "input", filterCustomSearch: window.fbFilterSearch },
        ],
    });
}

/* ---------- Tabella riparazioni (righe dettaglio delle schede) ---------- */

function formatMaintenanceDoc(value, row) {
    return `<a class="fb-doc-link" target="_blank" rel="noopener" href="${window.FB.baseUrl}admin/maintenance?open=${row.id_maintenance}" title="Apri scheda manutenzione">#${escapeHtml(row.id_maintenance)}</a>`;
}

function initRepairsTable() {
    if (!window.$ || !window.$.fn.bootstrapTable) {
        setTimeout(initRepairsTable, 100);
        return;
    }

    window.$("#vehicle-repairs-table").bootstrapTable({
        url: `${window.FB.baseUrl}api/maintenance/lines?vehicle_id=${idVehicle}`,
        sidePagination: "client",
        pagination: true,
        search: false,
        filterControl: true,
        sortable: true,
        locale: "it-IT",
        columns: [
            { field: "maintenance_date", title: "Data", sortable: true, formatter: (v) => fmtDate(v), filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "id_maintenance", title: "Documento", sortable: true, align: "center", formatter: formatMaintenanceDoc, filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "sku", title: "Codice", sortable: true, formatter: (v) => escapeHtml(v || "—"), filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "product_name", title: "Articolo", sortable: true, formatter: (v) => escapeHtml(v || "—"), filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "quantity", title: "Q.tà", sortable: true, align: "right", formatter: (v) => fmtQty(Math.abs(Number(v) || 0)), filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "price", title: "Prezzo", sortable: true, align: "right", formatter: (v) => fmtMoney(v), filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "discount", title: "Sconto", sortable: true, align: "right", formatter: (v) => `${Number(v || 0)}%` },
            { field: "importo", title: "Importo", sortable: true, align: "right", formatter: (v, r) => fmtMoney(lineTotals(r).importo) },
            { field: "labor_hours", title: "Ore", sortable: true, align: "right", formatter: (v) => (v !== null && v !== undefined ? fmtQty(Math.abs(Number(v))) : "—") },
            { field: "labor_price_per_hour", title: "€/h", sortable: true, align: "right", formatter: (v) => (v !== null && v !== undefined ? fmtMoney(v) : "—") },
            { field: "labor_manpower", title: "Manodopera", sortable: true, align: "right", formatter: (v) => (v !== null && v !== undefined ? fmtMoney(Math.abs(Number(v))) : "—") },
            { field: "vat_rate", title: "I.v.a.", sortable: true, formatter: (v, r) => vatLabel(r) },
            { field: "totale", title: "Totale", sortable: true, align: "right", formatter: (v, r) => fmtMoney(lineTotals(r).totale) },
        ],
    });
}

function initTabs() {
    const tabs = document.querySelectorAll(".fb-form-tabs .fb-tab");
    const tablesInited = {};
    tabs.forEach((tab) => {
        tab.addEventListener("click", () => {
            const panelName = tab.dataset.tab;
            tabs.forEach((t) => {
                t.classList.remove("active");
                t.setAttribute("aria-selected", "false");
            });
            tab.classList.add("active");
            tab.setAttribute("aria-selected", "true");

            document.querySelectorAll(".fb-tab-panel").forEach((p) => {
                p.classList.toggle("active", p.dataset.panel === panelName);
            });

            // bootstrapTable va inizializzato quando il pannello e' visibile
            const tableForPanel = { expirations: "#vehicle-expirations-table", repairs: "#vehicle-repairs-table" };
            const selector = tableForPanel[panelName];
            if (selector) {
                if (!tablesInited[panelName]) {
                    tablesInited[panelName] = true;
                    (panelName === "expirations" ? initExpirationsTable : initRepairsTable)();
                } else if (window.$ && window.$(selector).data("bootstrap.table")) {
                    window.$(selector).bootstrapTable("resetView");
                }
            }
        });
    });
}

function renderInfo(vehicle) {
    const container = document.getElementById("vehicle-detail-info");
    const rows = [
        { label: "Targa", value: vehicle.plate },
        { label: "Marca", value: vehicle.brand_name ?? "—" },
        { label: "Stato", value: vehicle.status },
        { label: "Numero telaio", value: vehicle.chassis_number ?? "—" },
        { label: "KM attuali", value: vehicle.current_km },
        { label: "Data inizio", value: vehicle.start_date ?? "—" },
        { label: "Data fine", value: vehicle.end_date ?? "—" },
        { label: "Classe veicolo", value: vehicle.description ?? "—" },
        { label: "Note", value: vehicle.note ?? "—" },
    ];

    container.innerHTML = rows
        .map(
            (row) => `
        <div class="fb-detail-field">
            <span class="fb-detail-label">${escapeHtml(row.label)}</span>
            <span class="fb-detail-value">${escapeHtml(row.value)}</span>
        </div>
    `,
        )
        .join("");

    document.getElementById("vehicle-plate").textContent = vehicle.plate;
}

function renderFeatures(vehicle) {
    const container = document.getElementById("vehicle-detail-features");
    if (!vehicle.features || vehicle.features.length === 0) {
        container.innerHTML = `<p class="fb-muted">Nessuna caratteristica registrata.</p>`;
        return;
    }

    container.innerHTML = vehicle.features
        .map((f) => {
            let value = f.value;
            if (f.type === "switch") {
                value = f.value === "1" ? "Sì" : "No";
            }
            return `
            <div class="fb-detail-field">
                <span class="fb-detail-label">${escapeHtml(f.label)}</span>
                <span class="fb-detail-value">${escapeHtml(value)}</span>
            </div>
        `;
        })
        .join("");
}

function renderImages(images) {
    const container = document.getElementById("vehicle-detail-images");
    if (!images || images.length === 0) {
        container.innerHTML = `<p class="fb-muted">Nessuna immagine caricata.</p>`;
        return;
    }

    container.innerHTML = images
        .map(
            (img) => `
        <div class="fb-upload-thumb">
            <img src="${escapeHtml(img.image)}" alt="" loading="lazy">
        </div>
    `,
        )
        .join("");
}

function renderDocuments(documents) {
    const container = document.getElementById("vehicle-detail-documents");
    if (!documents || documents.length === 0) {
        container.innerHTML = `<li class="fb-muted">Nessun documento caricato.</li>`;
        return;
    }

    container.innerHTML = documents
        .map(
            (doc) => `
        <li>
            <div class="fb-document-info">
                <a href="${escapeHtml(doc.document)}" target="_blank" rel="noopener">${escapeHtml(doc.document.split("/").pop())}</a>
                ${doc.description ? `<span class="fb-document-description">${escapeHtml(doc.description)}</span>` : ""}
            </div>
        </li>
    `,
        )
        .join("");
}

async function loadVehicle() {
    try {
        const res = await FetchHelper.get(`${window.FB.baseUrl}api/vehicles/${idVehicle}`);
        renderInfo(res.vehicle);
        renderFeatures(res.vehicle);
        renderImages(res.vehicle.images);
        renderDocuments(res.vehicle.documents);
    } catch (err) {
        await dialog.error(err);
    }
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", () => {
        initTabs();
        loadVehicle();
        document.querySelector('[data-action="edit-vehicle"]')?.addEventListener("click", () => {
            window.location.href = `${window.FB.baseUrl}admin/vehicles`;
        });
    });
} else {
    initTabs();
    loadVehicle();
    document.querySelector('[data-action="edit-vehicle"]')?.addEventListener("click", () => {
        window.location.href = `${window.FB.baseUrl}admin/vehicles`;
    });
}
