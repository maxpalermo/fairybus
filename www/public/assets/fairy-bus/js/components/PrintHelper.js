import DialogHelper from "../core/DialogHelper.js";

/**
 * Gestione stampa PDF delle righe selezionate in una Bootstrap Table.
 * Uso:
 *   new PrintHelper({
 *       table: "#stocks-table",
 *       button: '[data-action="print-stocks"]',
 *       counter: "#stocks-selected-count",
 *       url: "admin/stocks/print",
 *       idField: "id_stock",
 *   });
 */
export default class PrintHelper {
    static MAX_ROWS = 500;

    /**
     * @param {object} options
     * @param {string} options.table   - selettore CSS della Bootstrap Table
     * @param {string} options.button  - selettore CSS del bottone di stampa
     * @param {string} options.url     - endpoint POST che riceve `ids` e risponde col PDF
     * @param {string} options.idField - campo ID nella riga (chiave primaria)
     * @param {string} [options.counter] - selettore CSS del contatore "N selezionati" (opzionale)
     * @param {number} [options.maxRows] - numero massimo di righe stampabili (default 500)
     * @param {object} [options.extraParams] - parametri POST aggiuntivi inviati col form di stampa
     * @param {DialogHelper} [options.dialog]
     */
    constructor(options) {
        this.tableSelector = options.table;
        this.url = options.url;
        this.idField = options.idField;
        this.counterSelector = options.counter || null;
        this.maxRows = options.maxRows ?? PrintHelper.MAX_ROWS;
        this.extraParams = options.extraParams || {};
        this.dialog = options.dialog ?? new DialogHelper();

        this.bindCounter();
        this.bindButton(options.button);
    }

    getTable() {
        return window.$ ? window.$(this.tableSelector) : null;
    }

    getSelectedRows() {
        const $table = this.getTable();
        if (!$table || typeof $table.bootstrapTable !== "function") {
            return [];
        }
        return $table.bootstrapTable("getSelections");
    }

    updateCounter() {
        if (!this.counterSelector) {
            return;
        }
        const el = document.querySelector(this.counterSelector);
        if (!el) {
            return;
        }
        const n = this.getSelectedRows().length;
        el.textContent = `${n} selezionat${n === 1 ? "a" : "i"}`;
    }

    bindCounter() {
        const $table = this.getTable();
        if (!$table || typeof $table.on !== "function") {
            return;
        }
        const events = ["check.bs.table", "uncheck.bs.table", "check-all.bs.table", "uncheck-all.bs.table", "load-success.bs.table"].join(" ");
        $table.on(events, () => this.updateCounter());
    }

    bindButton(selector) {
        if (!selector) {
            return;
        }
        document.querySelector(selector)?.addEventListener("click", () => this.print());
    }

    async print() {
        const rows = this.getSelectedRows();
        if (rows.length === 0) {
            await this.dialog.alert("Seleziona almeno una riga da stampare.", "Attenzione");
            return;
        }
        if (rows.length > this.maxRows) {
            await this.dialog.alert(`Puoi stampare al massimo ${this.maxRows} righe alla volta (selezionate: ${rows.length}).`, "Attenzione");
            return;
        }

        const ids = rows.map((r) => Number(r[this.idField])).filter((i) => i > 0);

        // Submit via form nascosto in nuova scheda: niente popup-blocker, cookie di sessione incluso
        const form = document.createElement("form");
        form.method = "POST";
        form.action = `${window.FB.baseUrl}${this.url}`;
        form.target = "_blank";
        form.style.display = "none";

        const input = document.createElement("input");
        input.type = "hidden";
        input.name = "ids";
        input.value = JSON.stringify(ids);
        form.appendChild(input);

        for (const [name, value] of Object.entries(this.extraParams)) {
            const extra = document.createElement("input");
            extra.type = "hidden";
            extra.name = name;
            extra.value = String(value);
            form.appendChild(extra);
        }

        document.body.appendChild(form);
        form.submit();
        form.remove();
    }
}
