import FetchHelper from "../core/FetchHelper.js";
import DialogHelper from "../core/DialogHelper.js";
import Toast from "../core/Toast.js";
import PasswordToggle from "../core/PasswordToggle.js";

window.fbActiveFilterOptions = { 0: "No", 1: "Sì" };

const dialog = new DialogHelper();
const toast = new Toast();
let allRoles = [];
let allPermissions = [];

function initUsersTable() {
    if (!window.$ || !window.$.fn.bootstrapTable) {
        return false;
    }

    const $table = window.$("#users-table");
    if (!$table.length || $table.data("bootstrap.table")) {
        return true;
    }

    $table.bootstrapTable({
        url: window.FB.baseUrl + "api/settings/users",
        sidePagination: "client",
        pagination: true,
        search: false,
        filterControl: true,
        sortable: true,
        locale: "it-IT",
        onLoadSuccess: function (res) {
            allRoles = res.all_roles || [];
        },
        columns: [
            { field: "state", checkbox: true, align: "center", valign: "middle" },
            { field: "id", title: "ID", sortable: true, sorter: (a, b) => Number(a || 0) - Number(b || 0), align: "center", filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "full_name", title: "Nome", sortable: true, filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "email", title: "Email", sortable: true, filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "active", title: "Attivo", formatter: formatUserActive, searchFormatter: false, filterControl: "select", filterData: "var:fbActiveFilterOptions" },
            { field: "roles", title: "Ruoli", formatter: formatUserRoles, filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "actions", title: "Azioni", formatter: formatUserActions, events: userActionsEvents },
        ],
    });

    $table.on("load-error.bs.table", (ev, status, res) => {
        console.error("Errore caricamento utenti:", status, res);
    });

    return true;
}

function waitForBootstrapTable(callback) {
    let attempts = 0;
    const maxAttempts = 50;
    const timer = setInterval(() => {
        if (initUsersTable() || ++attempts >= maxAttempts) {
            clearInterval(timer);
            if (attempts >= maxAttempts) {
                console.error("Bootstrap Table non disponibile");
            }
        }
    }, 100);
}

/* ---------- Tipi di documento ---------- */

function initDocTypesTable() {
    if (!window.$ || !window.$.fn.bootstrapTable) {
        setTimeout(initDocTypesTable, 100);
        return;
    }

    const $table = window.$("#doctypes-table");
    if (!$table.length || $table.data("bootstrap.table")) {
        return;
    }

    $table.bootstrapTable({
        url: window.FB.baseUrl + "api/settings/document-types",
        sidePagination: "client",
        pagination: true,
        search: false,
        filterControl: true,
        sortable: true,
        locale: "it-IT",
        columns: [
            { field: "id", title: "ID", sortable: true, align: "center", filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "name", title: "Nome", sortable: true, filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "description", title: "Descrizione", formatter: (v) => escapeHtml(v || "—"), filterControl: "input", filterCustomSearch: window.fbFilterSearch },
            { field: "actions", title: "Azioni", formatter: window.formatDocTypeActions, events: window.doctypeActionsEvents },
        ],
    });
}

window.formatDocTypeActions = function (value, row) {
    const del = Number(row.id) === 0 ? '<span class="fb-muted" title="Il tipo Default non è eliminabile">—</span>' : `<button type="button" class="fb-btn fb-btn-secondary fb-btn-sm" data-action="delete-doctype" data-id="${row.id}">Elimina</button>`;
    return `<button type="button" class="fb-btn fb-btn-secondary fb-btn-sm" data-action="edit-doctype" data-id="${row.id}">Modifica</button> ${del}`;
};

window.doctypeActionsEvents = {
    'click [data-action="edit-doctype"]': function (e, value, row) {
        openDocTypeForm(row);
    },
    'click [data-action="delete-doctype"]': async function (e, value, row) {
        const ok = await dialog.confirm(`Eliminare il tipo di documento <strong>${escapeHtml(row.name)}</strong>?`, "Conferma");
        if (!ok) return;
        try {
            await FetchHelper.post(`${window.FB.baseUrl}api/settings/document-types/${row.id}/delete`, {});
            window.$("#doctypes-table").bootstrapTable("refresh");
        } catch (err) {
            await dialog.error(err);
        }
    },
};

