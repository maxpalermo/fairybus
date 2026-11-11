/**
 * Copyright since 2026 Massimiliano Palermo
 * @license AFL-3.0
 *
 * I campi sconto (data-field="detail-discount") accettano solo valori
 * positivi: un eventuale segno meno digitato viene normalizzato in
 * valore assoluto alla perdita del focus. Il calcolo applica sempre
 * uno sconto (importo = prezzo * (1 - |sconto| / 100)).
 */
document.addEventListener(
    "blur",
    (e) => {
        const el = e.target.closest?.('[data-field="detail-discount"]');
        if (el && el.value !== "") {
            const v = Math.abs(Number(el.value));
            el.value = Number.isFinite(v) ? v : "";
        }
    },
    true
);
