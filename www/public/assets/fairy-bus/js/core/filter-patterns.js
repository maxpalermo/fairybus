/**
 * Copyright since 2026 Massimiliano Palermo
 * @license AFL-3.0
 *
 * Pattern di ricerca per i filtri "input" di Bootstrap Table (filter-control).
 * Assegnato come filterCustomSearch: window.fbFilterSearch sulle colonne.
 *
 * Pattern supportati (prefisso + valore):
 *   <valore>        contiene il valore (default, case-insensitive)
 *   %><valore>      inizia con il valore
 *   %/<valore>      finisce con il valore
 *   =<valore>       esattamente uguale (numeri confrontati numericamente)
 *   ><valore>       maggiore di (numeri e date)
 *   <<valore>       minore di (numeri e date)
 *   <><valore>      diverso dal valore
 *   <-><v1>,<v2>    compreso tra v1 e v2 (inclusi; numeri e date)
 */
(function () {
    "use strict";

    // numero (1.234,56 / 1234.56, anche con simboli o unità: "€ 60,00", "60,00 l",
    // "336.135 km") oppure data (gg/mm/aaaa, aaaa-mm-gg) -> numero
    function toNum(x) {
        const s = String(x ?? "").trim();
        if (!s) return NaN;
        const it = s.match(/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{2,4})/);
        if (it) return new Date(+it[3], it[2] - 1, +it[1]).getTime();
        const iso = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
        if (iso) return new Date(iso[0]).getTime();
        const num =
            s
                .replace(/[^\d.,+-]/g, " ")
                .trim()
                .split(/\s+/)[0] || "";
        if (/^[+-]?\d{1,3}(\.\d{3})+(,\d+)?$/.test(num)) return parseFloat(num.replace(/\./g, "").replace(",", "."));
        if (/^[+-]?\d+([.,]\d+)?$/.test(num)) return parseFloat(num.replace(",", "."));
        return NaN;
    }

    window.fbFilterSearch = function (text, value) {
        const raw = String(text ?? "").trim();
        const str = String(value ?? "")
            .trim()
            .toLowerCase();
        const cellNum = toNum(value);
        const argNum = (s) => toNum(s.trim());

        if (raw.startsWith("<->")) {
            const [a, b] = raw.slice(3).split(",");
            const lo = argNum(a);
            const hi = argNum(b);
            return !isNaN(cellNum) && !isNaN(lo) && !isNaN(hi) && cellNum >= lo && cellNum <= hi;
        }
        if (raw.startsWith("%>")) return str.startsWith(raw.slice(2).trim().toLowerCase());
        if (raw.startsWith("%/")) return str.endsWith(raw.slice(2).trim().toLowerCase());
        if (raw.startsWith("<>")) {
            const q = raw.slice(2).trim();
            const qn = argNum(q);
            return !isNaN(cellNum) && !isNaN(qn) ? cellNum !== qn : str !== q.toLowerCase();
        }
        if (raw.startsWith("=")) {
            const q = raw.slice(1).trim();
            const qn = argNum(q);
            return !isNaN(cellNum) && !isNaN(qn) ? cellNum === qn : str === q.toLowerCase();
        }
        if (raw.startsWith(">")) {
            const qn = argNum(raw.slice(1));
            return !isNaN(cellNum) && !isNaN(qn) && cellNum > qn;
        }
        if (raw.startsWith("<")) {
            const qn = argNum(raw.slice(1));
            return !isNaN(cellNum) && !isNaN(qn) && cellNum < qn;
        }
        return str.includes(raw.toLowerCase());
    };
})();