function openDocTypeForm(row = null) {
    const tpl = document.getElementById("tpl-doctype-form");
    const form = tpl.content.cloneNode(true).querySelector("form");

    if (row) {
        form.querySelector('[name="id"]').value = row.id;
        form.querySelector('[name="name"]').value = row.name || "";
        form.querySelector('[name="description"]').value = row.description || "";
    }

    const d = document.createElement("dialog");
    d.className = "fb-dialog";
    d.innerHTML = `<div class="fb-dialog-header"><h3 class="fb-dialog-title">${row ? "Modifica tipo documento" : "Nuovo tipo documento"}</h3></div><div class="fb-dialog-body"></div>`;
    d.querySelector(".fb-dialog-body").appendChild(form);
    document.body.appendChild(d);
    d.showModal();

    form.addEventListener("submit", async (ev) => {
        ev.preventDefault();
        const data = Object.fromEntries(new FormData(form).entries());
        const id = data.id;
        const url = id ? `${window.FB.baseUrl}api/settings/document-types/${id}/update` : `${window.FB.baseUrl}api/settings/document-types`;
        try {
            await FetchHelper.post(url, data);
            d.close();
            window.$("#doctypes-table").bootstrapTable("refresh");
        } catch (err) {
            await dialog.error(err);
        }
    });

    form.querySelector('[data-action="close"]').addEventListener("click", () => d.close());
    d.addEventListener("close", () => d.remove(), { once: true });
}

function initNewDocTypeButton() {
    document.querySelector('[data-action="new-doctype"]')?.addEventListener("click", () => openDocTypeForm());
}

function initTabs() {
    const tabs = document.querySelectorAll(".fb-tab");
    tabs.forEach((tab) => {
        tab.addEventListener("click", () => {
            tabs.forEach((t) => {
                t.classList.remove("active");
                t.setAttribute("aria-selected", "false");
            });
            document.querySelectorAll(".fb-tab-panel").forEach((p) => p.classList.remove("active"));

            tab.classList.add("active");
            tab.setAttribute("aria-selected", "true");
            const panel = document.getElementById(tab.getAttribute("aria-controls"));
            if (panel) {
                panel.classList.add("active");
                if (tab.dataset.tab === "permissions") {
                    loadRoles();
                    loadPermissions();
                }
                if (tab.dataset.tab === "menu" && window.fbInitMenuEditor) {
                    window.fbInitMenuEditor();
                }
                if (tab.dataset.tab === "doctypes") {
                    initDocTypesTable();
                }
            }
        });
    });
}

window.formatUserActive = function (value) {
    const active = value === true || value === 1 || value === "1";
    return `<span class="fb-badge ${active ? "fb-badge-success" : "fb-badge-danger"}">${active ? "Attivo" : "Disattivato"}</span>`;
};

window.formatUserRoles = function (roles) {
    if (!Array.isArray(roles) || roles.length === 0) {
        return "-";
    }
    return roles.map((r) => `<span class="fb-badge">${escapeHtml(r)}</span>`).join(" ");
};

window.formatUserActions = function (id, row) {
    return `
        <button type="button" class="fb-btn fb-btn-secondary fb-btn-sm" data-action="edit-user" data-id="${id}">Modifica</button>
        <button type="button" class="fb-btn fb-btn-secondary fb-btn-sm" data-action="reset-password" data-id="${id}">Password</button>
        <button type="button" class="fb-btn fb-btn-secondary fb-btn-sm" data-action="toggle-active" data-id="${id}" data-active="${row.active ? 1 : 0}">${row.active ? "Disattiva" : "Attiva"}</button>
        <button type="button" class="fb-btn fb-btn-secondary fb-btn-sm" data-action="delete-user" data-id="${id}">Elimina</button>
    `;
};

window.userActionsEvents = {
    'click [data-action="edit-user"]': function (e, value, row) {
        openUserForm(row);
    },
    'click [data-action="reset-password"]': function (e, value, row) {
        resetPassword(row.id);
    },
    'click [data-action="toggle-active"]': function (e, value, row) {
        toggleUserActive(row.id);
    },
    'click [data-action="delete-user"]': function (e, value, row) {
        deleteUser(row.id);
    },
};

function escapeHtml(text) {
    const div = document.createElement("div");
    div.textContent = String(text ?? "");
    return div.innerHTML;
}

