/**
 * Copyright since 2026 Massimiliano Palermo
 * @license AFL-3.0
 */

export default class ToastHelper {
    constructor(containerSelector = 'body') {
        this.container = document.querySelector(containerSelector);
        this.ensureContainer();
    }

    ensureContainer() {
        if (!this.container) {
            this.container = document.body;
        }
        this.el = document.querySelector('.fb-toast-container');
        if (!this.el) {
            this.el = document.createElement('div');
            this.el.className = 'fb-toast-container';
            this.container.appendChild(this.el);
        }
    }

    show(message, type = 'info', duration = 4000) {
        const toast = document.createElement('div');
        toast.className = `fb-toast ${type}`;
        toast.setAttribute('role', 'status');
        toast.textContent = message;

        this.el.appendChild(toast);

        if (duration > 0) {
            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateX(1rem)';
                setTimeout(() => toast.remove(), 200);
            }, duration);
        }

        return toast;
    }

    success(message, duration) {
        return this.show(message, 'success', duration);
    }

    error(message, duration) {
        return this.show(message, 'error', duration);
    }

    warning(message, duration) {
        return this.show(message, 'warning', duration);
    }

    info(message, duration) {
        return this.show(message, 'info', duration);
    }
}
