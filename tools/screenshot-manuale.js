/**
 * Cattura gli screenshot dell'app per il manuale (docs sono in manuale.md).
 *
 * Uso:  node tools/screenshot-manuale.js
 * Env:  FB_URL (default https://fairybus.localhost)
 *       FB_USER / FB_PASS (credenziali di login)
 * Output: www/public/assets/fairy-bus/manuale/img/*.png
 *
 * Solo navigazione e apertura di dialog: nessun salvataggio.
 */
const { chromium } = require("playwright");
const fs = require("fs");
const path = require("path");

const BASE = process.env.FB_URL || "https://fairybus.localhost";
const USER = process.env.FB_USER || "admin@fairybus.local";
const PASS = process.env.FB_PASS || "admin";
const OUT = path.join(__dirname, "..", "www", "public", "assets", "fairy-bus", "manuale", "img");

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function shot(page, name, opts = {}) {
    const sel = opts.selector;
    const target = sel ? page.locator(sel).last() : page;
    await sleep(opts.wait || 400);
    await target.screenshot({ path: path.join(OUT, name) });
    console.log(`  ${name}`);
}

(async () => {
    fs.mkdirSync(OUT, { recursive: true });
    const browser = await chromium.launch();
    const page = await browser.newPage({ viewport: { width: 1440, height: 860 }, ignoreHTTPSErrors: true });

    // --- Login ---
    await page.goto(`${BASE}/login`, { waitUntil: "networkidle" });
    await page.fill('input[name="email"]', USER);
    await page.fill('input[name="password"]', PASS);
    await page.click('button[type="submit"]');
    await page.waitForLoadState("networkidle");
    if (page.url().includes("/login")) {
        throw new Error(`login fallito (url=${page.url()}) — verifica FB_USER/FB_PASS`);
    }
    // Se c'è il cambio password forzato, segui il redirect alla dashboard dopo
    if (page.url().includes("/change-password")) {
        console.log("  nota: password-change forzato attivo");
    }
    await page.goto(`${BASE}/admin/dashboard`, { waitUntil: "networkidle" });
    console.log("login ok ->", page.url());

    // --- Dashboard ---
    await page.waitForSelector(".fb-kpi, .fb-card, main", { timeout: 15000 });
    await sleep(1200);
    await shot(page, "dashboard.png");

    // --- Documenti: lista carichi ---
    await page.goto(`${BASE}/admin/documents`, { waitUntil: "networkidle" });
    await page.waitForSelector("table tbody tr", { timeout: 15000 }).catch(() => {});
    await sleep(800);
    await shot(page, "documenti-carichi.png");

    // --- Nuovo carico: scheda vuota ---
    await page.click('[data-action="new-document"]');
    await page.waitForSelector("dialog[open]", { timeout: 8000 });
    await shot(page, "carico-nuovo.png", { selector: "dialog[open]", wait: 500 });

    // --- Tab Movimenti su documento nuovo: overlay "salva prima" ---
    await page.click('dialog[open] .fb-tab[data-tab="movimenti"]').catch(() => {});
    await shot(page, "carico-movimenti-bloccati.png", { selector: "dialog[open]", wait: 500 });
    await page.keyboard.press("Escape");
    await sleep(400);

    // --- Documento esistente → Movimenti → ricerca articolo (famiglia alias) ---
    const docEdit = page.locator('[data-action="edit-document"]').first();
    if (await docEdit.count()) {
        await docEdit.click();
        await page.waitForSelector("dialog[open]", { timeout: 8000 });
        await sleep(500);
        await page.click('dialog[open] .fb-tab[data-tab="movimenti"]');
        await sleep(400);
        await page.click("dialog[open] .fb-tableselect-input");
        await sleep(600); // lascia aprire il dropdown prima di digitare
        await page.fill("dialog[open] .fb-tableselect-input", "cinghia");
        await page.waitForSelector("dialog[open] .fb-tableselect-dropdown .fb-tableselect-row:not(.fb-tableselect-empty)", { timeout: 8000 }).catch(() => {});
        await sleep(500);
        // Il dropdown è position:fixed e può uscire dal bordo del dialog:
        // per questo scatto catturo la pagina intera, non l'elemento.
        await shot(page, "carico-ricerca-articolo.png", { wait: 600 });
        await page.keyboard.press("Escape");
        await page.keyboard.press("Escape");
    }

    // --- Magazzino: categorie, prodotti e giacenze ---
    await page.goto(`${BASE}/admin/categories`, { waitUntil: "networkidle" });
    await page.waitForSelector("table tbody tr", { timeout: 15000 }).catch(() => {});
    await sleep(800);
    await shot(page, "categorie.png");

    await page.goto(`${BASE}/admin/products`, { waitUntil: "networkidle" });
    await page.waitForSelector("table tbody tr", { timeout: 15000 }).catch(() => {});
    await sleep(800);
    await shot(page, "prodotti.png");

    await page.goto(`${BASE}/admin/stocks`, { waitUntil: "networkidle" });
    await page.waitForSelector("table tbody tr", { timeout: 15000 }).catch(() => {});
    await sleep(800);
    await shot(page, "giacenze.png");

    // --- Manutenzione: lista ---
    await page.goto(`${BASE}/admin/maintenance`, { waitUntil: "networkidle" });
    await page.waitForSelector("table tbody tr", { timeout: 15000 }).catch(() => {});
    await sleep(800);
    await shot(page, "manutenzioni.png");

    // --- Nuova manutenzione: tab Informazioni ---
    await page.click('[data-action="new-maintenance"]');
    await page.waitForSelector("dialog[open]", { timeout: 8000 });
    await sleep(600);
    await shot(page, "manutenzione-informazioni.png", { selector: "dialog[open]" });

    // --- Tab Ricambi su manutenzione non salvata: overlay "Salva prima" ---
    await page.click('dialog[open] .fb-tab[data-tab="ricambi"]');
    await shot(page, "manutenzione-ricambi-bloccata.png", { selector: "dialog[open]", wait: 500 });
    await page.keyboard.press("Escape");
    await page
        .locator('dialog[open] .fb-dialog-close, dialog[open] [data-action="close"]')
        .first()
        .click()
        .catch(() => page.keyboard.press("Escape"));
    await sleep(300);

    // --- Manutenzione esistente: tab Ricambi con tabella 12 colonne ---
    const editBtn = page.locator('[data-action="edit-maintenance"]').first();
    if (await editBtn.count()) {
        await editBtn.click();
        await page.waitForSelector("dialog[open]", { timeout: 8000 });
        await sleep(700);
        await page.click('dialog[open] .fb-tab[data-tab="ricambi"]');
        await shot(page, "manutenzione-ricambi.png", { selector: "dialog[open]", wait: 600 });
        await page.keyboard.press("Escape");
    }

    // --- Anagrafiche ---
    await page.goto(`${BASE}/admin/customers`, { waitUntil: "networkidle" });
    await page.waitForSelector("table tbody tr", { timeout: 15000 }).catch(() => {});
    await sleep(800);
    await shot(page, "anagrafiche-clienti.png");

    await page.goto(`${BASE}/admin/suppliers`, { waitUntil: "networkidle" });
    await page.waitForSelector("table tbody tr", { timeout: 15000 }).catch(() => {});
    await sleep(800);
    await shot(page, "anagrafiche-fornitori.png");

    await page.goto(`${BASE}/admin/locations`, { waitUntil: "networkidle" });
    await sleep(800);
    await shot(page, "anagrafiche-locations.png");

    // --- Documenti: scarichi e fatture ---
    await page.goto(`${BASE}/admin/unloads`, { waitUntil: "networkidle" });
    await page.waitForSelector("table tbody tr", { timeout: 15000 }).catch(() => {});
    await sleep(800);
    await shot(page, "documenti-scarichi.png");

    await page.goto(`${BASE}/admin/invoices`, { waitUntil: "networkidle" });
    await page.waitForSelector("table tbody tr", { timeout: 15000 }).catch(() => {});
    await sleep(800);
    await shot(page, "documenti-fatture.png");

    // Fattura: scheda di registrazione (senza salvare)
    await page.click('[data-action="new-invoice"]').catch(() => {});
    await page.waitForSelector("dialog[open]", { timeout: 5000 }).catch(() => {});
    if (await page.locator("dialog[open]").count()) {
        await shot(page, "fattura-nuova.png", { selector: "dialog[open]", wait: 500 });
        await page.keyboard.press("Escape");
    }

    // --- Rifornimenti ---
    await page.goto(`${BASE}/admin/refuelling-stations`, { waitUntil: "networkidle" });
    await page.waitForSelector("table tbody tr", { timeout: 15000 }).catch(() => {});
    await sleep(800);
    await shot(page, "rifornimenti-stazioni.png");

    await page.goto(`${BASE}/admin/refuelling-load`, { waitUntil: "networkidle" });
    await page.waitForSelector("table tbody tr", { timeout: 15000 }).catch(() => {});
    await sleep(800);
    await shot(page, "rifornimenti-carico.png");

    await page.goto(`${BASE}/admin/refuelling`, { waitUntil: "networkidle" });
    await page.waitForSelector("table tbody tr", { timeout: 15000 }).catch(() => {});
    await sleep(800);
    await shot(page, "rifornimenti-gestione.png");

    // Nuovo rifornimento: scheda con veicolo/stazione/km (senza salvare)
    await page.click('[data-action="new-refuelling"]').catch(() => {});
    await page.waitForSelector("dialog[open]", { timeout: 5000 }).catch(() => {});
    if (await page.locator("dialog[open]").count()) {
        await shot(page, "rifornimento-nuovo.png", { selector: "dialog[open]", wait: 600 });
        await page.keyboard.press("Escape");
    }

    await page.goto(`${BASE}/admin/refuelling-stats`, { waitUntil: "networkidle" });
    await sleep(1200);
    await shot(page, "rifornimenti-statistiche.png");

    // --- Officina: veicoli, marche, caratteristiche ---
    await page.goto(`${BASE}/admin/vehicles`, { waitUntil: "networkidle" });
    await page.waitForSelector("table tbody tr", { timeout: 15000 }).catch(() => {});
    await sleep(800);
    await shot(page, "veicoli.png");

    await page.click('[data-action="new-vehicle"]').catch(() => {});
    await page.waitForSelector("dialog[open]", { timeout: 5000 }).catch(() => {});
    if (await page.locator("dialog[open]").count()) {
        await shot(page, "veicolo-nuovo.png", { selector: "dialog[open]", wait: 500 });
        await page.keyboard.press("Escape");
    }

    await page.goto(`${BASE}/admin/brands`, { waitUntil: "networkidle" });
    await page.waitForSelector("table tbody tr", { timeout: 15000 }).catch(() => {});
    await sleep(800);
    await shot(page, "marche.png");

    await page.goto(`${BASE}/admin/features`, { waitUntil: "networkidle" });
    await page.waitForSelector("table tbody tr", { timeout: 15000 }).catch(() => {});
    await sleep(800);
    await shot(page, "caratteristiche.png");

    // --- Scadenze ---
    await page.goto(`${BASE}/admin/expirations`, { waitUntil: "networkidle" });
    await page.waitForSelector("table tbody tr", { timeout: 15000 }).catch(() => {});
    await sleep(800);
    await shot(page, "scadenze.png");

    // Nuova voce di scadenza (senza salvare)
    await page.click('[data-action="new-tag"]').catch(() => {});
    await page.waitForSelector("dialog[open]", { timeout: 5000 }).catch(() => {});
    if (await page.locator("dialog[open]").count()) {
        await shot(page, "scadenza-nuova.png", { selector: "dialog[open]", wait: 500 });
        await page.keyboard.press("Escape");
    }

    // --- Calendario ---
    await page.goto(`${BASE}/admin/calendar`, { waitUntil: "networkidle" });
    await sleep(1000);
    await shot(page, "calendario.png");

    // --- Impostazioni: tab Generali (costo orario) ---
    await page.goto(`${BASE}/admin/settings`, { waitUntil: "networkidle" });
    await sleep(800);
    await shot(page, "impostazioni.png");
    await page.click('[data-tab="generali"]').catch(() => {});
    await shot(page, "impostazioni-generali.png", { wait: 500 });

    // --- Impostazioni: tab Importazioni (Importa tutto) ---
    await page.click('[data-tab="imports"]').catch(() => {});
    await shot(page, "impostazioni-importazioni.png", { wait: 500 });

    await browser.close();
    console.log(`\nScreenshot salvati in ${OUT}`);
})().catch((err) => {
    console.error("ERRORE:", err.message);
    console.error("Suggerimento: imposta FB_URL / FB_USER / FB_PASS se le credenziali di default non funzionano.");
    process.exit(1);
});