function openUserForm(user = null) {
    const tpl = document.getElementById("tpl-user-form");
    const form = tpl.content.cloneNode(true).querySelector("form");
    const title = user ? "Modifica utente" : "Nuovo utente";

    const rolesSelect = form.querySelector('[name="role_ids[]"]');
    const selectedIds = user ? (user.role_ids || []).map(Number) : [];
    rolesSelect.innerHTML = allRoles.map((role) => `<option value="${role.id}" ${selectedIds.includes(Number(role.id)) ? "selected" : ""}>${escapeHtml(role.name)}</option>`).join("");

    if (user) {
        form.querySelector('[name="id"]').value = user.id;
        form.querySelector('[name="first_name"]').value = user.first_name || "";
        form.querySelector('[name="last_name"]').value = user.last_name || "";
        form.querySelector('[name="email"]').value = user.email || "";
        const passGroup = form.querySelector('[data-field="password"]');
        if (passGroup) passGroup.remove();
    }

    const d = document.createElement("dialog");
    d.className = "fb-dialog";
    d.innerHTML = `<div class="fb-dialog-header"><h3 class="fb-dialog-title">${escapeHtml(title)}</h3></div><div class="fb-dialog-body"></div>`;
    d.querySelector(".fb-dialog-body").appendChild(form);
    PasswordToggle.init(form);
    document.body.appendChild(d);
    d.showModal();

    form.addEventListener("submit", async (ev) => {
        ev.preventDefault();
        const formData = new FormData(form);
        const data = Object.fromEntries(formData.entries());
        const selectedRoles = formData.getAll("role_ids[]").map(Number);
        data.role_ids = JSON.stringify(selectedRoles);
        delete data["role_ids[]"];
        const url = user ? `${window.FB.baseUrl}api/settings/users/${user.id}/update` : `${window.FB.baseUrl}api/settings/users`;
        try {
            await FetchHelper.post(url, data);
            d.close();
            refreshUsersTable();
        } catch (err) {
            await dialog.error(err);
        }
    });

    form.querySelector('[data-action="close"]').addEventListener("click", () => d.close());
    d.addEventListener("close", () => d.remove(), { once: true });
}

async function resetPassword(userId) {
    const password = window.prompt("Inserisci la nuova password (min. 6 caratteri):");
    if (!password || password.length < 6) {
        return;
    }
    try {
        await FetchHelper.post(`${window.FB.baseUrl}api/settings/users/${userId}/reset-password`, { password });
        await dialog.alert("Password aggiornata.", "OK");
        refreshUsersTable();
    } catch (err) {
        await dialog.error(err);
    }
}

async function toggleUserActive(userId) {
    try {
        await FetchHelper.post(`${window.FB.baseUrl}api/settings/users/${userId}/toggle-active`, {});
        refreshUsersTable();
    } catch (err) {
        await dialog.error(err);
    }
}

async function deleteUser(userId) {
    const ok = await dialog.confirm("Eliminare definitivamente questo utente?", "Conferma");
    if (!ok) return;
    try {
        await FetchHelper.post(`${window.FB.baseUrl}api/settings/users/${userId}/delete`, {});
        refreshUsersTable();
    } catch (err) {
        await dialog.error(err);
    }
}

function refreshUsersTable() {
    const $table = window.$(document.getElementById("users-table"));
    if ($table && typeof $table.bootstrapTable === "function") {
        $table.bootstrapTable("refresh");
    }
}

async function loadImports() {
    let tbody;
    try {
        const res = await FetchHelper.get(`${window.FB.baseUrl}api/imports/tables`);
        tbody = document.querySelector("#imports-table tbody");
        tbody.innerHTML = "";

        if (!res.tables || res.tables.length === 0) {
            tbody.innerHTML = `<tr><td colspan="3" style="text-align:center;color:var(--fb-muted-foreground);">Nessuna tabella legacy trovata nel database.</td></tr>`;
            return;
        }

        res.tables.forEach((table) => {
            const tr = document.createElement("tr");
            tr.innerHTML = `
                <td>${escapeHtml(table.name)}</td>
                <td>${table.count.toLocaleString("it-IT")}</td>
                <td>
                    <button type="button" class="fb-btn fb-btn-secondary fb-btn-sm" data-action="import-table" data-name="${escapeHtml(table.name)}">Importa</button>
                </td>
            `;
            tbody.appendChild(tr);
        });

        tbody.querySelectorAll('[data-action="import-table"]').forEach((btn) => {
            btn.addEventListener("click", async () => {
                const tableName = btn.dataset.name;
                if (!(await dialog.confirm(`Importare i dati della tabella "${tableName}"?`, "Conferma"))) {
                    return;
                }
                try {
                    const result = await FetchHelper.post(`${window.FB.baseUrl}api/imports/tables/${encodeURIComponent(tableName)}`, {});
                    await dialog.alert(result.message || "Importazione avviata.", "OK");
                } catch (err) {
                    await dialog.error(err);
                }
            });
        });
    } catch (err) {
        console.error(err);
        if (tbody) {
            tbody.innerHTML = `<tr><td colspan="3" style="text-align:center;color:var(--fb-destructive);">Errore caricamento: ${escapeHtml(err.message)}</td></tr>`;
        }
    }
}

