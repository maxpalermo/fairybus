/**
 * Copyright since 2026 Massimiliano Palermo
 * @license AFL-3.0
 *
 * Select ricercabile per i filtri colonna di Bootstrap Table.
 * Il <select> originale resta nel DOM (l'estensione filter-control legge il
 * suo valore), viene nascosto e sostituito da un input con dropdown
 * position:fixed su <body>: mai clippato da header/container con overflow.
 *
 * Uso: window.fbInitTableFilterSelects(tableElement) — invocato dal layout
 * sull'evento created-controls.bs.table.
 */
(function () {
    "use strict";

    function enhance(select) {
        if (select.dataset.fbTfs) return;
        select.dataset.fbTfs = "1";

        const wrapper = select.closest(".filter-control") || select.parentElement;
        select.style.display = "none";

        const input = document.createElement("input");
        input.type = "text";
        input.className = "fb-tfs-input";
        input.autocomplete = "off";
        input.placeholder = "Tutti";
        select._fbTfsInput = input;
        const cur = select.options[select.selectedIndex];
        if (cur && cur.value !== "") input.value = cur.text;
        wrapper.appendChild(input);

        let drop = null;

        // ripristina nell'input il testo dell'opzione attualmente selezionata
        const syncInput = () => {
            const o = select.options[select.selectedIndex];
            input.value = o && o.value !== "" ? o.text : "";
        };

        const close = () => {
            if (drop) {
                drop.remove();
                drop = null;
                syncInput();
            }
        };

        const pick = (opt) => {
            select.value = opt.value;
            input.value = opt.value === "" ? "" : opt.text;
            // jQuery trigger: i listener delegati di filter-control sono jQuery
            if (window.jQuery) {
                window.jQuery(select).trigger("change");
            } else {
                select.dispatchEvent(new Event("change", { bubbles: true }));
            }
            close();
        };

        const render = (query) => {
            drop.innerHTML = "";
            const q = (query || "").trim().toLowerCase();
            const opts = Array.from(select.options).map((o) => ({ value: o.value, text: o.text }));
            if (!opts.some((o) => o.value === "")) {
                opts.unshift({ value: "", text: "— Tutti —" });
            }
            const list = opts.filter((o) => !q || o.text.toLowerCase().includes(q));

            if (!list.length) {
                const none = document.createElement("div");
                none.className = "fb-tfs-item fb-tfs-empty";
                none.textContent = "Nessun risultato";
                drop.appendChild(none);
                return;
            }

            list.forEach((opt) => {
                const item = document.createElement("div");
                item.className = "fb-tfs-item" + (opt.value === select.value ? " fb-tfs-selected" : "");
                item.textContent = opt.text;
                item.addEventListener("mousedown", (e) => {
                    e.preventDefault();
                    pick(opt);
                });
                drop.appendChild(item);
            });
        };

        const position = () => {
            const r = input.getBoundingClientRect();
            drop.style.left = r.left + "px";
            drop.style.top = r.bottom + 4 + "px";
            drop.style.width = Math.max(r.width, 180) + "px";
        };

        const open = () => {
            if (drop) return;
            // all'apertura la casella si svuota per permettere la ricerca
            input.value = "";
            drop = document.createElement("div");
            drop.className = "fb-tfs-drop";
            render("");
            document.body.appendChild(drop);
            position();
        };

        // i listener delegati dell'estensione (keyup/change/mouseup) non devono
        // intercettare il nostro input: altrimenti il testo digitato finisce
        // nel filtro della colonna
        ["keyup", "keydown", "change", "mouseup", "paste"].forEach((ev) => input.addEventListener(ev, (e) => e.stopPropagation()));

        input.addEventListener("focus", open);
        input.addEventListener("click", open); // riapre se l'input era già focused
        input.addEventListener("input", () => {
            if (!drop) open();
            else render(input.value);
        });
        input.addEventListener("keydown", (e) => {
            if (e.key === "Escape") {
                close();
                input.blur();
            } else if (e.key === "Enter" && drop) {
                e.preventDefault();
                const first = drop.querySelector(".fb-tfs-item:not(.fb-tfs-empty)");
                if (first) first.dispatchEvent(new Event("mousedown"));
            }
        });
        document.addEventListener("mousedown", (e) => {
            if (drop && !drop.contains(e.target) && e.target !== input) close();
        });
        window.addEventListener("scroll", close, true);
        window.addEventListener("resize", close);
    }

    window.fbInitTableFilterSelects = function (root) {
        const selects = () => root.querySelectorAll('.filter-control select, select[class*="bootstrap-table-filter-control-"]');
        selects().forEach(enhance);

        // l'estensione ripristina i valori dei filtri (Tc) ad ogni re-render:
        // rispecchia il valore del <select> nascosto nel nostro input
        const sync = () =>
            selects().forEach((s) => {
                if (!s._fbTfsInput) return;
                const o = s.options[s.selectedIndex];
                s._fbTfsInput.value = o && o.value !== "" ? o.text : "";
            });
        if (window.jQuery) {
            window.jQuery(root).off("post-body.fbTfs reset-view.fbTfs").on("post-body.fbTfs reset-view.fbTfs", sync);
        }
        setTimeout(sync, 0);
    };
})();
