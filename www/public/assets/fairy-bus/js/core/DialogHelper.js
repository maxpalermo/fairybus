/**
 * Copyright since 2026 Massimiliano Palermo
 * @license AFL-3.0
 */

export default class DialogHelper {
    constructor() {
        this.template = document.getElementById("tpl-fb-dialog");
        if (!this.template) {
            throw new Error("Dialog template #tpl-fb-dialog not found");
        }
    }

    confirm(message, title = "Conferma") {
        return new Promise((resolve) => {
            const dialog = this._create(title, message, ["Sì", "No"]);
            document.body.appendChild(dialog);
            dialog.showModal();

            dialog.addEventListener(
                "close",
                () => {
                    const result = dialog.returnValue;
                    resolve(result === "yes");
                    dialog.remove();
                },
                { once: true },
            );
        });
    }

    alert(message, title = "Attenzione") {
        return new Promise((resolve) => {
            const dialog = this._create(title, message, ["OK"]);
            document.body.appendChild(dialog);
            dialog.showModal();

            dialog.addEventListener(
                "close",
                () => {
                    resolve(true);
                    dialog.remove();
                },
                { once: true },
            );
        });
    }

    /**
     * Dialog di errore con icona e stile rosso. Accetta un Error di FetchHelper
     * (err.payload.errors = errori di validazione API) o un messaggio HTML.
     */
    error(err, title = "Errore") {
        const icon = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>';

        let body;
        const errors = err && err.payload && err.payload.errors;
        if (errors && typeof errors === "object") {
            const items = Object.entries(errors)
                .map(([field, msg]) => `<li><strong>${this._esc(field)}</strong>: ${this._esc(msg)}</li>`)
                .join("");
            body = `<div class="fb-alert fb-alert-error">${icon}<ul class="fb-error-list">${items}</ul></div>`;
        } else {
            const msg = err && err.payload && err.payload.message ? err.payload.message : err instanceof Error ? err.message : String(err);
            body = `<div class="fb-alert fb-alert-error">${icon}<div>${this._esc(msg)}</div></div>`;
        }

        return this.alert(body, title);
    }

    _esc(str) {
        const div = document.createElement("div");
        div.textContent = String(str);
        return div.innerHTML;
    }

    _create(title, body, buttons) {
        const clone = this.template.content.cloneNode(true);
        const dialog = clone.querySelector("dialog");
        const titleEl = dialog.querySelector('[data-slot="title"]');
        const bodyEl = dialog.querySelector('[data-slot="body"]');
        const footerEl = dialog.querySelector('[data-slot="footer"]');

        if (titleEl) titleEl.textContent = title;
        if (bodyEl) bodyEl.innerHTML = body;

        const closeBtn = dialog.querySelector('[data-slot="close"]');
        if (closeBtn) {
            closeBtn.addEventListener("click", () => dialog.close("no"));
        }

        if (footerEl) {
            footerEl.innerHTML = "";
            buttons.forEach((label) => {
                const btn = document.createElement("button");
                btn.type = "button";
                btn.className = "fb-btn";
                btn.textContent = label;
                if (label.toLowerCase() === "sì") {
                    btn.classList.add("fb-btn-primary");
                    btn.value = "yes";
                } else if (label.toLowerCase() === "no") {
                    btn.classList.add("fb-btn-secondary");
                    btn.value = "no";
                } else {
                    btn.classList.add("fb-btn-secondary");
                    btn.value = "ok";
                }
                btn.addEventListener("click", () => dialog.close(btn.value));
                footerEl.appendChild(btn);
            });
        }

        return dialog;
    }
}
