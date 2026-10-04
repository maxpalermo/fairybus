import FetchHelper from "../core/FetchHelper.js";
import DialogHelper from "../core/DialogHelper.js";

window.fbFeatureTypeFilterOptions = { switch: "Interruttore", select: "Select", value: "Valore" };

const dialog = new DialogHelper();

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

    window.$("#features-table").bootstrapTable({
        url: window.FB.baseUrl + "api/features",
        sidePagination: "client",
        pagination: true,
        search: false,
        filterControl: true,
        sortable: true,
        locale: "it-IT",
        columns: [
            { field: "state", checkbox: true, align: "center", valign: "middle" },
            { field: "id_feature", title: "#", sortable: true, width: 60 , filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "name", title: "Nome", sortable: true , filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "label", title: "Etichetta", sortable: true , filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "type", title: "Tipo", sortable: true, formatter: formatType , searchFormatter: false, filterControl: "select", filterData: "var:fbFeatureTypeFilterOptions" },
        ],
    });
}

function formatType(value) {
    const labels = {
        switch: "Interruttore",
        select: "Select",
        value: "Valore",
    };
    return `<span class="fb-badge">${escapeHtml(labels[value] ?? value)}</span>`;
}

async function loadConfigurations() {
    const container = document.getElementById("features-config-forms");
    if (!container) {
        return;
    }

    try {
        const res = await FetchHelper.get(`${window.FB.baseUrl}api/features/configurations`);
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
                    await FetchHelper.post(`${window.FB.baseUrl}api/features/configurations/${encodeURIComponent(name)}`, { value });
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

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", () => {
        initFeaturesTable();
        loadConfigurations();
    });
} else {
    initFeaturesTable();
    loadConfigurations();
}
