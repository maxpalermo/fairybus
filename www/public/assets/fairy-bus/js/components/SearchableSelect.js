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
                // data-search opzionale: testo extra per la ricerca (es. codici alias)
                search: (opt.dataset.search || opt.textContent).trim().toLowerCase(),
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

        this._reposition = () => {
            if (!this.input.isConnected) {
                // Il componente è stato rimosso dal DOM (es. dialog chiuso):
                // libera i listener per non trattenere l'istanza.
                document.removeEventListener("scroll", this._reposition, true);
                window.removeEventListener("resize", this._reposition);
                return;
            }
            if (this.dropdown.classList.contains("open")) {
                this._positionDropdown();
            }
        };
        // capture=true intercetta anche lo scroll dei contenitori (es. <dialog>)
        document.addEventListener("scroll", this._reposition, true);
        window.addEventListener("resize", this._reposition);

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

    get value() {
        return this.hiddenInput.value;
    }

    /** Svuota la selezione (es. dopo un inserimento riuscito). */
    clear() {
        this.hiddenInput.value = "";
        this._syncInputFromValue();
    }

    _filter() {
        const term = this.input.value.toLowerCase().trim();
        const filtered = term === "" ? this.items : this.items.filter((i) => i.search.includes(term));
        this._renderDropdown(filtered, term !== "");
    }

    /**
     * Posiziona il dropdown sulle coordinate viewport dell'input:
     * position:fixed non viene mai tagliato dall'overflow degli antenati
     * (è il fix per i <dialog> nativi). Apre verso l'alto se sotto non c'è spazio.
     */
    _positionDropdown() {
        const rect = this.input.getBoundingClientRect();
        const spaceBelow = window.innerHeight - rect.bottom;
        const spaceAbove = rect.top;
        const up = spaceBelow < 260 && spaceAbove > spaceBelow;

        this.dropdown.style.left = `${rect.left}px`;
        this.dropdown.style.width = `${rect.width}px`;
        this.dropdown.style.top = up ? "auto" : `${rect.bottom + 4}px`;
        this.dropdown.style.bottom = up ? `${window.innerHeight - rect.top + 4}px` : "auto";
        this.dropdown.classList.toggle("fb-searchable-select-up", up);
    }

    _renderDropdown(items, showAll = false) {
        this._positionDropdown();
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

            const MAX_ITEMS = 400;
            items.slice(0, MAX_ITEMS).forEach((item) => {
                const li = document.createElement("li");
                li.className = "fb-searchable-select-item";
                li.textContent = item.label;
                if (this.hiddenInput.value === item.value) {
                    li.classList.add("selected");
                }
                li.addEventListener("click", () => this._select(item.value));
                this.dropdown.appendChild(li);
            });
            if (items.length > MAX_ITEMS) {
                const moreLi = document.createElement("li");
                moreLi.className = "fb-searchable-select-no-results";
                moreLi.textContent = `${items.length - MAX_ITEMS} altri risultati: digita per restringere…`;
                this.dropdown.appendChild(moreLi);
            }
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
