import FetchHelper from "../core/FetchHelper.js";
import TableSelect from "./TableSelect.js";

/**
 * Gestore globale dei componenti TableSelect.
 *
 * - init(root): trova tutte le select[data-table] nel root, le avvolge nel
 *   componente (Chosen + pulsante "+") e le popola dalla tabella indicata.
 * - Click su ".fb-table-select-add": apre il modale di inserimento valore.
 * - Salvataggio: POST /api/lookup/{table} con il nome; poi refresh di tutte
 *   le select della stessa tabella e selezione del nuovo valore in quella
 *   che ha aperto il modale.
 */
class TableSelectManager {
    constructor() {
        this.components = new Set();
        this.optionCache = new Map();
        this.pendingLoads = new Map();
        this.modal = null;
        this.initDelegated();
    }

    init(root = document) {
        root.querySelectorAll("select[data-table]").forEach((select) => {
            if (!select._tableSelect) {
                this.components.add(new TableSelect(select, this));
            }
            this.loadOptions(select.dataset.table);
        });
    }

    componentsFor(table) {
        return [...this.components].filter((c) => c.table === table && (c.container || c.select).isConnected);
    }

    async loadOptions(table) {
        try {
            const items = await this.fetchItems(table);
            this.componentsFor(table).forEach((c) => c.populate(items));
        } catch (err) {
            console.error(`TableSelectManager: errore caricamento opzioni "${table}"`, err);
        }
    }

    fetchItems(table) {
        if (!this.pendingLoads.has(table)) {
            this.pendingLoads.set(
                table,
                FetchHelper.get(`${window.FB.baseUrl}api/lookup/${table}`)
                    .then((res) => {
                        const items = res.items || [];
                        this.optionCache.set(table, items);
                        return items;
                    })
                    .finally(() => this.pendingLoads.delete(table)),
            );
        }
        return this.pendingLoads.get(table);
    }

    async refresh(table) {
        this.optionCache.delete(table);
        await this.loadOptions(table);
    }

    initDelegated() {
        document.addEventListener("click", (ev) => {
            const btn = ev.target.closest(".fb-table-select-add");
            if (!btn) {
                return;
            }
            const container = btn.closest(".fb-table-select");
            const component = container ? container._tableSelect : null;
            if (component) {
                ev.preventDefault();
                this.openModal(component);
            }
        });
    }

    openModal(component) {
        if (this.modal) {
            this.modal.remove();
            this.modal = null;
        }

        const overlay = document.createElement("dialog");
        overlay.className = "fb-ts-modal-overlay";
        overlay.innerHTML = `
            <div class="fb-ts-modal" role="dialog" aria-modal="true">
                <div class="fb-ts-modal-header">
                    <h3 class="fb-ts-modal-title">Nuovo valore — ${escapeHtml(component.table)}</h3>
                    <button type="button" class="fb-ts-modal-close" data-ts="close" aria-label="Chiudi">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"></path></svg>
                    </button>
                </div>
                <div class="fb-ts-modal-body">
                    <label class="fb-ts-modal-label" for="fb-ts-input">Nome</label>
                    <input type="text" id="fb-ts-input" class="fb-form-input fb-ts-input" placeholder="Inserisci il nome" autocomplete="off">
                    <div class="fb-ts-modal-error" hidden></div>
                </div>
                <div class="fb-ts-modal-footer">
                    <button type="button" class="fb-btn fb-btn-primary" data-ts="save">Salva</button>
                    <button type="button" class="fb-btn fb-btn-secondary" data-ts="close">Annulla</button>
                </div>
            </div>`;

        document.body.appendChild(overlay);
        overlay.showModal();
        this.modal = overlay;

        const input = overlay.querySelector("#fb-ts-input");
        const errorEl = overlay.querySelector(".fb-ts-modal-error");
        const saveBtn = overlay.querySelector('[data-ts="save"]');
        setTimeout(() => input.focus(), 30);

        const close = () => {
            if (overlay.open) {
                overlay.close();
            }
        };

        overlay.addEventListener(
            "close",
            () => {
                overlay.remove();
                if (this.modal === overlay) {
                    this.modal = null;
                }
            },
            { once: true },
        );

        overlay.addEventListener("click", (ev) => {
            if (ev.target === overlay) {
                close();
            }
        });
        overlay.querySelectorAll('[data-ts="close"]').forEach((b) => b.addEventListener("click", close));

        const save = async () => {
            const name = input.value.trim();
            if (!name) {
                errorEl.textContent = "Il nome è obbligatorio.";
                errorEl.hidden = false;
                input.focus();
                return;
            }

            saveBtn.disabled = true;
            saveBtn.textContent = "Salvataggio...";
            errorEl.hidden = true;

            try {
                const res = await FetchHelper.post(`${window.FB.baseUrl}api/lookup/${component.table}`, { name });
                if (!res.success) {
                    throw new Error(res.error || "Errore durante il salvataggio.");
                }

                close();
                await this.refresh(component.table);
                component.setValue(res.id);
            } catch (err) {
                errorEl.textContent = err.message;
                errorEl.hidden = false;
            } finally {
                saveBtn.disabled = false;
                saveBtn.textContent = "Salva";
            }
        };

        saveBtn.addEventListener("click", save);
        input.addEventListener("keydown", (ev) => {
            if (ev.key === "Enter") {
                ev.preventDefault();
                save();
            }
        });
    }
}

function escapeHtml(text) {
    const div = document.createElement("div");
    div.textContent = String(text ?? "");
    return div.innerHTML;
}

const tableSelectManager = new TableSelectManager();
export default tableSelectManager;