async function loadPermissions() {
    try {
        const res = await FetchHelper.get(`${window.FB.baseUrl}api/settings/permissions`);
        const tbody = document.querySelector("#permissions-table tbody");
        tbody.innerHTML = "";

        res.users.forEach((user) => {
            const roles = (user.roles || []).map((r) => `<span class="fb-badge">${escapeHtml(r)}</span>`).join(" ");
            const perms = (user.permissions || []).map((p) => `<span class="fb-badge">${escapeHtml(p)}</span>`).join(" ");
            const tr = document.createElement("tr");
            tr.innerHTML = `
                <td>${escapeHtml(user.full_name || user.email)}</td>
                <td>${roles || "-"}</td>
                <td>${perms || "-"}</td>
                <td>
                    <button type="button" class="fb-btn fb-btn-secondary fb-btn-sm" data-action="edit-permissions" data-user='${escapeHtml(JSON.stringify(user))}' data-permissions='${escapeHtml(JSON.stringify(res.permissions))}'>Modifica permessi</button>
                </td>
            `;
            tbody.appendChild(tr);
        });

        tbody.querySelectorAll('[data-action="edit-permissions"]').forEach((btn) => {
            btn.addEventListener("click", () => {
                const user = JSON.parse(btn.dataset.user);
                const permissions = JSON.parse(btn.dataset.permissions);
                openPermissionsForm(user, permissions);
            });
        });
    } catch (err) {
        console.error(err);
    }
}

async function loadRoles() {
    try {
        const res = await FetchHelper.get(`${window.FB.baseUrl}api/settings/roles`);
        allPermissions = res.permissions || [];
        const tbody = document.querySelector("#roles-table tbody");
        tbody.innerHTML = "";

        (res.roles || []).forEach((role) => {
            const isAdmin = String(role.name).toLowerCase() === "administrator";
            const perms = (role.permissions || []).map((p) => `<span class="fb-badge">${escapeHtml(p)}</span>`).join(" ");
            const tr = document.createElement("tr");
            tr.innerHTML = `
                <td><strong>${escapeHtml(role.name)}</strong>${role.description ? `<div class="fb-muted">${escapeHtml(role.description)}</div>` : ""}</td>
                <td>${perms || "-"}</td>
                <td>
                    ${isAdmin ? `<span class="fb-muted">Non modificabile</span>` : `<button type="button" class="fb-btn fb-btn-secondary fb-btn-sm" data-action="edit-role-permissions" data-role='${escapeHtml(JSON.stringify(role))}'>Modifica permessi</button>`}
                </td>
            `;
            tbody.appendChild(tr);
        });

        tbody.querySelectorAll('[data-action="edit-role-permissions"]').forEach((btn) => {
            btn.addEventListener("click", () => {
                const role = JSON.parse(btn.dataset.role);
                openRolePermissionsForm(role, allPermissions);
            });
        });
    } catch (err) {
        console.error(err);
    }
}

