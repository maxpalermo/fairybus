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
                if (tab.dataset.tab === "backup") {
                    initBackupTable();
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

function initConfigForm() {
    const form = document.getElementById("hourly-cost-form");
    if (!form) return;

    form.addEventListener("submit", async (ev) => {
        ev.preventDefault();
        const body = Object.fromEntries(new FormData(form).entries());
        try {
            await FetchHelper.post(`${window.FB.baseUrl}api/settings/config`, body);
            toast.showToastSuccess("Costo orario salvato.");
        } catch (err) {
            await dialog.error(err);
        }
    });
}

function initAlertThresholdsForm() {
    const form = document.getElementById("alert-thresholds-form");
    if (!form) return;

    form.addEventListener("submit", async (ev) => {
        ev.preventDefault();
        const body = Object.fromEntries(new FormData(form).entries());
        try {
            await FetchHelper.post(`${window.FB.baseUrl}api/settings/config`, body);
            toast.showToastSuccess("Soglie avvisi salvate.");
        } catch (err) {
            await dialog.error(err);
        }
    });
}

function initVatRateForm() {
    const form = document.getElementById("vat-rate-form");
    if (!form) return;

    form.addEventListener("submit", async (ev) => {
        ev.preventDefault();
        const body = Object.fromEntries(new FormData(form).entries());
        try {
            await FetchHelper.post(`${window.FB.baseUrl}api/settings/config`, body);
            toast.showToastSuccess("Aliquota IVA salvata.");
        } catch (err) {
            await dialog.error(err);
        }
    });
}

/* ---------- Backup database ---------- */

const BACKUP_ICONS = {
    restore: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>',
    download: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>',
    trash: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>',
};

function fmtBackupSize(bytes) {
    const b = Number(bytes || 0);
    if (b >= 1048576) return (b / 1048576).toLocaleString("it-IT", { maximumFractionDigits: 2 }) + "\u00A0MB";
    if (b >= 1024) return (b / 1024).toLocaleString("it-IT", { maximumFractionDigits: 1 }) + "\u00A0KB";
    return b + "\u00A0B";
}

function formatBackupActions() {
    return `
        <div class="fb-btn-group">
            <button type="button" class="fb-btn-icon fb-btn-icon-warning backup-restore" title="Ripristina il database da questo backup">${BACKUP_ICONS.restore}</button>
            <button type="button" class="fb-btn-icon fb-btn-icon-info backup-download" title="Scarica l'archivio">${BACKUP_ICONS.download}</button>
            <button type="button" class="fb-btn-icon fb-btn-icon-danger backup-delete" title="Elimina il backup">${BACKUP_ICONS.trash}</button>
        </div>`;
}

function initBackupTable(attempt = 0) {
    if (!window.$ || !window.$.fn.bootstrapTable) {
        if (attempt < 50) setTimeout(() => initBackupTable(attempt + 1), 100);
        return false;
    }
    const $table = window.$("#backups-table");
    if (!$table.length) return false;

    if (!$table.data("bootstrap.table")) {
        window.backupActionsEvents = {
            "click .backup-restore": async (e, value, row) => {
                const ok = await dialog.confirm(`Ripristinare il backup <strong>${escapeHtml(row.name)}</strong> del ${escapeHtml(row.created_at)}?<br>` + "<strong>Il database corrente verrà completamente sovrascritto.</strong> L'operazione non è annullabile.", "Ripristina database");
                if (!ok) return;
                await runRestore(row);
            },
            "click .backup-download": (e, value, row) => {
                const a = document.createElement("a");
                a.href = `${window.FB.baseUrl}api/settings/backups/${encodeURIComponent(row.name)}/download`;
                a.download = row.name;
                document.body.appendChild(a);
                a.click();
                a.remove();
            },
            "click .backup-delete": async (e, value, row) => {
                const ok = await dialog.confirm(`Eliminare definitivamente il backup <strong>${escapeHtml(row.name)}</strong>?`, "Elimina backup");
                if (!ok) return;
                try {
                    await FetchHelper.post(`${window.FB.baseUrl}api/settings/backups/${encodeURIComponent(row.name)}/delete`, {});
                    toast.showToastSuccess("Backup eliminato.");
                    refreshBackups();
                } catch (err) {
                    await dialog.error(err);
                }
            },
        };

        $table.bootstrapTable({
            data: [],
            sidePagination: "client",
            pagination: true,
            search: false,
            sortable: true,
            locale: "it-IT",
            columns: [
                { field: "name", title: "File", sortable: true, formatter: (v) => `<code>${escapeHtml(v)}</code>` },
                { field: "created_at", title: "Data", sortable: true, sorter: (a, b, rowA, rowB) => (rowA.mtime || 0) - (rowB.mtime || 0) },
                { field: "size", title: "Dimensione", sortable: true, align: "right", formatter: fmtBackupSize },
                { field: "actions", title: "Azioni", formatter: formatBackupActions, events: window.backupActionsEvents },
            ],
        });
    }

    refreshBackups();
    return true;
}

/**
 * Ripristino a blocchi con dialog di avanzamento: il server esegue
 * CHUNK statement per richiesta e risponde con total/executed/done.
 * ESC disabilitato mentre il ripristino è in corso.
 */
async function runRestore(row) {
    const d = document.createElement("dialog");
    d.className = "fb-dialog";
    d.innerHTML = `
        <div class="fb-dialog-header"><h3 class="fb-dialog-title">Ripristino database</h3></div>
        <div class="fb-dialog-body">
            <p class="fb-muted" style="margin-top:0">Backup: <code>${escapeHtml(row.name)}</code></p>
            <div class="fb-clean-progress"><div class="fb-clean-progress-bar" data-field="bar"></div></div>
            <div data-field="status">Ripristino in corso…</div>
            <div class="fb-form-actions" data-field="foot" hidden>
                <button type="button" class="fb-btn fb-btn-primary" data-action="reload">Ricarica pagina</button>
                <button type="button" class="fb-btn fb-btn-secondary" data-action="close">Chiudi</button>
            </div>
        </div>`;
    document.body.appendChild(d);
    const blockCancel = (ev) => ev.preventDefault();
    d.addEventListener("cancel", blockCancel);
    d.showModal();

    const bar = d.querySelector('[data-field="bar"]');
    const status = d.querySelector('[data-field="status"]');
    const foot = d.querySelector('[data-field="foot"]');
    d.querySelector('[data-action="close"]').addEventListener("click", () => d.close());
    d.querySelector('[data-action="reload"]').addEventListener("click", () => location.reload());
    d.addEventListener("close", () => d.remove(), { once: true });

    const CHUNK = 200;
    let offset = 0;
    let total = 0;
    try {
        for (;;) {
            const res = await FetchHelper.post(`${window.FB.baseUrl}api/settings/backups/${encodeURIComponent(row.name)}/restore`, { offset, limit: CHUNK });
            total = Number(res.total || 0);
            offset += Number(res.executed || 0);
            bar.style.width = total > 0 ? `${Math.round((offset / total) * 100)}%` : "100%";
            status.textContent = `Ripristino in corso… ${offset}/${total} statement eseguiti`;
            if (res.done || offset >= total) {
                break;
            }
        }
        status.innerHTML = `<strong>Ripristino completato.</strong> ${total} statement eseguiti. Ricarica la pagina per vedere i dati aggiornati.`;
        bar.style.width = "100%";
    } catch (err) {
        bar.style.background = "#dc2626";
        status.innerHTML = `<strong>Errore</strong> dopo ${offset}${total ? "/" + total : ""} statement: ${escapeHtml(err?.message || String(err))}<br><span class="fb-muted">Il database potrebbe essere parzialmente ripristinato.</span>`;
    }
    d.removeEventListener("cancel", blockCancel);
    foot.hidden = false;
}

async function refreshBackups() {
    const $table = window.$("#backups-table");
    try {
        const res = await FetchHelper.get(`${window.FB.baseUrl}api/settings/backups`);
        if ($table.length && $table.data("bootstrap.table")) {
            $table.bootstrapTable("load", res.rows || []);
        }
        renderDemoBackup(res.demo, !!res.demo_mode);
    } catch (err) {
        await dialog.error(err);
    }
}

// Sezione "Backup demo": archivio fisso, solo ripristino (niente download/delete).
// In modalita' demo (demo_mode) toolbar e storico vengono nascosti: resta
// visibile solo questa sezione.
function renderDemoBackup(demo, demoMode) {
    document.querySelectorAll("[data-backup-ui]").forEach((el) => {
        el.hidden = !!demoMode;
    });
    const notice = document.querySelector('[data-field="demo-notice"]');
    if (notice) {
        notice.hidden = !demoMode;
    }
    const box = document.querySelector('[data-field="demo-backup"]');
    const empty = document.querySelector('[data-field="demo-empty"]');
    if (!box || !empty) {
        return;
    }
    box.hidden = !demo;
    empty.hidden = !!demo;
    if (demo) {
        const meta = box.querySelector('[data-field="demo-meta"]');
        if (meta) {
            meta.textContent = `· ${demo.created_at} · ${fmtBackupSize(demo.size)}`;
        }
    }
}

/**
 * Pulizia tabelle non usate: elenca le tabelle non fb_* (escluse
 * migrations), chiede conferma e le elimina una alla volta con
 * dialog di avanzamento. La whitelist è ricalcolata lato server.
 */
async function cleanUnusedTables() {
    let tables = [];
    try {
        const res = await FetchHelper.get(`${window.FB.baseUrl}api/settings/backups/unused-tables`);
        tables = res.tables || [];
    } catch (err) {
        await dialog.error(err);
        return;
    }
    if (tables.length === 0) {
        toast.showToastSuccess("Nessuna tabella da eliminare: il database è pulito.");
        return;
    }
    const preview = tables.slice(0, 15).map(escapeHtml).join(", ");
    const extra = tables.length > 15 ? ` …e altre ${tables.length - 15}` : "";
    const ok = await dialog.confirm(
        `Verranno eliminate definitivamente <strong>${tables.length}</strong> tabelle:<br><span class="fb-muted">${preview}${extra}</span><br><br><strong>L'operazione non è annullabile.</strong>`,
        "Pulisci tabelle non usate"
    );
    if (!ok) {
        return;
    }

    const d = document.createElement("dialog");
    d.className = "fb-dialog";
    d.innerHTML = `
        <div class="fb-dialog-header"><h3 class="fb-dialog-title">Pulizia tabelle non usate</h3></div>
        <div class="fb-dialog-body">
            <div class="fb-clean-progress"><div class="fb-clean-progress-bar" data-field="bar"></div></div>
            <div data-field="status">Inizio…</div>
            <div class="fb-form-actions" data-field="foot" hidden>
                <button type="button" class="fb-btn fb-btn-primary" data-action="close">Chiudi</button>
            </div>
        </div>`;
    document.body.appendChild(d);
    d.showModal();

    const bar = d.querySelector('[data-field="bar"]');
    const status = d.querySelector('[data-field="status"]');
    const foot = d.querySelector('[data-field="foot"]');
    d.querySelector('[data-action="close"]').addEventListener("click", () => d.close());
    d.addEventListener("close", () => d.remove(), { once: true });

    let done = 0;
    const errors = [];
    for (const name of tables) {
        status.textContent = `Eliminazione ${name}… (${done + 1}/${tables.length})`;
        try {
            await FetchHelper.post(`${window.FB.baseUrl}api/settings/backups/drop-table`, { table: name });
        } catch (err) {
            errors.push(`${name}: ${err?.message || err}`);
        }
        done++;
        bar.style.width = `${Math.round((done / tables.length) * 100)}%`;
    }
    status.innerHTML = errors.length
        ? `Completato con errori: eliminate ${done - errors.length}/${tables.length} tabelle.<br><span class="fb-muted">${errors.map(escapeHtml).join("<br>")}</span>`
        : `Fatto: eliminate tutte le ${done} tabelle non usate.`;
    foot.hidden = false;
}

function initBackups() {
    const cleanBtn = document.querySelector('[data-action="clean-tables"]');
    if (cleanBtn && cleanBtn.dataset.bound !== "1") {
        cleanBtn.dataset.bound = "1";
        cleanBtn.addEventListener("click", () => {
            cleanUnusedTables().catch(async (err) => dialog.error(err));
        });
    }

    const demoBtn = document.querySelector('[data-action="demo-restore"]');
    if (demoBtn && demoBtn.dataset.bound !== "1") {
        demoBtn.dataset.bound = "1";
        demoBtn.addEventListener("click", async () => {
            const ok = await dialog.confirm("Ripristinare lo stato iniziale della demo?<br><strong>Il database corrente verrà completamente sovrascritto con i dati dimostrativi.</strong> L'operazione non è annullabile.", "Ripristina demo");
            if (!ok) return;
            await runRestore({ name: "fb-demo.zip" });
        });
    }

    const btn = document.querySelector('[data-action="backup-create"]');
    if (btn && btn.dataset.bound !== "1") {
        btn.dataset.bound = "1";

        btn.addEventListener("click", async () => {
            btn.disabled = true;
            const label = btn.innerHTML;
            btn.innerHTML = "Backup in corso…";
            try {
                const res = await FetchHelper.post(`${window.FB.baseUrl}api/settings/backups/create`, {});
                toast.showToastSuccess(`Backup creato: ${res.name}`);
                if (initBackupTable()) {
                    refreshBackups();
                }
            } catch (err) {
                await dialog.error(err);
            } finally {
                btn.disabled = false;
                btn.innerHTML = label;
            }
        });
    }

    const uploadBtns = document.querySelectorAll('[data-action="backup-upload"]');
    const fileInput = document.getElementById("backup-upload-file");
    if (uploadBtns.length && fileInput && fileInput.dataset.bound !== "1") {
        fileInput.dataset.bound = "1";

        uploadBtns.forEach((uploadBtn) => uploadBtn.addEventListener("click", () => fileInput.click()));
        fileInput.addEventListener("change", async () => {
            const file = fileInput.files && fileInput.files[0];
            fileInput.value = "";
            if (!file) return;

            const formData = new FormData();
            formData.append("backup", file);
            if (window.FB && window.FB.csrf) {
                formData.append(window.FB.csrf.name, window.FB.csrf.value);
            }

            uploadBtns.forEach((b) => (b.disabled = true));
            try {
                const res = await FetchHelper.postMultipart(`${window.FB.baseUrl}api/settings/backups/upload`, formData);
                toast.showToastSuccess(`Backup caricato: ${res.name}`);
                if (initBackupTable()) {
                    refreshBackups();
                }
            } catch (err) {
                await dialog.error(err);
            } finally {
                uploadBtns.forEach((b) => (b.disabled = false));
            }
        });
    }
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
        initPasswordForm();
        initToastPrefsForm();
        initConfigForm();
        initVatRateForm();
        initAlertThresholdsForm();
        initBackups();
        PasswordToggle.init();
    });
} else {
    waitForBootstrapTable();
    initTabs();
    initNewUserButton();
    initNewRoleButton();
    initNewDocTypeButton();
    initUserActionDelegation();
    initPasswordForm();
    initToastPrefsForm();
    initConfigForm();
    initVatRateForm();
    initAlertThresholdsForm();
    initBackups();
    PasswordToggle.init();
}
