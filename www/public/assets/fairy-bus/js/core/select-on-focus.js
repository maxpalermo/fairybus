/**
 * Copyright since 2026 Massimiliano Palermo
 * @license AFL-3.0
 *
 * Select-all on focus: al focus ogni campo di testo/numero seleziona
 * automaticamente tutto il contenuto. Delegato su `focusin` per coprire
 * anche gli input creati dinamicamente (dialog, form nei template).
 */
document.addEventListener("focusin", (ev) => {
    const el = ev.target;
    const isInput = el instanceof HTMLInputElement;
    const isArea = el instanceof HTMLTextAreaElement;
    if (!isInput && !isArea) {
        return;
    }
    const selectableTypes = ["", "text", "search", "email", "url", "tel", "password", "number"];
    if (isInput && !selectableTypes.includes(el.type)) {
        return;
    }
    try {
        el.select();
    } catch (err) {
        // alcuni tipi di input non supportano la selezione
    }
});
