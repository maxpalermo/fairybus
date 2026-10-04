import FetchHelper from "../core/FetchHelper.js";
import DialogHelper from "../core/DialogHelper.js";

const dialog = new DialogHelper();
const idVehicle = window.FB.idVehicle;

function escapeHtml(text) {
    const div = document.createElement("div");
    div.textContent = String(text ?? "");
    return div.innerHTML;
}

function initTabs() {
    const tabs = document.querySelectorAll(".fb-form-tabs .fb-tab");
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
