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

    // Debounce dei filtri colonna: la ricerca parte 800ms dopo l'ultimo
    // tasto premuto (default 500) — meno re-render mentre si digita.
    // Vale anche per le tabelle con sidePagination server (locations).
    if (window.jQuery && window.jQuery.fn.bootstrapTable) {
        window.jQuery.fn.bootstrapTable.defaults.searchTimeOut = 800;
        // Righe per pagina uniformi su tutte le tabelle: default 50,
        // selezione [5,10,20,50,100,200,500,1000].
        window.jQuery.fn.bootstrapTable.defaults.pageSize = 50;
        window.jQuery.fn.bootstrapTable.defaults.pageList = [5, 10, 20, 50, 100, 200, 500, 1000];
        // I filtri colonna scattano solo con Invio o col bottone "Cerca":
        // niente re-render ad ogni tasto premuto.
        window.jQuery.fn.bootstrapTable.defaults.searchOnEnterKey = true;
    }

    // Applica i filtri colonna di una tabella: equivale a premere Invio
    // (searchOnEnterKey) in ogni campo filtro; le select restano su change.
    // Usato dal bottone "Cerca" delle toolbar di pagina.
    window.triggerColumnSearch = function (tableEl) {
        const wrap = tableEl && tableEl.closest(".bootstrap-table");
        if (!wrap) {
            return;
        }
        wrap.querySelectorAll(".filter-control input, .filter-control select").forEach((el) => {
            const $el = window.$(el);
            if ($el.is("select")) {
                $el.trigger("change");
            } else {
                $el.trigger(window.$.Event("keyup", { keyCode: 13 }));
            }
        });
    };

    // Aggancia i tooltip "premi Invio o usa il bottone Cerca" ai campi
    // filtro di una tabella (da chiamare su post-header.bs.table).
    window.fbBindSearchHints = function (tableEl) {
        const wrap = tableEl && tableEl.closest(".bootstrap-table");
        if (!wrap) {
            return;
        }
        wrap.querySelectorAll(".filter-control input").forEach((el) => {
            el.title = "Scrivi e premi Invio, oppure usa il bottone Cerca in alto a destra";
        });
    };

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
    const SEARCH_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>';

    // Inietta la lente "Cerca" nella page-actions (una sola per pagina):
    // applica i filtri colonna di tutte le tabelle con filter-control.
    function injectRunFiltersButton() {
        if (document.querySelector('[data-action="run-filters"]')) {
            return;
        }
        const actions = document.querySelector(".fb-page-actions");
        if (!actions) {
            return;
        }
        const btn = document.createElement("button");
        btn.type = "button";
        btn.className = "fb-btn-icon fb-btn-icon-info";
        btn.dataset.action = "run-filters";
        btn.title = "Cerca: applica i filtri delle colonne";
        btn.innerHTML = SEARCH_ICON;
        btn.addEventListener("click", () => {
            document.querySelectorAll(".fb-table-wrap .fixed-table-container").forEach((container) => {
                const table = container.querySelector("table");
                if (table && container.querySelector(".filter-control")) {
                    window.triggerColumnSearch(table);
                }
            });
        });
        const firstIcon = actions.querySelector(".fb-btn-icon");
        actions.insertBefore(btn, firstIcon || null);
    }

    /* ---------- Wizard ricerca guidata (popover) ----------
     * Icona "sliders" dentro ogni campo filtro input: apre un popover con
     * select operatore + valore/i (datepicker per date, number per numeri)
     * e anteprima live del codice. Applica scrivendo il codice nel campo
     * e simulando Invio — la pipeline di filtro resta invariata.
     */
    const WIZ_ICON = '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="21" x2="4" y2="14"></line><line x1="4" y1="10" x2="4" y2="3"></line><line x1="12" y1="21" x2="12" y2="12"></line><line x1="12" y1="8" x2="12" y2="3"></line><line x1="20" y1="21" x2="20" y2="16"></line><line x1="20" y1="12" x2="20" y2="3"></line><line x1="1" y1="14" x2="7" y2="14"></line><line x1="9" y1="8" x2="15" y2="8"></line><line x1="17" y1="16" x2="23" y2="16"></line></svg>';
    const WIZ_OPS = [
        ["", "contiene"],
        ["%>", "inizia con"],
        ["%/", "finisce con"],
        ["=", "uguale a"],
        [">", "maggiore di"],
        ["<", "minore di"],
        ["<>", "diverso da"],
        ["<->", "compreso tra (inclusi)"],
    ];

    // Tipo colonna dai dati reali (fino a 15 righe), fallback sul nome campo.
    function wizDetectType(th) {
        const field = th.getAttribute("data-field") || "";
        const table = th.closest(".bootstrap-table")?.querySelector("table");
        if (table && field && window.$ && window.$.fn.bootstrapTable) {
            const rows = window.$(table).bootstrapTable("getData") || [];
            for (const r of rows.slice(0, 15)) {
                const v = r[field];
                if (v === null || v === undefined || v === "") {
                    continue;
                }
                const s = String(v);
                if (/^\d{4}-\d{2}-\d{2}/.test(s) || /^\d{1,2}[\/.-]\d{1,2}[\/.-]\d{2,4}/.test(s)) {
                    return "date";
                }
                return isNaN(Number(s)) ? "text" : "number";
            }
        }
        if (/date|data|scad/i.test(field)) {
            return "date";
        }
        if (/count|total|amount|price|qty|km|lit|id_/i.test(field)) {
            return "number";
        }
        return "text";
    }

    // Riporta il codice sintassi a operatore + valori (per riempire il wizard).
    function wizParseCode(raw) {
        const s = String(raw || "").trim();
        if (s.startsWith("<->")) {
            const [a, b] = s.slice(3).split(",");
            return { op: "<->", v1: (a || "").trim(), v2: (b || "").trim() };
        }
        const m = s.match(/^(%>|%\/|<>|=|>|<)(.*)$/);
        return m ? { op: m[1], v1: m[2].trim() } : { op: "", v1: s };
    }

    const wizItToIso = (s) => {
        const m = String(s || "").trim().match(/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{2,4})/);
        if (m) {
            return `${m[3].length === 2 ? "20" + m[3] : m[3]}-${m[2].padStart(2, "0")}-${m[1].padStart(2, "0")}`;
        }
        const i = String(s || "").match(/^(\d{4}-\d{2}-\d{2})/);
        return i ? i[1] : s;
    };
    const wizIsoToIt = (s) => {
        const m = String(s || "").trim().match(/^(\d{4})-(\d{2})-(\d{2})/);
        return m ? `${m[3]}/${m[2]}/${m[1]}` : s;
    };

    function wizBuildCode(op, v1, v2, type) {
        const cvt = (s) => (type === "date" ? wizIsoToIt(s) : String(s || "").trim());
        const a = cvt(v1);
        const b = cvt(v2);
        if (op === "<->") {
            if (a && b) {
                return `<->${a},${b}`;
            }
            return a ? `>${a}` : "";
        }
        return a ? `${op}${a}` : "";
    }

    let wizPop = null;
    let wizInput = null;

    function closeFilterWizard() {
        if (wizPop) {
            wizPop.style.display = "none";
        }
        wizInput = null;
    }

    function openFilterWizard(th, input) {
        const field = th.getAttribute("data-field") || "";
        const type = wizDetectType(th);
        const thead = th.closest("thead");
        const colTitle = thead?.querySelector(`tr:first-child th[data-field="${field}"] .th-inner`)?.textContent.trim() || field || "colonna";

        if (!wizPop) {
            wizPop = document.createElement("div");
            wizPop.className = "fb-fw-pop";
            wizPop.innerHTML = `
                <div class="fb-fw-head">
                    <span class="fb-fw-title"></span>
                    <button type="button" class="fb-fw-close" aria-label="Chiudi">
                        <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18M6 6l12 12"></path></svg>
                    </button>
                </div>
                <select class="fb-form-input fb-fw-op" data-no-search>${WIZ_OPS.map(([v, l]) => `<option value="${v}">${l}</option>`).join("")}</select>
                <div class="fb-fw-fields">
                    <input class="fb-form-input fb-fw-v1">
                    <span class="fb-fw-sep">÷</span>
                    <input class="fb-form-input fb-fw-v2">
                </div>
                <code class="fb-fw-code" title="Codice filtro generato"></code>
                <div class="fb-fw-actions">
                    <button type="button" class="fb-btn fb-btn-secondary fb-btn-sm fb-fw-clear">Pulisci</button>
                    <button type="button" class="fb-btn fb-btn-primary fb-btn-sm fb-fw-apply">Applica</button>
                </div>`;
            document.body.appendChild(wizPop);

            const opSel = wizPop.querySelector(".fb-fw-op");
            const v1 = wizPop.querySelector(".fb-fw-v1");
            const v2 = wizPop.querySelector(".fb-fw-v2");
            const code = wizPop.querySelector(".fb-fw-code");

            const refresh = () => {
                const range = opSel.value === "<->";
                wizPop.querySelector(".fb-fw-sep").style.display = range ? "" : "none";
                v2.style.display = range ? "" : "none";
                const c = wizBuildCode(opSel.value, v1.value, v2.value, wizPop._type);
                code.textContent = c || "(vuoto)";
                return c;
            };
            wizPop._refresh = refresh;

            const apply = () => {
                if (wizInput) {
                    wizInput.value = refresh();
                    window.$(wizInput).trigger(window.$.Event("keyup", { keyCode: 13 }));
                }
                closeFilterWizard();
            };

            opSel.addEventListener("change", refresh);
            [v1, v2].forEach((el) => {
                el.addEventListener("input", refresh);
                el.addEventListener("keydown", (e) => {
                    if (e.key === "Enter") {
                        e.preventDefault();
                        apply();
                    } else if (e.key === "Escape") {
                        closeFilterWizard();
                    }
                });
            });
            wizPop.querySelector(".fb-fw-apply").addEventListener("click", apply);
            wizPop.querySelector(".fb-fw-clear").addEventListener("click", () => {
                if (wizInput) {
                    wizInput.value = "";
                    window.$(wizInput).trigger(window.$.Event("keyup", { keyCode: 13 }));
                }
                closeFilterWizard();
            });
            wizPop.querySelector(".fb-fw-close").addEventListener("click", closeFilterWizard);
            document.addEventListener("mousedown", (e) => {
                if (wizPop.style.display !== "none" && !wizPop.contains(e.target) && !e.target.closest(".fb-fw-btn")) {
                    closeFilterWizard();
                }
            });
            document.addEventListener("keydown", (e) => {
                if (e.key === "Escape") {
                    closeFilterWizard();
                }
            });
            window.addEventListener("resize", closeFilterWizard);
            // Chiudi allo scroll di pagina, ma ignora gli scroll dentro il
            // popover (es. dropdown select convertiti in SearchableSelect).
            window.addEventListener(
                "scroll",
                (e) => {
                    if (wizPop.style.display !== "none" && !wizPop.contains(e.target)) {
                        closeFilterWizard();
                    }
                },
                true,
            );
        }

        const sameTarget = wizInput === input && wizPop.style.display !== "none";
        closeFilterWizard();
        if (sameTarget) {
            return; // click sull'icona = toggle
        }
        wizInput = input;
        wizPop._type = type;
        wizPop.querySelector(".fb-fw-title").textContent = `Filtra: ${colTitle}`;

        const parsed = wizParseCode(input.value);
        const opSel = wizPop.querySelector(".fb-fw-op");
        const v1 = wizPop.querySelector(".fb-fw-v1");
        const v2 = wizPop.querySelector(".fb-fw-v2");
        opSel.value = parsed.op;
        const inputType = type === "date" ? "date" : type === "number" ? "number" : "text";
        [v1, v2].forEach((el) => {
            el.type = inputType;
            if (inputType === "number") {
                el.step = "any";
            }
        });
        const toField = (s) => (type === "date" ? wizItToIso(s) : s);
        v1.value = toField(parsed.v1) || "";
        v2.value = toField(parsed.v2) || "";
        wizPop._refresh();

        wizPop.style.display = "block";
        const r = input.getBoundingClientRect();
        wizPop.style.top = `${r.bottom + 4}px`;
        wizPop.style.left = `${Math.max(8, Math.min(r.left, window.innerWidth - wizPop.offsetWidth - 8))}px`;
        v1.focus();
    }

    function injectFilterHelp() {
        document.querySelectorAll(".bootstrap-table").forEach((wrap) => {
            if (!wrap.querySelector(".filter-control input")) {
                return;
            }
            wrap.querySelectorAll(".filter-control input").forEach((input) => {
                if (!input.title) {
                    input.title = INPUT_TITLE;
                }
                const th = input.closest(".filter-control");
                if (th && !th.querySelector(".fb-fw-btn")) {
                    const btn = document.createElement("button");
                    btn.type = "button";
                    btn.className = "fb-fw-btn";
                    btn.title = "Ricerca guidata";
                    btn.innerHTML = WIZ_ICON;
                    btn.addEventListener("click", (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        openFilterWizard(th, input);
                    });
                    th.appendChild(btn);
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
            // Bottone lente "Cerca" nella page-actions: equivale a Invio nei
            // campi filtro (searchOnEnterKey). Iniettato una sola volta per
            // pagina, solo se la pagina non ne fornisce già uno.
            injectRunFiltersButton();
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
