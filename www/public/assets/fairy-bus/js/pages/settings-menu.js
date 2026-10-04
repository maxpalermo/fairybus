/**
 * Copyright since 2026 Massimiliano Palermo
 * @license AFL-3.0
 *
 * Editor drag&drop della struttura del menu (tab Menu in Impostazioni).
 * Carica/salva via api/settings/menu — struttura a 2 livelli:
 * voce principale (icona + eventuali sottomenu) -> sottomenu (con route).
 */
import FetchHelper from "../core/FetchHelper.js";
import DialogHelper from "../core/DialogHelper.js";
import Toast from "../core/Toast.js";

const dialog = new DialogHelper();
const toast = new Toast();
const BASE = window.FB.baseUrl;

let meta = null;
let inited = false;
let dragEl = null;

function escapeHtml(text) {
    const div = document.createElement("div");
    div.textContent = String(text ?? "");
    return div.innerHTML;
}

function optionList(items, current) {
    return items.map((o) => `<option value="${escapeHtml(o.value)}"${o.value === current ? " selected" : ""}>${escapeHtml(o.label)}</option>`).join("");
}

function colorSelect(cls, value) {
    const opts = meta.colors.map((c) => `<option value="${escapeHtml(c.value)}"${c.value === value ? " selected" : ""}${c.value ? ` style="background:${c.value};color:#fff;text-shadow:0 0 2px #000"` : ""}>${escapeHtml(c.label)}${c.value ? ` — ${c.value}` : ""}</option>`).join("");
    return `<select class="fb-form-input ${cls}">${opts}</select>`;
}

function buildItem(node, level) {
    const li = document.createElement("li");
    li.className = "fb-menu-item";
    li.dataset.level = String(level);
    li.draggable = true;
    li.innerHTML = `
        <div class="fb-menu-row">
            <span class="fb-menu-drag" title="Trascina">&#8942;&#8942;</span>
            <input type="text" class="fb-form-input fb-menu-title" maxlength="255" placeholder="Titolo" value="${escapeHtml(node.title || "")}">
            <input type="text" class="fb-form-input fb-menu-desc" maxlength="255" placeholder="Descrizione" value="${escapeHtml(node.description || "")}">
            <select class="fb-form-input fb-menu-icon" title="Icona">${optionList([{ value: "", label: "— Icona —" }, ...meta.icons], node.icon || "")}</select>
            ${colorSelect("fb-menu-bg", node.color_bg || "")}
            ${colorSelect("fb-menu-fg", node.color_fg || "")}
            <select class="fb-form-input fb-menu-route" title="Route">${optionList([{ value: "", label: "— Nessuna route —" }, ...meta.routes], node.route || "")}</select>
            <button type="button" class="fb-btn-icon fb-menu-add-child" title="Aggiungi sottomenu">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            </button>
            <button type="button" class="fb-btn-icon fb-btn-icon-danger fb-menu-del" title="Elimina voce">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
            </button>
        </div>
        <ul class="fb-menu-children"></ul>`;

    const ul = li.querySelector(".fb-menu-children");
    (node.children || []).forEach((c) => ul.appendChild(buildItem(c, 1)));
    syncItemState(li);
    return li;
}

/** Adatta i controlli al livello e al numero di figli. */
function syncItemState(li) {
    const level = Number(li.dataset.level);
    const childrenUl = li.querySelector(":scope > .fb-menu-children");
    const hasChildren = childrenUl.children.length > 0;
    const routeSel = li.querySelector(".fb-menu-route");
    const iconSel = li.querySelector(".fb-menu-icon");
    const addBtn = li.querySelector(".fb-menu-add-child");

    if (level === 1) {
        childrenUl.style.display = "none";
        iconSel.style.display = "none";
        addBtn.style.display = "none";
        routeSel.disabled = false;
    } else {
        childrenUl.style.display = "";
        iconSel.style.display = "";
        addBtn.style.display = "";
        routeSel.disabled = hasChildren;
        if (hasChildren) routeSel.value = ""; // i genitori non hanno route
    }
}

