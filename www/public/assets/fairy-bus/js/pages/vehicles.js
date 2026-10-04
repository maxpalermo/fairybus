import FetchHelper from "../core/FetchHelper.js";
import DialogHelper from "../core/DialogHelper.js";
import PrintHelper from "../components/PrintHelper.js";

const dialog = new DialogHelper();

function escapeHtml(text) {
    const div = document.createElement("div");
    div.textContent = String(text ?? "");
    return div.innerHTML;
}

let globalDefinitions = null;

function formatActions(value, row) {
    return `
        <button type="button" class="fb-btn fb-btn-secondary fb-btn-sm" data-action="view-vehicle" data-id="${row.id_vehicle}">Visualizza</button>
        <button type="button" class="fb-btn fb-btn-secondary fb-btn-sm" data-action="edit-vehicle" data-id="${row.id_vehicle}">Modifica</button>
        <button type="button" class="fb-btn fb-btn-secondary fb-btn-sm" data-action="delete-vehicle" data-id="${row.id_vehicle}">Elimina</button>
    `;
}

function openVehicleDetail(idVehicle) {
    window.location.href = `${window.FB.baseUrl}admin/vehicles/${idVehicle}`;
}

window.vehicleActionsEvents = {
    "click [data-action=view-vehicle]": function (ev, value, row) {
        ev.stopPropagation();
        openVehicleDetail(row.id_vehicle);
    },
    "click [data-action=edit-vehicle]": async function (ev, value, row) {
        ev.stopPropagation();
        openVehicleForm(row.id_vehicle);
    },
    "click [data-action=delete-vehicle]": async function (ev, value, row) {
        ev.stopPropagation();
        if (!(await dialog.confirm(`Eliminare il veicolo con targa "${escapeHtml(row.plate)}"?`, "Conferma"))) {
            return;
        }
        try {
            await FetchHelper.post(`${window.FB.baseUrl}api/vehicles/${row.id_vehicle}/delete`, {});
            refreshTable();
        } catch (err) {
            await dialog.error(err);
        }
    },
};

function initVehiclesTable() {
    if (!window.$ || !window.$.fn.bootstrapTable) {
        setTimeout(initVehiclesTable, 100);
        return;
    }

    window.$("#vehicles-table").bootstrapTable({
        url: window.FB.baseUrl + "api/vehicles",
        sidePagination: "client",
        pagination: true,
        search: false,
        filterControl: true,
        sortable: true,
        clickToSelect: false,
        locale: "it-IT",
        onClickRow: function (row, $element, field) {
            openVehicleDetail(row.id_vehicle);
        },
        columns: [
            { field: "state", checkbox: true, align: "center", valign: "middle" },
            { field: "id_vehicle", title: "ID", sortable: true, width: 80, align: "center" , filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "plate", title: "Targa", sortable: true , filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "brand_name", title: "Marca", sortable: true , filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "status", title: "Stato", sortable: true, formatter: formatStatus , filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "current_km", title: "KM", sortable: true, align: "right" , filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "actions", title: "Azioni", formatter: formatActions, events: window.vehicleActionsEvents },
        ],
    });
}

function formatStatus(value) {
    const labels = {
        active: "Attivo",
        maintenance: "In manutenzione",
        retired: "Ritirato",
    };
    return `<span class="fb-badge">${escapeHtml(labels[value] ?? value)}</span>`;
}

function refreshTable() {
    const $table = window.$("#vehicles-table");
    if ($table.length && typeof $table.bootstrapTable === "function") {
        $table.bootstrapTable("refresh");
    }
}

async function getDefinitions() {
    if (globalDefinitions) {
        return globalDefinitions;
    }
    globalDefinitions = await FetchHelper.get(`${window.FB.baseUrl}api/vehicles/definitions`);
    return globalDefinitions;
}

async function openVehicleForm(idVehicle = null) {
    const definitions = await getDefinitions();
    const tpl = document.getElementById("tpl-vehicle-form");
    const form = tpl.content.cloneNode(true).querySelector("form");
    const title = idVehicle ? "Modifica veicolo" : "Nuovo veicolo";

    let vehicle = null;
    if (idVehicle) {
        try {
            const res = await FetchHelper.get(`${window.FB.baseUrl}api/vehicles/${idVehicle}`);
            vehicle = res.vehicle;
        } catch (err) {
            await dialog.error(err);
            return;
        }
    }

    populateSelect(form.querySelector('[name="id_brand"]'), definitions.brands, "id_brand", "name", vehicle?.id_brand ?? "");
    populateSelect(form.querySelector('[name="status"]'), definitions.statuses, "value", "label", vehicle?.status ?? "active");

    if (vehicle) {
        form.querySelector('[name="id_vehicle"]').value = vehicle.id_vehicle;
        form.querySelector('[name="plate"]').value = vehicle.plate || "";
        form.querySelector('[name="chassis_number"]').value = vehicle.chassis_number || "";
        form.querySelector('[name="current_km"]').value = vehicle.current_km || 0;
        form.querySelector('[name="start_date"]').value = vehicle.start_date || "";
        form.querySelector('[name="end_date"]').value = vehicle.end_date || "";
        form.querySelector('[name="description"]').value = vehicle.description || "";
        form.querySelector('[name="note"]').value = vehicle.note || "";

        renderFeatures(form, definitions.features, vehicle.features);
        renderImages(form, vehicle.images, vehicle.id_vehicle);
        renderDocuments(form, vehicle.documents, vehicle.id_vehicle);
    } else {
        renderFeatures(form, definitions.features, []);
        renderImages(form, [], null);
        renderDocuments(form, [], null);
    }

    const d = document.createElement("dialog");
    d.className = "fb-dialog fb-dialog-wide";
    d.innerHTML = `<div class="fb-dialog-header"><h3 class="fb-dialog-title">${escapeHtml(title)}</h3></div><div class="fb-dialog-body"></div>`;
    d.querySelector(".fb-dialog-body").appendChild(form);
    document.body.appendChild(d);
    d.showModal();

    initFormTabs(form);
    initUploadHandlers(form, idVehicle);

    form.addEventListener("submit", async (ev) => {
        ev.preventDefault();
        await submitVehicle(form, idVehicle);
    });

    form.querySelector('[data-action="close"]').addEventListener("click", () => d.close());
    d.addEventListener("close", () => d.remove(), { once: true });
}

