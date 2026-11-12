/**
 * Formattazione valuta unificata per tutta l'app:
 * separatore migliaia = spazio, decimali con virgola, suffisso "EUR".
 * Usare queste funzioni ovunque si mostri un importo — niente copie locali.
 */
const formatEur = (value, decimals) =>
    `${Number(value || 0)
        // useGrouping "always": it-IT usa "min2" (nessun separatore sotto 10.000)
        .toLocaleString("it-IT", { minimumFractionDigits: decimals, maximumFractionDigits: decimals, useGrouping: "always" })
        .replace(/\./g, " ")} EUR`;

/** Standard: 1 894,78 EUR */
export function fmtMoney(value) {
    return formatEur(value, 2);
}

/** Unitari di precisione (prezzo al litro, prezzi d'acquisto): 1,4590 EUR */
export function fmtMoney4(value) {
    return formatEur(value, 4);
}
