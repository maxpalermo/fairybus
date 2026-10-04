/**
 * Copyright since 2026 Massimiliano Palermo
 * @license AFL-3.0
 *
 * Toolbar a schede KPI (stile .fb-kpi) riusabile: scarica un endpoint di
 * riepilogo e renderizza le card definite da una callback.
 *
 *   const kpi = new KpiGrid("#products-kpis", `${BASE}api/products/summary`,
 *       (s) => [{ label: "Prodotti", value: fmt(s.total), sub: "…", tone: "info" }]);
 *   await kpi.load();      // primo caricamento
 *   kpi.refresh();         // dopo create/update/delete
 *
 * Card: { label, value, sub?, tone? } — tone: success|danger|warning|info|secondary
 */
import FetchHelper from "../core/FetchHelper.js";

const fmtNum = (n) => Number(n || 0).toLocaleString("it-IT");

export default class KpiGrid {
    /**
     * @param {string|HTMLElement} target selettore o elemento contenitore
     * @param {string} url endpoint JSON
     * @param {(data: object) => Array<{label:string,value:string,sub?:string,tone?:string}>} cardsFn
     * @param {(res: object) => object} pickFn opzionale: estrae il payload dalla risposta (default res.summary || res)
     */
    constructor(target, url, cardsFn, pickFn = null) {
        this.el = typeof target === "string" ? document.querySelector(target) : target;
        this.url = url;
        this.cardsFn = cardsFn;
        this.pickFn = pickFn || ((res) => res.summary || res);
        if (this.el && !this.el.classList.contains("fb-kpi-grid")) {
            this.el.classList.add("fb-kpi-grid");
        }
    }

    async load() {
        if (!this.el) return;
        const res = await FetchHelper.get(this.url);
        const cards = this.cardsFn(this.pickFn(res));
        this.el.innerHTML = cards
            .map(
                (c, i) => `
            <article class="fb-kpi fb-kpi-${c.tone || "secondary"}">
                ${
                    c.split
                        ? `
                <div class="fb-kpi-split-cols">
                    <div class="fb-kpi-split-col">
                        <span class="fb-kpi-label">${c.split.left.label}</span>
                        <span class="fb-kpi-value">${c.split.left.value}</span>
                    </div>
                    <div class="fb-kpi-split-col fb-kpi-split-right">
                        <span class="fb-kpi-label">${c.split.right.label}</span>
                        <span class="fb-kpi-value">${c.split.right.value}</span>
                    </div>
                </div>`
                        : `
                <span class="fb-kpi-label">${c.label}</span>
                <span class="fb-kpi-value">${c.value}</span>
                ${c.sub ? `<span class="fb-kpi-sub">${c.sub}</span>` : ""}`
                }
                ${c.button ? `<button type="button" class="fb-kpi-btn${c.split ? " fb-kpi-btn-center" : ""}" data-kpi-action="${i}">${c.button.label}</button>` : ""}
            </article>`,
            )
            .join("");
        this.el.querySelectorAll("[data-kpi-action]").forEach((btn) => {
            btn.addEventListener("click", (e) => {
                e.stopPropagation();
                const card = cards[Number(btn.dataset.kpiAction)];
                card?.button?.onClick?.();
            });
        });
    }

    refresh() {
        return this.load().catch(() => {});
    }

    static fmtNum(n) {
        return fmtNum(n);
    }
}