function populateSelect(select, options, valueField, labelField, selectedValue) {
    options.forEach((opt) => {
        const option = document.createElement("option");
        option.value = opt[valueField];
        option.textContent = opt[labelField];
        if (String(opt[valueField]) === String(selectedValue)) {
            option.selected = true;
        }
        select.appendChild(option);
    });
}

function renderFeatureField(feature, value) {
    const id = `feature-${feature.id_feature}`;

    if (feature.type === "switch") {
        return `
            <label class="fb-switch-label" for="${id}">
                <input type="checkbox" id="${id}" name="features[${feature.id_feature}]" value="1" ${value === "1" ? "checked" : ""}>
                <span class="fb-switch-slider"></span>
                <span class="fb-feature-label">${escapeHtml(feature.label)}</span>
            </label>
        `;
    }

    if (feature.type === "select") {
        let optionsHtml = `<option value="">—</option>`;
        (feature.options || []).forEach((opt) => {
            optionsHtml += `<option value="${escapeHtml(opt.value)}" ${String(opt.value) === String(value) ? "selected" : ""}>${escapeHtml(opt.label)}</option>`;
        });
        return `
            <label class="fb-form-label" for="${id}">${escapeHtml(feature.label)}</label>
            <select id="${id}" name="features[${feature.id_feature}]" class="fb-form-input">${optionsHtml}</select>
        `;
    }

    return `
        <label class="fb-form-label" for="${id}">${escapeHtml(feature.label)}</label>
        <input type="text" id="${id}" name="features[${feature.id_feature}]" class="fb-form-input" value="${escapeHtml(value)}">
    `;
}

function renderFeatures(form, features, vehicleFeatures) {
    const container = form.querySelector("#vehicle-features");
    const values = {};
    vehicleFeatures.forEach((vf) => {
        values[vf.id_feature] = vf.value;
    });

    const groups = {
        switch: features.filter((f) => f.type === "switch"),
        select: features.filter((f) => f.type === "select"),
        value: features.filter((f) => f.type === "value"),
    };
    const titles = {
        switch: "Opzioni on/off",
        select: "Selezione da elenco",
        value: "Valori liberi",
    };

    container.innerHTML = `
        <h3 class="fb-features-section-title">Caratteristiche</h3>
        <div class="fb-features-groups"></div>
    `;

    const groupsContainer = container.querySelector(".fb-features-groups");

    Object.entries(groups).forEach(([type, groupFeatures]) => {
        if (groupFeatures.length === 0) {
            return;
        }

        groupFeatures.sort((a, b) => a.label.localeCompare(b.label));

        const section = document.createElement("div");
        section.className = "fb-features-group";
        section.innerHTML = `<h4 class="fb-features-group-title">${escapeHtml(titles[type])}</h4>`;

        const grid = document.createElement("div");
        grid.className = "fb-features-group-grid";

        groupFeatures.forEach((feature) => {
            const value = values[feature.id_feature] ?? "";
            const wrapper = document.createElement("div");
            wrapper.className = "fb-feature-field";
            wrapper.innerHTML = renderFeatureField(feature, value);
            grid.appendChild(wrapper);
        });

        section.appendChild(grid);
        groupsContainer.appendChild(section);
    });
}

function renderImages(form, images, idVehicle) {
    const container = form.querySelector("#vehicle-images-list");
    container.innerHTML = "";
    if (!images || images.length === 0) {
        container.innerHTML = `<p class="fb-muted">Nessuna immagine caricata.</p>`;
        return;
    }

    images.forEach((img) => {
        const div = document.createElement("div");
        div.className = "fb-upload-thumb";
        div.innerHTML = `
            <img src="${escapeHtml(img.image)}" alt="" loading="lazy">
            <button type="button" class="fb-upload-delete" data-action="delete-image" data-id="${img.id_image}" title="Elimina">&times;</button>
        `;
        container.appendChild(div);
    });
}

