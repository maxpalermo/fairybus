/**
 * Copyright since 2026 Massimiliano Palermo
 * @license AFL-3.0
 */

/**
 * Toast.js — Notifiche toast riutilizzabili.
 *
 * Uso:
 *   const toast = new Toast();
 *   toast.showToast({ content: "<b>Salvato</b>", type: "success", position: "center", duration: 5000 });
 *   toast.showToastSuccess("Salvato.");                        // position/duration opzionali
 *   toast.showToastError("<em>Errore</em>", "top-right", 8000);
 *
 * Posizioni: top-left | top-center | top-right | center | bottom-left | bottom-center | bottom-right
 * Tipi: notice | success | warning | error
 * Il contenuto e' sempre interpretato come HTML.
 * Il timer di scomparsa si sospende in hover e riprende all'uscita del mouse.
 */
export default class Toast {
    static DEFAULT_DURATION = 5000;
    static DEFAULT_POSITION = "center";

    static POSITIONS = ["top-left", "top-center", "top-right", "center", "bottom-left", "bottom-center", "bottom-right"];
    static TYPES = ["notice", "success", "warning", "error"];

    static ICONS = {
        notice: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>',
        success: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
        warning: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
        error: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
    };

    constructor() {
        this.containers = {};
        // default globali configurabili in Impostazioni > Interfaccia (window.FB.toast)
        const prefs = (typeof window !== "undefined" && window.FB && window.FB.toast) || {};
        this.defaults = {
            position: Toast.POSITIONS.includes(prefs.position) ? prefs.position : Toast.DEFAULT_POSITION,
            duration: Number.isFinite(prefs.duration) ? prefs.duration : Toast.DEFAULT_DURATION,
            banner: prefs.banner === true,
        };
    }

    /**
     * Mostra un toast.
     * @param {object} options
     * @param {string} options.content   - contenuto HTML del toast
     * @param {string} [options.type]    - notice | success | warning | error (default notice)
     * @param {string} [options.position]- posizione (default da Impostazioni, altrimenti center)
     * @param {number} [options.duration]- ms di permanenza, 0 = persistente (default da Impostazioni, altrimenti 5000)
     * @param {boolean}[options.banner]  - true = banner a tutta larghezza, senza X, click-to-dismiss (default da Impostazioni)
     * @returns {HTMLElement} l'elemento toast
     */
    showToast({ content, type = "notice", position, duration, banner }) {
        if (!Toast.TYPES.includes(type)) type = "notice";
        if (!Toast.POSITIONS.includes(position)) position = this.defaults.position;
        if (duration === undefined) duration = this.defaults.duration;
        if (banner === undefined) banner = this.defaults.banner;

        const container = this._getContainer(position, banner);
        const toast = document.createElement("div");
        toast.className = `fb-toast fb-toast-${type}${banner ? " fb-toast-banner" : ""}`;
        toast.setAttribute("role", "status");
        toast.innerHTML = `
            ${banner ? "" : `<span class="fb-toast-icon">${Toast.ICONS[type] || Toast.ICONS.notice}</span>`}
            <div class="fb-toast-content">${content}</div>
            ${
                banner
                    ? ""
                    : `
            <button type="button" class="fb-toast-close" aria-label="Chiudi">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>`
            }
        `;

        const dismiss = () => {
            toast.classList.add("fb-toast-out");
            setTimeout(() => toast.remove(), 200);
        };
        if (banner) {
            toast.addEventListener("click", dismiss);
        } else {
            toast.querySelector(".fb-toast-close").addEventListener("click", dismiss);
        }

        // pausa in hover: salva il tempo residuo e riavvia all'uscita
        if (duration > 0) {
            let remaining = duration;
            let startedAt = Date.now();
            let timer = setTimeout(dismiss, remaining);

            toast.addEventListener("mouseenter", () => {
                clearTimeout(timer);
                remaining -= Date.now() - startedAt;
            });
            toast.addEventListener("mouseleave", () => {
                startedAt = Date.now();
                timer = setTimeout(dismiss, Math.max(remaining, 0));
            });
        }

        container.appendChild(toast);
        return toast;
    }

    showToastNotice(content, position, duration, banner) {
        return this.showToast({ content, type: "notice", position, duration, banner });
    }

    showToastSuccess(content, position, duration, banner) {
        return this.showToast({ content, type: "success", position, duration, banner });
    }

    showToastWarning(content, position, duration, banner) {
        return this.showToast({ content, type: "warning", position, duration, banner });
    }

    showToastError(content, position, duration, banner) {
        return this.showToast({ content, type: "error", position, duration, banner });
    }

    _getContainer(position, banner = false) {
        let key = position;
        let className = `fb-toast-container fb-toast-pos-${position}`;
        if (banner) {
            // il banner occupa tutta la larghezza: conta solo l'ancora verticale
            const v = position.startsWith("top") ? "top" : position.startsWith("bottom") ? "bottom" : "center";
            key = `banner-${v}`;
            className = `fb-toast-container fb-toast-banner-wrap fb-toast-pos-${v}`;
        }
        if (!this.containers[key]) {
            const el = document.createElement("div");
            el.className = className;
            document.body.appendChild(el);
            this.containers[key] = el;
        }
        return this.containers[key];
    }
}