function openRolePermissionsForm(role, allPermissions) {
    const tpl = document.getElementById("tpl-role-permission-form");
    const form = tpl.content.cloneNode(true).querySelector("form");
    form.querySelector('[name="role_id"]').value = role.id;
    form.querySelector(".fb-role-name").textContent = `${role.name} — permessi del ruolo`;
    const list = form.querySelector(".fb-permission-list");

    const selected = new Set((role.permission_ids || []).map(Number));

    allPermissions.forEach((perm) => {
        const label = document.createElement("label");
        label.className = "fb-checkbox-row";
        label.innerHTML = `
            <input type="checkbox" name="permission_ids" value="${perm.id}" ${selected.has(Number(perm.id)) ? "checked" : ""}>
            <span>${escapeHtml(perm.name)} ${perm.description ? `— ${escapeHtml(perm.description)}` : ""}</span>
        `;
        list.appendChild(label);
    });

    const d = document.createElement("dialog");
    d.className = "fb-dialog";
    d.innerHTML = `<div class="fb-dialog-header"><h3 class="fb-dialog-title">Permessi ruolo</h3></div><div class="fb-dialog-body"></div>`;
    d.querySelector(".fb-dialog-body").appendChild(form);
    document.body.appendChild(d);
    d.showModal();

    form.addEventListener("submit", async (ev) => {
        ev.preventDefault();
        const checked = Array.from(form.querySelectorAll('input[name="permission_ids"]:checked')).map((i) => i.value);
        try {
            await FetchHelper.post(`${window.FB.baseUrl}api/settings/roles/${role.id}/permissions`, { permission_ids: JSON.stringify(checked) });
            d.close();
            loadRoles();
        } catch (err) {
            await dialog.error(err);
        }
    });

    form.querySelector('[data-action="close"]').addEventListener("click", () => d.close());
    d.addEventListener("close", () => d.remove(), { once: true });
}

function openPermissionsForm(user, allPermissions) {
    const tpl = document.getElementById("tpl-permission-form");
    const form = tpl.content.cloneNode(true).querySelector("form");
    form.querySelector('[name="user_id"]').value = user.id;
    form.querySelector(".fb-user-name").textContent = `${user.full_name || user.email} — permessi diretti`;
    const list = form.querySelector(".fb-permission-list");

    const selected = new Set(user.direct_permission_ids || []);

    allPermissions.forEach((perm) => {
        const label = document.createElement("label");
        label.className = "fb-checkbox-row";
        label.innerHTML = `
            <input type="checkbox" name="permission_ids" value="${perm.id}" ${selected.has(perm.id) ? "checked" : ""}>
            <span>${escapeHtml(perm.name)} ${perm.description ? `— ${escapeHtml(perm.description)}` : ""}</span>
        `;
        list.appendChild(label);
    });

    const d = document.createElement("dialog");
    d.className = "fb-dialog";
    d.innerHTML = `<div class="fb-dialog-header"><h3 class="fb-dialog-title">Permessi utente</h3></div><div class="fb-dialog-body"></div>`;
    d.querySelector(".fb-dialog-body").appendChild(form);
    document.body.appendChild(d);
    d.showModal();

    form.addEventListener("submit", async (ev) => {
        ev.preventDefault();
        const checked = Array.from(form.querySelectorAll('input[name="permission_ids"]:checked')).map((i) => i.value);
        try {
            await FetchHelper.post(`${window.FB.baseUrl}api/settings/users/${user.id}/permissions`, { permission_ids: JSON.stringify(checked) });
            d.close();
            loadPermissions();
        } catch (err) {
            await dialog.error(err);
        }
    });

    form.querySelector('[data-action="close"]').addEventListener("click", () => d.close());
    d.addEventListener("close", () => d.remove(), { once: true });
}

function initNewRoleButton() {
    const btn = document.querySelector('[data-action="new-role"]');
    if (btn) {
        btn.addEventListener("click", () => openRoleForm());
    }
}

function openRoleForm() {
    const tpl = document.getElementById("tpl-role-form");
    const form = tpl.content.cloneNode(true).querySelector("form");

    const d = document.createElement("dialog");
    d.className = "fb-dialog";
    d.innerHTML = `<div class="fb-dialog-header"><h3 class="fb-dialog-title">Nuovo ruolo</h3></div><div class="fb-dialog-body"></div>`;
    d.querySelector(".fb-dialog-body").appendChild(form);
    document.body.appendChild(d);
    d.showModal();

    form.addEventListener("submit", async (ev) => {
        ev.preventDefault();
        const data = Object.fromEntries(new FormData(form).entries());
        try {
            const result = await FetchHelper.post(`${window.FB.baseUrl}api/settings/roles`, data);
            d.close();
            await loadRoles();
            openRolePermissionsForm({ id: result.id, name: data.name, description: data.description, permissions: [], permission_ids: [] }, allPermissions);
        } catch (err) {
            await dialog.error(err);
        }
    });

    form.querySelector('[data-action="close"]').addEventListener("click", () => d.close());
    d.addEventListener("close", () => d.remove(), { once: true });
}

