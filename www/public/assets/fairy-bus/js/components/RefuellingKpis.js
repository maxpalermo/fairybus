import FetchHelper from "../core/FetchHelper.js";

/**
 * Schede KPI riassuntive dei rifornimenti (carico/scarico/giacenza/ultimo carico).
 * Uso:
 *   const kpis = new RefuellingKpis("#refuelling-kpis");
 *   kpis.load();            // primo caricamento
 *   kpis.refresh();         // dopo create/update/delete
 */
export default class RefuellingKpis {
    /**
     * @param {string|HTMLElement} target - selettore CSS o elemento contenitore (.fb-kpi-grid)
     */
    constructor(target) {
        this.el = typeof target === "string" ? document.querySelector(target) : target;
    }

    async load() {
        if (!this.el) return;
        try {
            const s = await FetchHelper.get(`${window.FB.baseUrl}api/refuelling/summary`);
            this.render(s);
        } catch (err) {
            console.error("Errore caricamento riepilogo:", err);
        }
    }

    refresh() {
        return this.load();
    }

    render(s) {
        const last = s.last_load;
        const lastOut = s.last_unload;
        this.el.innerHTML = `
            <article class="fb-kpi fb-kpi-success">
                <span class="fb-kpi-label">Carico totale</span>
                <span class="fb-kpi-value">${fmtLiters(s.load)}</span>
                <span class="fb-kpi-sub">${fmtInt(s.load_count)} movimenti</span>
            </article>
            <article class="fb-kpi fb-kpi-danger">
                <span class="fb-kpi-label">Scarico totale</span>
                <span class="fb-kpi-value">${fmtLiters(s.unload)}</span>
                <span class="fb-kpi-sub">${fmtInt(s.unload_count)} movimenti</span>
            </article>
            <article class="fb-kpi fb-kpi-warning">
                <span class="fb-kpi-label">Giacenza</span>
                <span class="fb-kpi-value">${fmtLiters(s.stock)}</span>
                <span class="fb-kpi-sub">Carico − scarico</span>
            </article>
            <article class="fb-kpi fb-kpi-info">
                <span class="fb-kpi-label">Ultimo carico</span>
                <span class="fb-kpi-value">${last ? fmtLiters(last.liters) : "—"}</span>
                <span class="fb-kpi-sub">${last ? `${fmtDate(last.refuel_time)}${last.supplier_name ? " · " + escapeHtml(last.supplier_name) : ""}` : "Nessun carico"}</span>
            </article>
            <article class="fb-kpi fb-kpi-secondary">
                <span class="fb-kpi-label">Ultimo scarico</span>
                <span class="fb-kpi-value">${lastOut ? fmtLiters(lastOut.liters) : "—"}</span>
                <span class="fb-kpi-sub">${lastOut ? `${fmtDate(lastOut.refuel_time)}${lastOut.vehicle_plate ? " · " + escapeHtml(lastOut.vehicle_plate) : ""}` : "Nessuno scarico"}</span>
            </article>`;
    }
}

function fmtLiters(v) {
    return v === null || v === undefined ? "—" : `${Number(v).toLocaleString("it-IT", { minimumFractionDigits: 2 })} l`;
}

function fmtInt(v) {
    return Number(v || 0).toLocaleString("it-IT");
}

function fmtDate(v) {
    if (!v) return "—";
    const d = new Date(String(v).replace(" ", "T"));
    if (Number.isNaN(d.getTime())) return String(v);
    const pad = (n) => String(n).padStart(2, "0");
    return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function escapeHtml(text) {
    const div = document.createElement("div");
    div.textContent = String(text ?? "");
    return div.innerHTML;
}
