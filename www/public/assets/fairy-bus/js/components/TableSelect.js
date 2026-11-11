/**
 * TableSelect — autocomplete con dropdown a tabella.
 *
 * Come SearchableSelect, ma i risultati sono righe di una tabella
 * formattata con celle HTML (es. codice, nome, giacenza colorata).
 *
 * Uso:
 *   const picker = new TableSelect(selectEl, {
 *       items: [{ value, label, search, data }],   // data = payload della riga
 *       columns: [{ title, width?, align?, render: (item) => html }],
 *       maxRows: 25,                               // default 25
 *       placeholder, emptyText, onChange(value, item)
 *   });
 *   picker.value   // id selezionato (hidden input, propaga select.name)
 *   picker.clear()
 *
 * Il dropdown è position:fixed e largo quanto serve (min = input):
 * non viene tagliato da overflow dei dialog.
 */
export default class TableSelect {
    constructor(select, options = {}) {
        this.name = select.name || "";
        this.placeholder = options.placeholder || "Cerca…";
        this.emptyText = options.emptyText || "— Seleziona —";
        this.maxRows = Number(options.maxRows) > 0 ? Number(options.maxRows) : 25;
        this.columns = options.columns || [{ title: "", render: (item) => item.label }];
        this.onChange = options.onChange || null;
        this.onRenderRow = options.onRenderRow || null; // (tr, item) => void
        // Riordina i risultati: (filteredItems, term) => items[]
        this.sortResults = options.sortResults || null;

        this.select = select;
        this.table = select.dataset.table || "";

        this.items =
            options.items ||
            Array.from(select.options || []).map((opt) => ({
                value: opt.value,
                label: opt.textContent,
                search: (opt.dataset.search || opt.textContent).toLowerCase(),
                data: null,
            }));

        this.container = document.createElement("div");
        this.container.className = "fb-tableselect";
        this.container._tableSelect = this;
        select._tableSelect = this;

        this.hiddenInput = document.createElement("input");
        this.hiddenInput.type = "hidden";
        this.hiddenInput.name = this.name;
        this.hiddenInput.value = select.value || "";
        Object.assign(this.hiddenInput.dataset, select.dataset);

        this.input = document.createElement("input");
        this.input.type = "text";
        this.input.className = "fb-searchable-select-input fb-tableselect-input";
        this.input.placeholder = this.placeholder;
        this.input.autocomplete = "off";

        this.dropdown = document.createElement("div");
        this.dropdown.className = "fb-tableselect-dropdown";

        this.container.appendChild(this.hiddenInput);
        this.container.appendChild(this.input);
        this.container.appendChild(this.dropdown);

        this._syncInputFromValue();
        this._bindEvents();

        this._reposition = () => {
            if (!this.input.isConnected) {
                // Componente rimosso dal DOM (es. dialog chiuso): libera i listener.
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

        // Select gestita (data-table): avvolgi con il bottone "+" che
        // apre il modale di creazione valore di TableSelectManager.
        if (this.table) {
            const wrap = document.createElement("div");
            wrap.className = "fb-table-select";
            wrap._tableSelect = this;

            const field = document.createElement("div");
            field.className = "fb-table-select-field";
            field.appendChild(this.container);

            const addBtn = document.createElement("button");
            addBtn.type = "button";
            addBtn.className = "fb-table-select-add";
            addBtn.title = "Aggiungi nuovo valore";
            addBtn.innerHTML = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>';

            wrap.appendChild(field);
            wrap.appendChild(addBtn);
            select.replaceWith(wrap);
        } else {
            select.replaceWith(this.container);
        }
        select.remove();
    }

    get value() {
        return this.hiddenInput.value;
    }

    /** Contratto TableSelectManager: sostituisce le opzioni (lookup {id, name}). */
    populate(items) {
        this.items = (items || []).map((it) => ({
            value: String(it.value ?? it.id ?? ""),
            label: String(it.label ?? it.name ?? ""),
            search: String(it.label ?? it.name ?? "").toLowerCase(),
            data: it,
        }));
        this._syncInputFromValue();
    }

    /** Sostituisce gli item mantenendo value/label/search/data custom (es. lotti). */
    setItems(items) {
        this.items = items || [];
        this._syncInputFromValue();
    }

    /** Contratto TableSelectManager: seleziona un valore (es. dopo POST lookup). */
    setValue(value) {
        this.hiddenInput.value = String(value ?? "");
        this._syncInputFromValue();
    }

    /** Svuota la selezione (es. dopo un inserimento riuscito). */
    clear() {
        this.hiddenInput.value = "";
        this._syncInputFromValue();
    }

    _syncInputFromValue() {
        const selected = this.items.find((i) => i.value === this.hiddenInput.value);
        this.input.value = selected ? selected.label : "";
    }

    _open() {
        // Apre e svuota: il testo corrente non deve ostacolare la ricerca.
        this.input.value = "";
        this._render(this._sort(this.items, ""));
    }

    _sort(items, term) {
        return typeof this.sortResults === "function" ? this.sortResults(items, term) : items;
    }

    _close() {
        this.dropdown.classList.remove("open");
        this._syncInputFromValue();
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
        const filtered = term === "" ? this.items : this.items.filter((i) => i.search.includes(term));
        this._render(this._sort(filtered, term), term !== "");
    }

    /**
     * Posiziona il dropdown: largo quanto serve (min = larghezza input),
     * clampato dentro la viewport e aperto verso l'alto se sotto non c'è spazio.
     */
    _positionDropdown() {
        const rect = this.input.getBoundingClientRect();
        const spaceBelow = window.innerHeight - rect.bottom;
        const spaceAbove = rect.top;
        const up = spaceBelow < 280 && spaceAbove > spaceBelow;
        const margin = 8;

        // Altezza limitata allo spazio realmente disponibile (max 24rem):
        // il dropdown non esce mai dalla viewport
        const available = (up ? spaceAbove : spaceBelow) - margin;
        this.dropdown.style.maxHeight = `${Math.max(120, Math.min(384, available))}px`;

        this.dropdown.style.minWidth = `${rect.width}px`;
        this.dropdown.style.maxWidth = `${window.innerWidth - margin * 2}px`;
        this.dropdown.style.top = up ? "auto" : `${rect.bottom + 4}px`;
        this.dropdown.style.bottom = up ? `${window.innerHeight - rect.top + 4}px` : "auto";
        this.dropdown.classList.toggle("fb-tableselect-up", up);

        // Clamp orizzontale: misura la larghezza reale e arretra se esce a destra
        this.dropdown.style.left = `${rect.left}px`;
        const ddWidth = this.dropdown.offsetWidth;
        if (rect.left + ddWidth > window.innerWidth - margin) {
            this.dropdown.style.left = `${Math.max(margin, window.innerWidth - margin - ddWidth)}px`;
        }
    }

    _render(items, filtered = false) {
        this._positionDropdown();
        this.dropdown.innerHTML = "";

        const shown = items.slice(0, this.maxRows);
        const table = document.createElement("table");
        table.className = "fb-tableselect-table";

        const thead = document.createElement("thead");
        const headTr = document.createElement("tr");
        for (const col of this.columns) {
            const th = document.createElement("th");
            th.innerHTML = col.title ?? "";
            if (col.width) {
                th.style.width = col.width;
            }
            if (col.align) {
                th.style.textAlign = col.align;
            }
            headTr.appendChild(th);
        }
        thead.appendChild(headTr);
        table.appendChild(thead);

        const tbody = document.createElement("tbody");

        // Riga "vuota" per deselezionare
        const emptyTr = document.createElement("tr");
        emptyTr.className = "fb-tableselect-row fb-tableselect-empty";
        emptyTr.dataset.value = "";
        const emptyTd = document.createElement("td");
        emptyTd.colSpan = this.columns.length;
        emptyTd.textContent = this.emptyText;
        emptyTr.appendChild(emptyTd);
        emptyTr.addEventListener("click", () => this._select(""));
        tbody.appendChild(emptyTr);

        for (const item of shown) {
            const tr = document.createElement("tr");
            tr.className = "fb-tableselect-row";
            tr.dataset.value = item.value;
            if (this.hiddenInput.value === item.value) {
                tr.classList.add("selected");
            }
            for (const col of this.columns) {
                const td = document.createElement("td");
                td.innerHTML = col.render ? col.render(item) : item.label;
                if (col.align) {
                    td.style.textAlign = col.align;
                }
                tr.appendChild(td);
            }
            if (typeof this.onRenderRow === "function") {
                this.onRenderRow(tr, item);
            }
            tr.addEventListener("click", () => this._select(item.value, item));
            tbody.appendChild(tr);
        }

        if (items.length === 0) {
            const tr = document.createElement("tr");
            const td = document.createElement("td");
            td.colSpan = this.columns.length;
            td.className = "fb-tableselect-noresults";
            td.textContent = "Nessun risultato";
            tr.appendChild(td);
            tbody.appendChild(tr);
        } else if (items.length > shown.length) {
            const tr = document.createElement("tr");
            const td = document.createElement("td");
            td.colSpan = this.columns.length;
            td.className = "fb-tableselect-noresults";
            td.textContent = `Altri ${items.length - shown.length} risultati: digita per restringere…`;
            tr.appendChild(td);
            tbody.appendChild(tr);
        }

        table.appendChild(tbody);
        this.dropdown.appendChild(table);
        this.dropdown.classList.add("open");

        // Se filtrato, il dropdown può essersi ristretto: riposiziona
        if (filtered) {
            this._positionDropdown();
        }
    }

    _select(value, item = null) {
        this.hiddenInput.value = value;
        const selected = item ?? this.items.find((i) => i.value === value) ?? null;
        this.input.value = selected ? selected.label : "";
        this._close();
        if (typeof this.onChange === "function") {
            this.onChange(value, selected);
        }
    }

    _onKeyDown(ev) {
        const rows = Array.from(this.dropdown.querySelectorAll(".fb-tableselect-row"));
        if (rows.length === 0) {
            return;
        }
        const active = this.dropdown.querySelector(".fb-tableselect-row.active");
        let index = active ? rows.indexOf(active) : -1;

        if (ev.key === "ArrowDown") {
            ev.preventDefault();
            index = Math.min(index + 1, rows.length - 1);
        } else if (ev.key === "ArrowUp") {
            ev.preventDefault();
            index = Math.max(index - 1, 0);
        } else if (ev.key === "Enter") {
            ev.preventDefault();
            if (active) {
                this._select(active.dataset.value);
            }
            return;
        } else if (ev.key === "Escape") {
            ev.preventDefault();
            this._close();
            return;
        } else {
            return;
        }

        rows.forEach((r) => r.classList.remove("active"));
        rows[index].classList.add("active");
        rows[index].scrollIntoView({ block: "nearest" });
    }
}