function serialize(li) {
    const row = li.querySelector(":scope > .fb-menu-row");
    return {
        title: row.querySelector(".fb-menu-title").value.trim(),
        description: row.querySelector(".fb-menu-desc").value.trim(),
        icon: row.querySelector(".fb-menu-icon").value,
        color_bg: row.querySelector(".fb-menu-bg").value,
        color_fg: row.querySelector(".fb-menu-fg").value,
        route: row.querySelector(".fb-menu-route").value,
        children: [...li.querySelector(":scope > .fb-menu-children").children].map(serialize),
    };
}

async function load(source = "") {
    const res = await FetchHelper.get(`${BASE}api/settings/menu${source ? `?source=${source}` : ""}`);
    meta = res.meta;
    render(res.menu);
    document.getElementById("menu-custom-switch").checked = !!res.custom;
    document.getElementById("menu-replica-note").style.display = res.is_replica || source === "default" ? "" : "none";
}

function render(tree) {
    const wrap = document.getElementById("menu-tree");
    wrap.innerHTML = "";
    const ul = document.createElement("ul");
    ul.className = "fb-menu-list";
    tree.forEach((node) => ul.appendChild(buildItem(node, 0)));
    wrap.appendChild(ul);
}

// --- Drag & drop ------------------------------------------------------------

function levelOf(li) {
    return Number(li.dataset.level);
}

function clearDropHints() {
    document.querySelectorAll(".fb-menu-item.drop-before,.fb-menu-item.drop-after,.fb-menu-item.drop-inside").forEach((el) => el.classList.remove("drop-before", "drop-after", "drop-inside"));
}

function dropZone(li, e) {
    const r = li.querySelector(":scope > .fb-menu-row").getBoundingClientRect();
    const y = (e.clientY - r.top) / r.height;
    if (levelOf(li) === 0) {
        if (y < 0.33) return "before";
        if (y > 0.67) return "after";
        return "inside";
    }
    return y < 0.5 ? "before" : "after";
}

function draggedHasChildren() {
    return dragEl && dragEl.querySelector(":scope > .fb-menu-children").children.length > 0;
}

function onDragStart(e) {
    const li = e.target.closest(".fb-menu-item");
    if (!li) return;
    dragEl = li;
    e.dataTransfer.effectAllowed = "move";
    setTimeout(() => li.classList.add("dragging"), 0);
}

function onDragOver(e) {
    const li = e.target.closest(".fb-menu-item");
    if (!li || !dragEl || li === dragEl || dragEl.contains(li)) return;
    e.preventDefault();
    const zone = dropZone(li, e);
    clearDropHints();
    // vietato: una voce con figli non puo' diventare sottomenu
    if ((zone === "inside" || levelOf(li) === 1) && draggedHasChildren()) {
        li.classList.add("drop-inside");
        li.dataset.invalid = "1";
        return;
    }
    li.dataset.invalid = "";
    li.classList.add(`drop-${zone}`);
}

function onDrop(e) {
    const li = e.target.closest(".fb-menu-item");
    if (!li || !dragEl) return;
    e.preventDefault();
    const zone = dropZone(li, e);
    const invalid = (zone === "inside" || levelOf(li) === 1) && draggedHasChildren();
    clearDropHints();
    if (invalid) {
        toast.showToastWarning("Una voce con sottomenu non può diventare un sottomenu.");
        return;
    }

    if (zone === "inside") {
        li.querySelector(":scope > .fb-menu-children").appendChild(dragEl);
        dragEl.dataset.level = "1";
    } else if (zone === "before") {
        li.parentElement.insertBefore(dragEl, li);
        dragEl.dataset.level = String(levelOf(li));
    } else {
        li.parentElement.insertBefore(dragEl, li.nextSibling);
        dragEl.dataset.level = String(levelOf(li));
    }

    // risincronizza i controlli di tutti i nodi coinvolti
    document.querySelectorAll(".fb-menu-item").forEach(syncItemState);
}

