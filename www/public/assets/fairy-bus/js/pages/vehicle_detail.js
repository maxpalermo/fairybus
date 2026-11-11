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
    const importo = qty * price * (1 - Math.abs(discount) / 100);
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
            { field: "kind", title: "Tipo", sortable: true, align: "center", formatter: (v) => kindBadge(v), searchFormatter: false, filterControl: "select", filterData: "var:fbVehKindFilterOptions" },
            { field: "next_date", title: "Scadenza", sortable: true, formatter: (v, r) => expirationDeadline(r), filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "periodicity", title: "Periodicità", sortable: true, formatter: (v) => escapeHtml(PERIODICITY_LABELS[v] || v || "—"), searchFormatter: false, filterControl: "select", filterData: "var:fbVehPeriodicityFilterOptions" },
            { field: "next_state", title: "Stato", sortable: true, align: "center", formatter: (v) => stateBadge(v), searchFormatter: false, filterControl: "select", filterData: "var:fbVehStateFilterOptions" },
            { field: "note", title: "Nota", formatter: (v) => escapeHtml(v || "—"), filterControl: "input", filterCustomSearch: window.fbFilterSearch },
        ],
    });
}

/* ---------- Tabella riparazioni (righe dettaglio delle schede) ---------- */

function formatMaintenanceDoc(value, row) {
    let html = `<a class="fb-doc-link" target="_blank" rel="noopener" href="${window.FB.baseUrl}admin/maintenance?open=${row.id_maintenance}" title="Apri scheda manutenzione">#${escapeHtml(row.id_maintenance)}</a>`;
    if (row.load_document_id) {
        const num = escapeHtml(row.load_number || `#${row.load_document_id}`);
        const sup = row.load_supplier ? `<span class="fb-load-sup">${escapeHtml(row.load_supplier)}</span>` : "";
        html += `<a class="fb-load-link" target="_blank" rel="noopener" href="${window.FB.baseUrl}admin/documents?open=${row.load_document_id}" data-doc-id="${row.load_document_id}" title="Mostra il documento di carico (Ctrl+click: nuova scheda)"><span class="fb-load-num">${num}</span>${sup}</a>`;
    }
    return `<div class="fb-doc-cell">${html}</div>`;
}

// Oltre questa soglia il PDF Dompdf diventerebbe troppo lento da generare:
// il report viene servito in HTML e stampato/salvato in PDF dal browser.
const HTML_REPORT_MIN_LINES = 250;
let repairsRows = [];

function updateRepairsStats(rows) {
    repairsRows = rows;
    let partsQty = 0;
    let total = 0;
    for (const row of rows) {
        partsQty += Math.abs(Number(row.quantity || 0));
        total += lineTotals(row).totale;
    }
    const setText = (id, text) => {
        const el = document.getElementById(id);
        if (el) {
            el.textContent = text;
        }
    };
    // "Riparazioni" = righe/interventi dello storico (una scheda può avere più righe)
    setText("rep-stat-count", rows.length.toLocaleString("it-IT"));
    setText("rep-stat-parts", fmtQty(partsQty));
    setText("rep-stat-total", fmtMoney(total));
}

async function printRepairs() {
    // righe selezionate via checkbox (vuoto = tutto lo storico)
    const selected = window.$("#vehicle-repairs-table").bootstrapTable("getSelections") || [];
    const selectedIds = selected.map((r) => Number(r.id_maintenance_detail)).filter((i) => i > 0);
    const lineCount = selectedIds.length || repairsRows.length;

    if (lineCount > HTML_REPORT_MIN_LINES) {
        await dialog.alert(`${selectedIds.length ? "Le righe selezionate sono" : "Lo storico contiene"} ${lineCount.toLocaleString("it-IT")} riparazioni: il report è molto lungo, quindi verrà generato in formato HTML e stampato dal browser (dal dialog di stampa puoi salvarlo in PDF).`, "Report esteso");
        const qs = selectedIds.length ? `?ids=${selectedIds.join(",")}` : "";
        window.open(`${window.FB.baseUrl}admin/vehicles/${idVehicle}/repairs/report${qs}`, "_blank");
        return;
    }

    // Submit via form nascosto in nuova scheda (stesso meccanismo di PrintHelper)
    const form = document.createElement("form");
    form.method = "POST";
    form.action = `${window.FB.baseUrl}admin/vehicles/${idVehicle}/repairs/print`;
    form.target = "_blank";
    form.style.display = "none";

    const dl = document.createElement("input");
    dl.type = "hidden";
    dl.name = "download";
    dl.value = localStorage.getItem("fb_force_pdf_download") === "1" ? "1" : "0";
    form.appendChild(dl);

    if (selectedIds.length) {
        const ids = document.createElement("input");
        ids.type = "hidden";
        ids.name = "ids";
        ids.value = JSON.stringify(selectedIds);
        form.appendChild(ids);
    }

    document.body.appendChild(form);
    form.submit();
    form.remove();
}

