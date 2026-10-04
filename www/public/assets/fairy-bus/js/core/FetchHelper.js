/**
 * Copyright since 2026 Massimiliano Palermo
 * @license AFL-3.0
 */

export default class FetchHelper {
    static encodeFormData(data) {
        const params = new URLSearchParams();
        for (const [key, value] of Object.entries(data)) {
            if (value === undefined || value === null) {
                continue;
            }
            if (Array.isArray(value)) {
                value.forEach((v) => params.append(key, v));
            } else if (typeof value === "object") {
                params.append(key, JSON.stringify(value));
            } else {
                params.append(key, String(value));
            }
        }
        return params;
    }

    static get(url, data = {}, signal = null) {
        const query = data ? "?" + this.encodeFormData(data).toString() : "";
        return fetch(url + query, {
            method: "GET",
            headers: { "X-Requested-With": "XMLHttpRequest" },
            signal,
        }).then((response) => this._handle(response));
    }

    static post(url, data = {}, signal = null) {
        const payload = { ...data };
        if (typeof window !== "undefined" && window.FB && window.FB.csrf) {
            payload[window.FB.csrf.name] = window.FB.csrf.value;
        }

        return fetch(url, {
            method: "POST",
            headers: {
                "Content-Type": "application/x-www-form-urlencoded",
                "X-Requested-With": "XMLHttpRequest",
            },
            body: this.encodeFormData(payload),
            signal,
        }).then((response) => this._handle(response));
    }

    static postMultipart(url, formData, signal = null) {
        return fetch(url, {
            method: "POST",
            headers: { "X-Requested-With": "XMLHttpRequest" },
            body: formData,
            signal,
        }).then((response) => this._handle(response));
    }

    static abortable() {
        const controller = new AbortController();
        return {
            signal: controller.signal,
            abort: () => controller.abort(),
        };
    }

    static _handle(response) {
        if (!response.ok) {
            return response.text().then((text) => {
                const err = new Error(`HTTP ${response.status}: ${text || response.statusText}`);
                err.status = response.status;
                try {
                    err.payload = JSON.parse(text);
                } catch {
                    err.payload = null;
                }
                throw err;
            });
        }
        const contentType = response.headers.get("content-type") || "";
        if (contentType.includes("application/json")) {
            return response.json();
        }
        return response.text();
    }
}
