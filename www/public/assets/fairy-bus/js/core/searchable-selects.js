/**
 * Copyright since 2026 Massimiliano Palermo
 * @license AFL-3.0
 *
 * Converte automaticamente le <select class="fb-form-input"> native in
 * SearchableSelect (input di ricerca + dropdown). La select originale
 * resta nel DOM nascosta dentro il componente, quindi il codice delle
 * pagine (populate via innerHTML, assegnazioni .value, listener su
 * 'change'/'input', FormData) continua a funzionare senza modifiche.
 *
 * Escluse dalla conversione:
 *   select[multiple]        (es. ruoli utente)
 *   select[data-table]      (gestite da TableSelect/TableSelectManager)
 *   select[data-no-search]  (opt-out esplicito)
 */
import SearchableSelect from "../components/SearchableSelect.js?v=5";

const EXCLUDE = "select[multiple], select[data-table], select[data-no-search]";

function enhance(root) {
    const scope = root instanceof Element ? root : document;
    const selects = [];
    if (scope instanceof HTMLSelectElement && scope.matches("select.fb-form-input")) {
        selects.push(scope);
    }
    scope.querySelectorAll?.("select.fb-form-input").forEach((sel) => selects.push(sel));
    selects.forEach((sel) => {
        if (sel.matches(EXCLUDE) || sel._fbSearchableSelect || sel._tableSelect) {
            return;
        }
        new SearchableSelect(sel, {});
    });
}

function init() {
    enhance(document);
    new MutationObserver((mutations) => {
        for (const m of mutations) {
            m.addedNodes.forEach((node) => {
                if (node instanceof Element) {
                    enhance(node);
                }
            });
        }
    }).observe(document.body, { childList: true, subtree: true });
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
} else {
    init();
}