function buildDialog(title, size = "") {
    const d = document.createElement("dialog");
    d.className = `fb-dialog${size ? " " + size : ""}`;
    d.innerHTML = `
        <div class="fb-dialog-header">
            <h3 class="fb-dialog-title">${escapeHtml(title)}</h3>
            <button type="button" class="fb-dialog-close" aria-label="Chiudi">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="fb-dialog-body"></div>`;
    d.querySelector(".fb-dialog-close").addEventListener("click", () => d.close());
    document.body.appendChild(d);
    d.showModal();
    d.addEventListener("close", () => d.remove(), { once: true });
    return d;
}

/**
 * Scheda del documento di carico: testata (numero/data/fornitore) + movimenti.
 */
async function openLoadDialog(idDocument) {
    let doc;
    try {
        const res = await FetchHelper.get(`${window.FB.baseUrl}api/documents/${idDocument}`);
        doc = res.document;
    } catch (err) {
        await dialog.error(err);
        return;
    }

    const d = buildDialog(`Carico n. ${doc.number || doc.id_document}`, "fb-dialog-wide");

    const info = [
        ["Numero", doc.number],
        ["Data", fmtDate(doc.date)],
        ["Fornitore", doc.supplier_name],
        ["Tipo", doc.type_name],
        ["Fattura", doc.invoice_number ? `n. ${doc.invoice_number}` : null],
        ["Note", doc.note],
    ]
        .filter(([, v]) => v !== null && v !== undefined && String(v) !== "")
        .map(([l, v]) => `<div class="fb-detail-field"><span class="fb-detail-label">${escapeHtml(l)}</span><span class="fb-detail-value">${escapeHtml(v)}</span></div>`)
        .join("");

    let totDoc = 0;
    const rows = (doc.details || [])
        .map((r) => {
            const qty = Math.abs(Number(r.quantity || 0));
            const imp = qty * Number(r.price || 0) * (1 - Math.abs(Number(r.discount || 0)) / 100);
            const tot = imp * (1 + Number(r.vat_rate || 0) / 100);
            totDoc += tot;
            return `<tr>
                <td>${escapeHtml(r.sku || "—")}</td>
                <td>${escapeHtml(r.product_name || "—")}</td>
                <td class="num">${fmtQty(qty)}</td>
                <td class="num">${fmtMoney(r.price)}</td>
                <td class="num">${Math.abs(Number(r.discount || 0)).toLocaleString("it-IT", { maximumFractionDigits: 2 })}%</td>
                <td class="num">${r.vat_rate !== null && r.vat_rate !== undefined ? `${Number(r.vat_rate).toLocaleString("it-IT", { maximumFractionDigits: 2 })}%` : "—"}</td>
                <td class="num fb-line-total">${fmtMoney(tot)}</td>
            </tr>`;
        })
        .join("");

    d.querySelector(".fb-dialog-body").innerHTML = `
        <div class="fb-load-doc-info">${info}</div>
        <div class="fb-load-doc-table">
            <table>
                <thead><tr><th>Codice</th><th>Articolo</th><th class="num">Q.tà</th><th class="num">Prezzo</th><th class="num">Sconto</th><th class="num">I.v.a.</th><th class="num">Totale</th></tr></thead>
                <tbody>${rows || '<tr><td colspan="7" class="fb-load-empty">Nessun movimento</td></tr>'}</tbody>
                <tfoot><tr><td colspan="6">Totale documento (IVA incl.)</td><td class="num fb-line-total">${fmtMoney(totDoc)}</td></tr></tfoot>
            </table>
        </div>
        <div class="fb-form-actions">
            <a class="fb-btn fb-btn-secondary" target="_blank" rel="noopener" href="${window.FB.baseUrl}admin/documents?open=${doc.id_document}">Apri in Documenti</a>
            <button type="button" class="fb-btn fb-btn-primary" data-action="close">Chiudi</button>
        </div>`;
    d.querySelector('[data-action="close"]').addEventListener("click", () => d.close());
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
        searchOnEnterKey: true,
        filterControl: true,
        sortable: true,
        locale: "it-IT",
        uniqueId: "id_maintenance_detail",
        onLoadSuccess: (data) => updateRepairsStats(data.rows || []),
        columns: [
            { field: "state", checkbox: true, align: "center", valign: "middle" },
            { field: "maintenance_date", title: "Data", sortable: true, width: 90, formatter: (v) => fmtDate(v), filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "id_maintenance", title: "Documento", sortable: true, align: "center", width: 150, formatter: formatMaintenanceDoc, filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "sku", title: "Codice", sortable: true, formatter: (v) => escapeHtml(v || "—"), filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "product_name", title: "Articolo", sortable: true, formatter: (v) => escapeHtml(v || "—"), filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "quantity", title: "Q.tà", sortable: true, align: "right", width: 60, formatter: (v) => fmtQty(Math.abs(Number(v) || 0)), filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "price", title: "Prezzo", sortable: true, align: "right", formatter: (v) => fmtMoney(v), filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "discount", title: "Sconto", sortable: true, align: "right", formatter: (v) => `${Math.abs(Number(v || 0))}%` },
            { field: "importo", title: "Importo", sortable: true, align: "right", formatter: (v, r) => fmtMoney(lineTotals(r).importo) },
            { field: "labor_hours", title: "Ore", sortable: true, align: "right", formatter: (v) => (v !== null && v !== undefined ? fmtQty(Math.abs(Number(v))) : "—") },
            { field: "labor_price_per_hour", title: "€/h", sortable: true, align: "right", formatter: (v) => (v !== null && v !== undefined ? fmtMoney(v) : "—") },
            { field: "labor_manpower", title: "Manodopera", sortable: true, align: "right", formatter: (v) => (v !== null && v !== undefined ? fmtMoney(Math.abs(Number(v))) : "—") },
            { field: "vat_rate", title: "I.v.a.", sortable: true, formatter: (v, r) => vatLabel(r) },
            { field: "totale", title: "Totale", sortable: true, align: "right", formatter: (v, r) => `<span class="fb-line-total">${fmtMoney(lineTotals(r).totale)}</span>` },
        ],
    });

    // click sul carico: scheda nella stessa pagina (Ctrl/middle click resta nuova tab)
    document.getElementById("vehicle-repairs-table").addEventListener("click", (ev) => {
        const link = ev.target.closest("a.fb-load-link[data-doc-id]");
        if (!link || ev.ctrlKey || ev.metaKey || ev.shiftKey) {
            return;
        }
        ev.preventDefault();
        openLoadDialog(Number(link.dataset.docId));
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
        { label: "Stato", value: vehicle.status === "retired" ? "Dismesso" : "Attivo" },
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
            <img src="${escapeHtml(img.image)}" alt="" loading="lazy" data-action="zoom-image" data-src="${escapeHtml(img.image)}" title="Ingrandisci">
        </div>
    `,
        )
        .join("");
}

// Lightbox a tutto schermo: un click ovunque chiude.
function openImageLightbox(src) {
    const d = document.createElement("dialog");
    d.className = "fb-dialog fb-lightbox";
    d.innerHTML = `<img src="${escapeHtml(src)}" alt="">`;
    document.body.appendChild(d);
    d.showModal();
    d.addEventListener("click", () => d.close());
    d.addEventListener("close", () => d.remove(), { once: true });
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

function initPage() {
    initTabs();
    loadVehicle();
    document.getElementById("vehicle-detail-images")?.addEventListener("click", (ev) => {
        const zoom = ev.target.closest("[data-action=zoom-image]");
        if (zoom) {
            openImageLightbox(zoom.dataset.src);
        }
    });
    document.querySelector('[data-action="edit-vehicle"]')?.addEventListener("click", () => {
        window.location.href = `${window.FB.baseUrl}admin/vehicles`;
    });
    document.querySelector('[data-action="repairs-search"]')?.addEventListener("click", () => {
        window.triggerColumnSearch?.(document.getElementById("vehicle-repairs-table"));
    });
    document.querySelector('[data-action="print-repairs"]')?.addEventListener("click", printRepairs);
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initPage);
} else {
    initPage();
}