function initNewUserButton() {
    const btn = document.querySelector('[data-action="new-user"]');
    if (btn) {
        btn.addEventListener("click", () => openUserForm());
    }
}

function initUserActionDelegation() {
    const table = document.getElementById("users-table");
    if (!table) return;

    table.addEventListener("click", (ev) => {
        const btn = ev.target.closest("[data-action]");
        if (!btn) return;

        const id = parseInt(btn.dataset.id, 10);
        if (!id) return;

        const $table = window.$("#users-table");
        const data = $table.bootstrapTable("getData");
        const row = data.find((r) => r.id === id);
        if (!row) return;

        const action = btn.dataset.action;
        if (action === "edit-user") {
            openUserForm(row);
        } else if (action === "reset-password") {
            resetPassword(id);
        } else if (action === "toggle-active") {
            toggleUserActive(id);
        } else if (action === "delete-user") {
            deleteUser(id);
        }
    });
}

function initImportButtons() {
    document.querySelector('[data-action="import-brands"]')?.addEventListener("click", async () => {
        if (!(await dialog.confirm("Importare le marche dalla tabella legacy vehicle?", "Conferma"))) {
            return;
        }
        try {
            const result = await FetchHelper.post(`${window.FB.baseUrl}api/brands/import-legacy`, {});
            await dialog.alert(result.message || "Importazione completata.", "OK");
        } catch (err) {
            await dialog.error(err);
        }
    });

    document.querySelector('[data-action="import-vehicles"]')?.addEventListener("click", async () => {
        if (!(await dialog.confirm("Importare i veicoli dalla tabella legacy vehicle?", "Conferma"))) {
            return;
        }
        try {
            const result = await FetchHelper.post(`${window.FB.baseUrl}api/vehicles/import-legacy`, {});
            await dialog.alert(result.message || "Importazione completata.", "OK");
        } catch (err) {
            await dialog.error(err);
        }
    });

    document.querySelector('[data-action="import-categories"]')?.addEventListener("click", async () => {
        if (!(await dialog.confirm("Importare le categorie dalla tabella legacy category?", "Conferma"))) {
            return;
        }
        try {
            const result = await FetchHelper.post(`${window.FB.baseUrl}api/categories/import-legacy`, {});
            await dialog.alert(result.message || "Importazione completata.", "OK");
        } catch (err) {
            await dialog.error(err);
        }
    });

    document.querySelector('[data-action="import-products"]')?.addEventListener("click", async () => {
        if (!(await dialog.confirm("Importare i prodotti dalla tabella legacy product?", "Conferma"))) {
            return;
        }
        try {
            const result = await FetchHelper.post(`${window.FB.baseUrl}api/products/import-legacy`, {});
            await dialog.alert(result.message || "Importazione completata.", "OK");
        } catch (err) {
            await dialog.error(err);
        }
    });

    document.querySelector('[data-action="import-stocks"]')?.addEventListener("click", async () => {
        if (!(await dialog.confirm("Importare le giacenze dalla tabella legacy inventory?", "Conferma"))) {
            return;
        }
        try {
            const result = await FetchHelper.post(`${window.FB.baseUrl}api/stocks/import-legacy`, {});
            await dialog.alert(result.message || "Importazione completata.", "OK");
        } catch (err) {
            await dialog.error(err);
        }
    });

    document.querySelector('[data-action="import-suppliers"]')?.addEventListener("click", async () => {
        if (!(await dialog.confirm("Importare i fornitori dalla tabella legacy business_partner?", "Conferma"))) {
            return;
        }
        try {
            const result = await FetchHelper.post(`${window.FB.baseUrl}api/suppliers/import-legacy`, {});
            await dialog.alert(result.message || "Importazione completata.", "OK");
        } catch (err) {
            await dialog.error(err);
        }
    });

    document.querySelector('[data-action="import-customers"]')?.addEventListener("click", async () => {
        if (!(await dialog.confirm("Importare i clienti dalla tabella legacy business_partner?", "Conferma"))) {
            return;
        }
        try {
            const result = await FetchHelper.post(`${window.FB.baseUrl}api/customers/import-legacy`, {});
            await dialog.alert(result.message || "Importazione completata.", "OK");
        } catch (err) {
            await dialog.error(err);
        }
    });

    document.querySelector('[data-action="import-documents"]')?.addEventListener("click", async () => {
        if (!(await dialog.confirm("Importare fatture, documenti e righe dettaglio dalle tabelle legacy invoice, trade e movement?", "Conferma"))) {
            return;
        }
        try {
            const result = await FetchHelper.post(`${window.FB.baseUrl}api/documents/import-legacy`, {});
            await dialog.alert(result.message || "Importazione completata.", "OK");
        } catch (err) {
            await dialog.error(err);
        }
    });

    document.querySelector('[data-action="import-maintenance"]')?.addEventListener("click", async () => {
        if (!(await dialog.confirm("Importare manutenzioni, ricambi, tagliandi, scadenze e registro km dalle tabelle legacy?", "Conferma"))) {
            return;
        }
        try {
            const result = await FetchHelper.post(`${window.FB.baseUrl}api/maintenance/import-legacy`, {});
            await dialog.alert(result.message || "Importazione completata.", "OK");
        } catch (err) {
            await dialog.error(err);
        }
    });

    document.querySelector('[data-action="import-refuelling"]')?.addEventListener("click", async () => {
        if (!(await dialog.confirm("Importare punti di rifornimento e rifornimenti dalle tabelle legacy refuelling_station e refuelling?", "Conferma"))) {
            return;
        }
        try {
            const result = await FetchHelper.post(`${window.FB.baseUrl}api/refuelling/import-legacy`, {});
            await dialog.alert(result.message || "Importazione completata.", "OK");
        } catch (err) {
            await dialog.error(err);
        }
    });
}

