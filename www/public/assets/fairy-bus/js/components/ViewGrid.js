/**
 * Copyright since 2026 Massimiliano Palermo
 * @license AFL-3.0
 *
 * Griglia di valori read-only per i dialog di anteprima.
 * Ogni voce mostra icona colorata + label + valore; icona e tonalità sono
 * dedotte automaticamente dall'etichetta (label in italiano), oppure passate
 * esplicitamente.
 *
 * Uso:
 *   import { viewItem, viewGrid } from "../components/ViewGrid.js";
 *   viewGrid([
 *       { label: "Data", value: "03/10/2026" },
 *       { label: "Automezzo", value: "AB769MH" },
 *       { label: "Nota", value: row.note },
 *   ]);
 *   // oppure un item alla volta:
 *   viewItem("Automezzo", row.vehicle_plate);
 *   viewItem("Custom", "html <b>ok</b>", { icon: "truck", tone: "blue", html: true });
 */

const svg = (inner) => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${inner}</svg>`;

export const VIEW_ICONS = {
    calendar: svg('<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>'),
    clock: svg('<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>'),
    truck: svg('<rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>'),
    store: svg('<path d="M3 9l1-5h16l1 5"/><path d="M3 9v11h18V9"/><path d="M9 20v-6h6v6"/><path d="M3 9h18"/>'),
    user: svg('<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>'),
    pin: svg('<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>'),
    fuel: svg('<line x1="3" y1="22" x2="15" y2="22"/><line x1="4" y1="9" x2="14" y2="9"/><path d="M14 22V4a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v18"/><path d="M14 13h2a2 2 0 0 1 2 2v2a2 2 0 0 1-2 2h-2"/><path d="M18 19l2-2V7l-3-3"/>'),
    droplet: svg('<path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z"/>'),
    euro: svg('<path d="M4 10h12"/><path d="M4 14h9"/><path d="M19 6a7.7 7.7 0 0 0-5.1-2 8 8 0 0 0-8 8 8 8 0 0 0 8 8 7.7 7.7 0 0 0 5.1-2"/>'),
    gauge: svg('<path d="M12 15l4-6"/><path d="M4 16a8 8 0 1 1 16 0"/><line x1="12" y1="15" x2="12.01" y2="15"/>'),
    wrench: svg('<path d="M14.7 6.3a4.5 4.5 0 0 0-6.4 6.4L3 18l3 3 5.3-5.3a4.5 4.5 0 0 0 6.4-6.4L14 13l-3-3 3.7-3.7z"/>'),
    note: svg('<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>'),
    hash: svg('<line x1="4" y1="9" x2="20" y2="9"/><line x1="4" y1="15" x2="20" y2="15"/><line x1="10" y1="3" x2="8" y2="21"/><line x1="16" y1="3" x2="14" y2="21"/>'),
    file: svg('<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>'),
    mail: svg('<path d="M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/><polyline points="22 6 12 13 2 6"/>'),
    phone: svg('<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.12.96.37 1.9.72 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.35 1.85.6 2.81.72A2 2 0 0 1 22 16.92z"/>'),
    tag: svg('<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/>'),
    flag: svg('<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/>'),
    compass: svg('<circle cx="12" cy="12" r="10"/><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"/>'),
    check: svg('<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>'),
    package: svg('<path d="M16.5 9.4L7.55 4.24"/><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>'),
    building: svg('<rect x="4" y="2" width="16" height="20" rx="1"/><line x1="8" y1="6" x2="10" y2="6"/><line x1="14" y1="6" x2="16" y2="6"/><line x1="8" y1="10" x2="10" y2="10"/><line x1="14" y1="10" x2="16" y2="10"/><line x1="8" y1="14" x2="10" y2="14"/><line x1="14" y1="14" x2="16" y2="14"/><line x1="9" y1="22" x2="15" y2="22"/>'),
};

/**
 * Regole label -> {icon, tone}. Prima corrispondenza vince (lowercase, includes).
 */
const LABEL_RULES = [
    [/\bdata\b|scadenza/, "calendar", "violet"],
    [/automezzo|veicolo|targa/, "truck", "blue"],
    [/fornitore/, "store", "teal"],
    [/cliente|referente/, "user", "teal"],
    [/ragione sociale|nome/, "building", "teal"],
    [/p\.?\s?iva|\bcf\b/, "file", "slate"],
    [/stazione|punto/, "fuel", "amber"],
    [/alimentazione|carburante/, "fuel", "amber"],
    [/quantit|litri/, "droplet", "amber"],
    [/prezzo|importo|totale|costo|iva|sconto|fattur/, "euro", "green"],
    [/km|chilometr|differenza|consumo/, "gauge", "indigo"],
    [/latitud|longitud|epsg/, "compass", "rose"],
    [/indirizzo|via\b|quartiere|comune|citt|provincia|\bcap\b/, "pin", "rose"],
    [/email|e-mail|pec/, "mail", "cyan"],
    [/telefono|cellulare|fax/, "phone", "cyan"],
    [/numero|n\.|ddt/, "hash", "stone"],
    [/tipo|stato|attivo|operazione|periodicit/, "tag", "orange"],
    [/lavoro|manodopera/, "wrench", "blue"],
    [/nota|note/, "note", "slate"],
];

const KNOWN_TONES = new Set(["slate", "blue", "indigo", "violet", "teal", "cyan", "green", "amber", "orange", "rose", "stone"]);

function detect(label) {
    const l = String(label ?? "").toLowerCase();
    for (const [re, icon, tone] of LABEL_RULES) {
        if (re.test(l)) return { icon, tone };
    }
    return { icon: "tag", tone: "slate" };
}

function escapeHtml(text) {
    return String(text ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);
}

/**
 * @param {string} label
 * @param {*} value
 * @param {{icon?: string, tone?: string, html?: boolean, span?: number}} [opts]
 */
export function viewItem(label, value, opts = {}) {
    const det = detect(label);
    const tone = KNOWN_TONES.has(opts.tone) ? opts.tone : det.tone;
    const icon = opts.icon || det.icon;
    const empty = value === null || value === undefined || String(value).trim() === "" || value === "—";
    const display = empty ? "—" : opts.html ? String(value) : escapeHtml(value);
    const span = Number(opts.span) > 1 ? ` grid-column: span ${Math.min(4, Number(opts.span))};` : "";

    return `
        <div class="fb-view-item${empty ? " fb-view-item-empty" : ""}" style="${span}">
            <span class="fb-vi-icon fb-vi-${tone}">${VIEW_ICONS[icon] || VIEW_ICONS.tag}</span>
            <div class="fb-vi-body"><dt>${escapeHtml(label)}</dt><dd>${display}</dd></div>
        </div>`;
}

/**
 * @param {Array<{label:string, value:*, icon?:string, tone?:string, html?:boolean, span?:number}>} items
 */
export function viewGrid(items) {
    return `<dl class="fb-view-grid">${items.map((i) => viewItem(i.label, i.value, i)).join("")}</dl>`;
}
