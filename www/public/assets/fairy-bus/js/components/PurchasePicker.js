/**
 * PurchasePicker — modale "Ultimo acquisto per fornitore" di un prodotto.
 *
 * loadProductPurchases(id) → GET api/products/{id}/purchases
 *   { last, suppliers[], history[] }
 * openPurchasePicker(purchasesData, onPick) → modale con una riga per
 *   fornitore (ultimo acquisto, ordine data desc); click riga → onPick(row)
 *
 * Usato in documents.js (carichi) e maintenance.js (ricambi).
 */
import FetchHelper from "../core/FetchHelper.js";

function escapeHtml(text) {
    const div = document.createElement("div");
    div.textContent = String(text ?? "");
    return div.innerHTML;
}

function fmtDate(value) {
    if (!value) {
        return "—";
    }
    const d = new Date(String(value).replace(" ", "T"));
    return isNaN(d) ? escapeHtml(value) : d.toLocaleDateString("it-IT");
}

export function loadProductPurchases(idProduct) {
    return FetchHelper.get(`${window.FB.baseUrl}api/products/${idProduct}/purchases`);
}

export function openPurchasePicker(purchasesData, onPick, onClose = null) {
    const rows = purchasesData?.suppliers || [];
    const d = document.createElement("dialog");
    d.className = "fb-dialog fb-dialog-large";
    const body =
        rows.length === 0
            ? '<p class="fb-muted">Nessun acquisto registrato per questo articolo.</p>'
            : `<div class="fb-purchase-table-wrap"><table class="fb-purchase-table">
            <thead><tr>
                <th>Fornitore</th><th>Data</th><th>Documento</th>
                <th class="num">Q.tà</th><th class="num">Prezzo</th>
                <th class="num">Sconto</th><th class="num">IVA</th><th></th>
            </tr></thead>
            <tbody>${rows
                .map(
                    (r, i) => `
                <tr data-idx="${i}">
                    <td class="sup">${escapeHtml(r.supplier_name || "—")}</td>
                    <td class="nowrap">${fmtDate(r.date)}</td>
                    <td>${escapeHtml(r.doc_number || "—")}</td>
                    <td class="num">${Number(r.quantity || 0).toLocaleString("it-IT")}</td>
                    <td class="num price">${Number(r.price || 0).toLocaleString("it-IT", { minimumFractionDigits: 2, maximumFractionDigits: 2 })} €</td>
                    <td class="num">${Number(r.discount || 0).toLocaleString("it-IT")}%</td>
                    <td class="num">${Number(r.vat_rate || 0).toLocaleString("it-IT")}%</td>
                    <td class="num"><button type="button" class="fb-btn fb-btn-sm fb-btn-select" data-pick="${i}">Seleziona</button></td>
                </tr>`,
                )
                .join("")}
            </tbody>
        </table></div>`;
    d.innerHTML = `
        <div class="fb-dialog-header"><h3 class="fb-dialog-title">Ultimo acquisto per fornitore</h3></div>
        <div class="fb-dialog-body">
            ${body}
            <div class="fb-form-actions">
                <button type="button" class="fb-btn fb-btn-secondary" data-action="close">Chiudi</button>
            </div>
        </div>
    `;
    document.body.appendChild(d);
    d.showModal();

    d.querySelectorAll("tbody tr[data-idx]").forEach((tr) => {
        tr.addEventListener("click", () => {
            d.close();
            onPick(rows[Number(tr.dataset.idx)]);
        });
    });
    d.querySelector('[data-action="close"]').addEventListener("click", () => d.close());
    d.addEventListener(
        "close",
        () => {
            onClose?.();
            d.remove();
        },
        { once: true },
    );
}