function initPasswordForm() {
    const form = document.getElementById("own-password-form");
    if (!form) return;

    form.addEventListener("submit", async (ev) => {
        ev.preventDefault();
        const data = Object.fromEntries(new FormData(form).entries());
        const msg = document.getElementById("password-message");
        msg.style.display = "none";
        msg.className = "fb-alert";

        try {
            await FetchHelper.post(`${window.FB.baseUrl}api/settings/change-password`, data);
            msg.textContent = "Password aggiornata con successo.";
            msg.classList.add("fb-alert-success");
            msg.style.display = "block";
            form.reset();
        } catch (err) {
            msg.textContent = err.message;
            msg.classList.add("fb-alert-error");
            msg.style.display = "block";
        }
    });
}

function initToastPrefsForm() {
    const form = document.getElementById("toast-prefs-form");
    if (!form) return;

    form.addEventListener("submit", async (ev) => {
        ev.preventDefault();
        const body = Object.fromEntries(new FormData(form).entries());
        try {
            await FetchHelper.post(`${window.FB.baseUrl}api/settings/toast-prefs`, body);
            // applica subito le nuove preferenze senza ricaricare
            window.FB.toast = {
                position: body.position,
                duration: Number(body.duration) * 1000,
                banner: body.style === "banner",
            };
            toast.defaults.position = window.FB.toast.position;
            toast.defaults.duration = window.FB.toast.duration;
            toast.defaults.banner = window.FB.toast.banner;
            toast.showToastSuccess("Preferenze toast salvate.");
        } catch (err) {
            await dialog.error(err);
        }
    });

    form.querySelector('[data-action="preview-toast"]')?.addEventListener("click", () => {
        toast.showToast({
            content: "Anteprima notifica toast",
            type: "notice",
            position: form.elements["position"].value,
            duration: Number(form.elements["duration"].value) * 1000,
            banner: form.elements["style"].value === "banner",
        });
    });
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", () => {
        waitForBootstrapTable();
        initTabs();
        initNewUserButton();
        initNewRoleButton();
        initNewDocTypeButton();
        initUserActionDelegation();
        initImportButtons();
        initPasswordForm();
        initToastPrefsForm();
        PasswordToggle.init();
        loadImports();
    });
} else {
    waitForBootstrapTable();
    initTabs();
    initNewUserButton();
    initNewRoleButton();
    initNewDocTypeButton();
    initUserActionDelegation();
    initImportButtons();
    initPasswordForm();
    initToastPrefsForm();
    PasswordToggle.init();
    loadImports();
}
