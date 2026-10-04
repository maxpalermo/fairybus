/**
 * Copyright since 2026 Massimiliano Palermo
 * @license AFL-3.0
 *
 * Lightweight searchable single-select that works inside native <dialog>.
 * Renders a text input + dropdown list; stores the selected value in a hidden input.
 */
export default class SearchableSelect {
    /**
     * @param {HTMLSelectElement} select - original select element
     * @param {Object} options
     * @param {string} options.placeholder
     * @param {string} options.emptyText
     * @param {string} options.searchPlaceholder
     */
    constructor(select, options = {}) {
        this.original = select;
        this.name = select.name;
        this.items = [];
        let emptyLabel = null;
        for (const opt of select.options) {
            if (opt.value === "") {
                // l'opzione vuota (— Seleziona — / — Nessuno —) diventa la
                // voce "vuota" del dropdown, non un valore selezionabile
                if (emptyLabel === null) {
                    emptyLabel = opt.textContent.trim() || "—";
                }
                continue;
            }
            this.items.push({
                value: opt.value,
                label: opt.textContent.trim(),
            });
        }
        this.placeholder = options.placeholder || "Cerca...";
        this.emptyText = options.emptyText || emptyLabel || "—";
        this.searchPlaceholder = options.searchPlaceholder || "Digita per cercare...";
        this.onChange = options.onChange || null;

        this.container = document.createElement("div");
        this.container.className = "fb-searchable-select";

        this.hiddenInput = document.createElement("input");
        this.hiddenInput.type = "hidden";
        this.hiddenInput.name = this.name;
        this.hiddenInput.value = select.value;

        this.input = document.createElement("input");
        this.input.type = "text";
        this.input.className = "fb-searchable-select-input";
        this.input.placeholder = this.placeholder;
        this.input.autocomplete = "off";

        this.dropdown = document.createElement("ul");
        this.dropdown.className = "fb-searchable-select-dropdown";

        this.container.appendChild(this.hiddenInput);
        this.container.appendChild(this.input);
        this.container.appendChild(this.dropdown);

        this._syncInputFromValue();
        this._bindEvents();

        select.replaceWith(this.container);
        select.remove();
    }

    _syncInputFromValue() {
        const selected = this.items.find((i) => i.value === this.hiddenInput.value);
        this.input.value = selected ? selected.label : "";
    }

    /**
     * Apre il dropdown e svuota l'input: il testo del valore corrente
     * (o l'etichetta vuota) non deve ostacolare la ricerca.
     */
    _open() {
        this.input.value = "";
        this._renderDropdown(this.items);
    }

    _bindEvents() {
        this.input.addEventListener("focus", () => this._open());
        this.input.addEventListener("input", () => this._filter());
        this.input.addEventListener("keydown", (ev) => this._onKeyDown(ev));

        this.input.addEventListener("click", (ev) => {
            ev.stopPropagation();
            this._open();
        });

        document.addEventListener("click", (ev) => {
            if (!this.container.contains(ev.target)) {
                this._close();
            }
        });
    }

    _filter() {
        const term = this.input.value.toLowerCase().trim();
        const filtered = term === "" ? this.items : this.items.filter((i) => i.label.toLowerCase().includes(term));
        this._renderDropdown(filtered, term !== "");
    }

    _renderDropdown(items, showAll = false) {
        this.dropdown.innerHTML = "";

        if (items.length === 0) {
            const li = document.createElement("li");
            li.className = "fb-searchable-select-no-results";
            li.textContent = "Nessun risultato";
            this.dropdown.appendChild(li);
        } else {
            // Empty option at top
            const emptyLi = document.createElement("li");
            emptyLi.className = "fb-searchable-select-item fb-searchable-select-empty";
            emptyLi.textContent = this.emptyText;
            emptyLi.addEventListener("click", () => this._select(""));
            this.dropdown.appendChild(emptyLi);

            items.forEach((item) => {
                const li = document.createElement("li");
                li.className = "fb-searchable-select-item";
                li.textContent = item.label;
                if (this.hiddenInput.value === item.value) {
                    li.classList.add("selected");
                }
                li.addEventListener("click", () => this._select(item.value));
                this.dropdown.appendChild(li);
            });
        }

        this.dropdown.classList.add("open");
    }

    _select(value) {
        this.hiddenInput.value = value;
        this._syncInputFromValue();
        this._close();
        if (typeof this.onChange === "function") {
            this.onChange(value);
        }
    }

    _onKeyDown(ev) {
        const visibleItems = Array.from(this.dropdown.querySelectorAll(".fb-searchable-select-item"));
        if (visibleItems.length === 0) return;

        const active = this.dropdown.querySelector(".fb-searchable-select-item.active");
        let index = active ? visibleItems.indexOf(active) : -1;

        if (ev.key === "ArrowDown") {
            ev.preventDefault();
            index = Math.min(index + 1, visibleItems.length - 1);
            this._highlight(visibleItems, index);
        } else if (ev.key === "ArrowUp") {
            ev.preventDefault();
            index = Math.max(index - 1, 0);
            this._highlight(visibleItems, index);
        } else if (ev.key === "Enter") {
            ev.preventDefault();
            if (active) {
                active.click();
            }
        } else if (ev.key === "Escape") {
            this._close();
        }
    }

    _highlight(items, index) {
        items.forEach((el) => el.classList.remove("active"));
        const target = items[index];
        if (target) {
            target.classList.add("active");
            target.scrollIntoView({ block: "nearest" });
        }
    }

    _close() {
        // chiusura senza scelta: ripristina il label del valore corrente
        this._syncInputFromValue();
        this.dropdown.classList.remove("open");
    }
}
