/**
 * Copyright since 2026 Massimiliano Palermo
 * @license AFL-3.0
 *
 * Toggle istantaneo per campi booleani in tabelle fb_*.
 *
 *   const newVal = await window.toggleTrueFalse('fb_supplier', 'fuel', 216);
 *
 * POST api/toggle-field -> { success, value } con il nuovo valore (0/1).
 * Lato server la coppia tabella/campo deve essere in whitelist
 * (Api\Toggle::ALLOWED). Solleva l'errore FetchHelper in caso di fallimento.
 */
import FetchHelper from "./FetchHelper.js";

window.toggleTrueFalse = async function (table, field, id) {
    const res = await FetchHelper.post(`${window.FB.baseUrl}api/toggle-field`, {
        table,
        field,
        id,
    });
    return Number(res.value) === 1 ? 1 : 0;
};