function renderDocuments(form, documents, idVehicle) {
    const container = form.querySelector("#vehicle-documents-list");
    container.innerHTML = "";
    if (!documents || documents.length === 0) {
        container.innerHTML = `<li class="fb-muted">Nessun documento caricato.</li>`;
        return;
    }

    documents.forEach((doc) => {
        const li = document.createElement("li");
        li.innerHTML = `
            <a href="${escapeHtml(doc.document)}" target="_blank" rel="noopener">${escapeHtml(doc.document.split("/").pop())}</a>
            <button type="button" class="fb-btn fb-btn-secondary fb-btn-sm" data-action="delete-document" data-id="${doc.id_document}">Elimina</button>
        `;
        container.appendChild(li);
    });
}

function initFormTabs(form) {
    const tabs = form.querySelectorAll(".fb-form-tabs .fb-tab");
    tabs.forEach((tab) => {
        tab.addEventListener("click", () => {
            const panelName = tab.dataset.tab;
            tabs.forEach((t) => {
                t.classList.remove("active");
                t.setAttribute("aria-selected", "false");
            });
            tab.classList.add("active");
            tab.setAttribute("aria-selected", "true");

            form.querySelectorAll(".fb-tab-panel").forEach((p) => {
                p.classList.toggle("active", p.dataset.panel === panelName);
            });
        });
    });
}

function initUploadHandlers(form, idVehicle) {
    form.querySelector('[name="image_upload"]')?.addEventListener("change", async (ev) => {
        if (!idVehicle) {
            await dialog.alert("Salva prima il veicolo per caricare le immagini.", "Attenzione");
            return;
        }
        await uploadFiles(ev.target.files, `${window.FB.baseUrl}api/vehicles/${idVehicle}/images`, "image");
        openVehicleForm(idVehicle);
    });

    form.querySelector('[name="document_upload"]')?.addEventListener("change", async (ev) => {
        if (!idVehicle) {
            await dialog.alert("Salva prima il veicolo per caricare i documenti.", "Attenzione");
            return;
        }
        const description = form.querySelector('[name="document_description"]')?.value || "";
        await uploadFiles(ev.target.files, `${window.FB.baseUrl}api/vehicles/${idVehicle}/documents`, "document", description);
        form.querySelector('[name="document_description"]').value = "";
        openVehicleForm(idVehicle);
    });

    form.addEventListener("click", async (ev) => {
        const btn = ev.target.closest("[data-action=delete-image]");
        if (btn) {
            ev.preventDefault();
            if (!(await dialog.confirm("Eliminare questa immagine?", "Conferma"))) return;
            try {
                await FetchHelper.post(`${window.FB.baseUrl}api/vehicles/images/${btn.dataset.id}/delete`, {});
                openVehicleForm(idVehicle);
            } catch (err) {
                await dialog.error(err);
            }
        }

        const docBtn = ev.target.closest("[data-action=delete-document]");
        if (docBtn) {
            ev.preventDefault();
            if (!(await dialog.confirm("Eliminare questo documento?", "Conferma"))) return;
            try {
                await FetchHelper.post(`${window.FB.baseUrl}api/vehicles/documents/${docBtn.dataset.id}/delete`, {});
                openVehicleForm(idVehicle);
            } catch (err) {
                await dialog.error(err);
            }
        }
    });
}

async function uploadFiles(files, url, fieldName, description = "") {
    for (const file of files) {
        const formData = new FormData();
        formData.append(fieldName, file);
        formData.append(window.FB.csrf.name, window.FB.csrf.value);
        if (description) {
            formData.append("description", description);
        }

        const response = await fetch(url, { method: "POST", body: formData });
        if (!response.ok) {
            const text = await response.text();
            throw new Error(text || "Errore durante il caricamento.");
        }
    }
}

async function submitVehicle(form, idVehicle) {
    const formData = new FormData(form);
    const data = Object.fromEntries(formData.entries());

    // Collect features as a map.
    const features = {};
    formData.forEach((value, key) => {
        const match = key.match(/^features\[(\d+)\]$/);
        if (match) {
            features[match[1]] = value;
        }
    });
    data.features = features;

    const url = idVehicle ? `${window.FB.baseUrl}api/vehicles/${idVehicle}/update` : `${window.FB.baseUrl}api/vehicles`;

    try {
        await FetchHelper.post(url, data);
        form.closest("dialog").close();
        refreshTable();
    } catch (err) {
        await dialog.error(err);
    }
}

function initToolbar() {
    document.querySelector('[data-action="new-vehicle"]')?.addEventListener("click", () => openVehicleForm());
}

function initPrint() {
    new PrintHelper({
        table: "#vehicles-table",
        button: '[data-action="print-vehicles"]',
        counter: "#vehicles-selected-count",
        url: "admin/vehicles/print",
        idField: "id_vehicle",
        dialog,
    });
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", () => {
        initVehiclesTable();
        initToolbar();
        initPrint();
    });
} else {
    initVehiclesTable();
    initToolbar();
    initPrint();
}
