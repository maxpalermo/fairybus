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

    /* ---------- Legenda sintassi filtri (auto-iniettata) ----------
     * Ogni bootstrap-table che ha almeno un filtro input riceve un
     * bottone "?" nella toolbar, che apre un dialog con la sintassi.
     * Vale anche per le tabelle create dopo il caricamento (detail view,
     * tab dinamici) grazie al MutationObserver.
     */
    const LEGEND_ROWS = [
        ["testo", "contiene il testo (default)", "mer → MERCEDES"],
        ["%>testo", "inizia con", "%>ab → AB123"],
        ["%/testo", "finisce con", "%/xyz → AA999XYZ"],
        ["=valore", "uguale a", "=5 · =OFFICINA"],
        [">valore", "maggiore di (numeri e date)", ">50 · >01/01/2026"],
        ["<valore", "minore di (numeri e date)", "<10 · <2026-06-01"],
        ["<>valore", "diverso da", "<>0"],
        ["<->a,b", "compreso tra (inclusi)", "<->10,50"],
    ];
    const INPUT_TITLE = "Sintassi filtri: testo · %>inizia · %/finisce · =uguale · >maggiore · <minore · <>diverso · <->a,b tra";

    let legendDialog = null;
    function openFilterLegend() {
        if (!legendDialog) {
            legendDialog = document.createElement("dialog");
            legendDialog.className = "fb-dialog fb-dialog-medium";
            legendDialog.innerHTML = `
                <div class="fb-dialog-header">
                    <h3 class="fb-dialog-title">Sintassi dei filtri di ricerca</h3>
                    <button type="button" class="fb-dialog-close" data-action="close" aria-label="Chiudi">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18M6 6l12 12"></path></svg>
                    </button>
                </div>
                <div class="fb-dialog-body">
                    <p class="fb-muted">Nei campi filtro dell'intestazione puoi usare questi prefissi.
                    Numeri e date si confrontano anche se formattati (€, km, gg/mm/aaaa).</p>
                    <div class="fb-legend-table-wrap">
                        <table class="fb-legend-table">
                            <thead><tr><th>Sintassi</th><th>Significato</th><th>Esempio</th></tr></thead>
                            <tbody>${LEGEND_ROWS.map((r) => `<tr><td><code>${r[0].replace(/</g, "&lt;")}</code></td><td>${r[1]}</td><td class="fb-muted">${r[2].replace(/</g, "&lt;")}</td></tr>`).join("")}
                            </tbody>
                        </table>
                    </div>
                </div>`;
            legendDialog.querySelector('[data-action="close"]').addEventListener("click", () => legendDialog.close());
            document.body.appendChild(legendDialog);
        }
        legendDialog.showModal();
    }

    const HELP_ICON = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>';

    function injectFilterHelp() {
        document.querySelectorAll(".bootstrap-table").forEach((wrap) => {
            if (!wrap.querySelector(".filter-control input")) {
                return;
            }
            wrap.querySelectorAll(".filter-control input").forEach((input) => {
                if (!input.title) {
                    input.title = INPUT_TITLE;
                }
            });
            const toolbar = wrap.querySelector(".fixed-table-toolbar");
            if (toolbar && !toolbar.querySelector(".fb-filter-help-btn")) {
                const btn = document.createElement("button");
                btn.type = "button";
                btn.className = "fb-filter-help-btn";
                btn.title = "Sintassi dei filtri di ricerca";
                btn.innerHTML = HELP_ICON;
                btn.addEventListener("click", openFilterLegend);
                toolbar.appendChild(btn);
            }
        });
    }

    let injectScheduled = false;
    function scheduleInject() {
        if (injectScheduled) {
            return;
        }
        injectScheduled = true;
        requestAnimationFrame(() => {
            injectScheduled = false;
            injectFilterHelp();
        });
    }

    function initFilterLegend() {
        injectFilterHelp();
        new MutationObserver(scheduleInject).observe(document.body, {
            childList: true,
            subtree: true,
        });
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initFilterLegend);
    } else {
        initFilterLegend();
    }
})();