function onDragEnd() {
    clearDropHints();
    if (dragEl) dragEl.classList.remove("dragging");
    dragEl = null;
}

// --- Init -------------------------------------------------------------------

function bindEvents() {
    const tree = document.getElementById("menu-tree");

    tree.addEventListener("dragstart", onDragStart);
    tree.addEventListener("dragover", onDragOver);
    tree.addEventListener("dragleave", (e) => {
        const li = e.target.closest(".fb-menu-item");
        if (li && !li.contains(e.relatedTarget)) clearDropHints();
    });
    tree.addEventListener("drop", onDrop);
    tree.addEventListener("dragend", onDragEnd);

    tree.addEventListener("click", async (e) => {
        const addBtn = e.target.closest(".fb-menu-add-child");
        if (addBtn) {
            const li = addBtn.closest(".fb-menu-item");
            const child = buildItem({ title: "" }, 1);
            li.querySelector(":scope > .fb-menu-children").appendChild(child);
            syncItemState(li);
            child.scrollIntoView?.({ behavior: "smooth", block: "center" });
            child.querySelector(".fb-menu-title").focus();
            return;
        }
        const delBtn = e.target.closest(".fb-menu-del");
        if (delBtn) {
            const li = delBtn.closest(".fb-menu-item");
            const n = li.querySelector(":scope > .fb-menu-children").children.length;
            const title = li.querySelector(".fb-menu-title").value || "voce";
            const ok = n > 0 ? await dialog.confirm(`Eliminare <strong>${escapeHtml(title)}</strong> e i suoi ${n} sottomenu?`, "Elimina") : await dialog.confirm(`Eliminare la voce <strong>${escapeHtml(title)}</strong>?`, "Elimina");
            if (ok) li.remove();
        }
    });

    document.querySelector('[data-action="menu-add-root"]').addEventListener("click", () => {
        const list = document.querySelector("#menu-tree .fb-menu-list");
        const li = buildItem({ title: "" }, 0);
        list.prepend(li);
        li.scrollIntoView?.({ behavior: "smooth", block: "center" });
        li.querySelector(".fb-menu-title").focus();
    });

    document.querySelector('[data-action="menu-save"]').addEventListener("click", async () => {
        const roots = [...document.querySelectorAll("#menu-tree .fb-menu-list > .fb-menu-item")].map(serialize);
        const empty = roots.find((r) => r.title === "" || r.children.some((c) => c.title === ""));
        if (empty) {
            await dialog.alert("Ogni voce deve avere un titolo.", "Attenzione");
            return;
        }
        try {
            await FetchHelper.post(`${BASE}api/settings/menu/save`, { tree: JSON.stringify(roots) });
            toast.showToastSuccess("Struttura del menu salvata.");
        } catch (err) {
            await dialog.error(err);
        }
    });

    document.querySelector('[data-action="menu-reset"]').addEventListener("click", async () => {
        const ok = await dialog.confirm("Ripristinare la struttura del menu predefinito? Le modifiche non salvate andranno perse.", "Ripristina");
        if (ok) await load("default");
    });

    document.getElementById("menu-custom-switch").addEventListener("change", async (e) => {
        try {
            const res = await FetchHelper.post(`${BASE}api/settings/menu/custom`, { enabled: e.target.checked ? 1 : 0 });
            toast.showToastSuccess(res.enabled ? "Menu custom attivo." : "Menu predefinito ripristinato.");
        } catch (err) {
            e.target.checked = !e.target.checked;
            await dialog.error(err);
        }
    });
}

async function initMenuEditor() {
    if (inited) return;
    try {
        await load();
        bindEvents();
        inited = true;
    } catch (err) {
        await dialog.error(err);
    }
}

window.fbInitMenuEditor = initMenuEditor;

export { initMenuEditor };
