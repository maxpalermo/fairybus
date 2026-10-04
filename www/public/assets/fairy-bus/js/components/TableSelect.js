/**
 * Componente select "creabile": una <select data-table="tipo"> con ricerca
 * (Chosen) e un pulsante "+" affiancato per aggiungere un nuovo valore
 * alla tabella di riferimento.
 *
 * Il popolamento e gli eventi sono gestiti da TableSelectManager.
 */
export default class TableSelect {
    constructor(select, manager) {
        this.select = select;
        this.manager = manager;
        this.table = select.dataset.table;
        this.pendingValue = select.value || null;
        this.placeholder = this.getPlaceholder();

        this.wrap();
        this.initChosen();

        select._tableSelect = this;
    }

    getPlaceholder() {
        const first = this.select.querySelector('option[value=""]');
        return first ? first.textContent : "—";
    }

    wrap() {
        const container = document.createElement("div");
        container.className = "fb-table-select";
        container.dataset.table = this.table;
        container._tableSelect = this;

        this.select.parentNode.insertBefore(container, this.select);
        container.appendChild(this.select);
        this.select.classList.add("fb-table-select-field");

        this.addBtn = document.createElement("button");
        this.addBtn.type = "button";
        this.addBtn.className = "fb-table-select-add";
        this.addBtn.title = "Aggiungi nuovo valore";
        this.addBtn.innerHTML = `
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 5v14M5 12h14"></path>
            </svg>`;
        container.appendChild(this.addBtn);

        this.container = container;
    }

    initChosen() {
        if (window.$ && window.$.fn && window.$.fn.chosen) {
            window.$(this.select).chosen({
                width: "100%",
                disable_search_threshold: 3,
                no_results_text: "Nessun risultato per",
                placeholder_text_single: this.placeholder,
            });
        }
    }

    refreshChosen() {
        if (window.$ && window.$.fn && window.$.fn.chosen) {
            window.$(this.select).trigger("chosen:updated");
        }
    }

    populate(items) {
        const keepValue = this.pendingValue !== null ? this.pendingValue : this.select.value;

        this.select.innerHTML = "";
        const empty = document.createElement("option");
        empty.value = "";
        empty.textContent = this.placeholder;
        this.select.appendChild(empty);

        (items || []).forEach((item) => {
            const opt = document.createElement("option");
            opt.value = item.id;
            opt.textContent = item.name;
            this.select.appendChild(opt);
        });

        if (keepValue && this.select.querySelector(`option[value="${CSS.escape(String(keepValue))}"]`)) {
            this.select.value = keepValue;
        }
        this.pendingValue = null;

        this.refreshChosen();
    }

    setValue(value) {
        this.pendingValue = value !== null && value !== undefined ? String(value) : null;
        if (value === null || value === undefined || value === "") {
            this.select.value = "";
        } else {
            this.select.value = String(value);
        }
        this.refreshChosen();
    }

    getValue() {
        return this.select.value;
    }
}
