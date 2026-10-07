"use strict";

window.addEventListener('DOMContentLoaded', async () => {
  try { await loadAdminMessages(); await connect(true); }
  catch (error) {
    document.getElementById('sessionLoading').hidden = true;
    document.getElementById('loginScreen').hidden = false;
    document.getElementById('loginStatus').textContent = document.documentElement.lang === "en"
      ? `Could not load translations: ${error.message}`
      : `翻訳データを読み込めませんでした: ${error.message}`;
  }
});

const apiRoot = "../api";
const fieldTypes = ["text", "textarea", "number", "date", "select", "checkbox", "url", "wikimedia"];
const optionFieldTypes = new Set(["select", "checkbox"]);
const state = {
  projects: [], currentApp: new URLSearchParams(location.search).get("app") || "",
  view: new URLSearchParams(location.search).get("view") || "projects",
  sessionUser: null,
  schema: { fields: {} }, rows: [], columnsTable: null, activityTable: null, nextColumnKey: 1, nextRowKey: 1,
  newActivityRows: new Set(), deletedActivityRows: new Set(), dirtyActivityFields: new Map(),
  schemaDirty: false, pendingCsv: null, users: [], userPage: 1, userPagination: null, currentUser: null, passwordUser: null
};
const ids = [
  "sessionLoading", "loginScreen", "loginForm", "loginStatus", "userid", "password", "connectButton", "adminHeader", "adminMain", "currentAdmin", "logoutButton", "mobileViewSelect",
  "status", "statusModal", "projectsGrid", "newProjectButton", "trashStatus", "trashProjectsBody",
  "columnsBackButton", "columnsProjectKey", "projectName", "projectFrontendUrl", "projectFrontendPublic", "columnsTable", "addColumnButton", "saveColumnsButton",
  "activitiesBackButton", "activitiesFrontendLink", "appSelect", "search", "addButton", "discardButton", "saveAllButton", "dirtyCount",
  "jsonExport", "csvExport", "importFile", "activityTable", "projectDialog", "projectForm",
  "newProjectName", "newAppKey", "newProjectFrontendUrl", "newProjectFrontendPublic", "csvDialog", "csvForm", "csvSummary", "csvMissing", "addMissingLabel", "addMissingColumns",
  "newUserButton", "usersSearch", "userStatusFilter", "userVerifiedFilter", "userRoleFilter", "userProjectFilter", "usersLoadButton",
  "usersBody", "usersPrevButton", "usersNextButton", "usersPageInfo", "newUserDialog", "newUserForm", "newUserId", "newUserEmail",
  "newUserModeHint", "newUserPasswordFields", "newUserPassword", "newUserPasswordConfirmation", "newUserSubmitButton", "newUserRole", "newUserProjects",
  "userDialog", "userForm", "userDialogTitle", "userMetadata", "editUserStatus", "editUserRole",
  "editUserProjects", "userAddEmailSection", "addUserEmail", "addUserEmailButton", "userEmailStatus", "userMailActions", "userMailAddress", "userMailDescription", "resendVerificationButton",
  "passwordResetDialog", "passwordResetTitle", "passwordResetEmailSection", "passwordResetEmailAddress", "sendPasswordResetButton", "editUserPassword", "editUserPasswordConfirmation", "setUserPasswordButton", "userPasswordStatus"
];
const els = Object.fromEntries(ids.map(id => [id, document.getElementById(id)]));

let statusReturnDialog = null;

function isProgressMessage(message) { return message.endsWith("…") || message.endsWith("..."); }

function setStatus(message, error = false, showModal = true) {
  els.status.textContent = message;
  els.status.classList.toggle("text-danger", error);
  els.status.classList.toggle("text-success", !error && !isProgressMessage(message));
  if (!showModal || !message || isProgressMessage(message)) return;
  document.getElementById("statusModalTitle").textContent = error ? t("message.ec6399d678a4") : t("message.28eeac5d2b7c");
  if (els.statusModal.open) return;
  const openDialog = document.querySelector("dialog[open]");
  if (openDialog) {
    statusReturnDialog = openDialog;
    openDialog.close();
  }
  els.statusModal.showModal();
}

function setLoginStatus(message, error = false) {
  els.loginStatus.textContent = message;
  els.loginStatus.classList.toggle("error", error);
  els.loginStatus.classList.toggle("success", !error && !isProgressMessage(message));
}

function showAdminConsole() {
  els.loginScreen.hidden = true;
  els.adminHeader.hidden = false;
  els.adminMain.hidden = false;
  els.currentAdmin.textContent = `${state.sessionUser?.userid || els.userid.value} (${state.sessionUser?.role || "user"})`;
}

async function logout() {
  if (!confirmDiscard()) return;
  try { await request('console-session.php', { method: 'DELETE' }); }
  catch (error) { setStatus(`${t("message.7a3196c1a7d1")}${error.message}`, true); return; }
  state.columnsTable?.destroy(); state.columnsTable = null;
  state.activityTable?.destroy(); state.activityTable = null;
  state.projects = []; state.rows = []; state.users = []; state.currentUser = null; state.passwordUser = null; state.sessionUser = null;
  state.schema = { fields: {} }; state.schemaDirty = false;
  state.newActivityRows.clear(); state.deletedActivityRows.clear(); state.dirtyActivityFields.clear();
  document.querySelectorAll("dialog[open]").forEach(dialog => dialog.close());
  document.body.classList.remove("activity-spreadsheet-view");
  els.adminHeader.hidden = true; els.adminMain.hidden = true; els.loginScreen.hidden = false;
  statusReturnDialog = null;
  if (els.statusModal.open) els.statusModal.close();
  els.currentAdmin.textContent = ""; els.password.value = ""; els.status.textContent = "";
  document.querySelectorAll('[data-view="activities"], [data-view="users"], [data-view="trash"]').forEach(button => { button.disabled = true; });
  els.mobileViewSelect.value = "projects";
  els.mobileViewSelect.querySelector('[value="activities"]').disabled = true;
  els.mobileViewSelect.querySelector('[value="users"]').disabled = true;
  els.mobileViewSelect.querySelector('[value="trash"]').disabled = true;
  els.newProjectButton.disabled = true; els.newUserButton.disabled = true;
  setLoginStatus(t("message.ac454ea66043"));
  (els.userid.value ? els.password : els.userid).focus();
}

function credentials() {
  return `Basic ${btoa(unescape(encodeURIComponent(`${els.userid.value}:${els.password.value}`)))}`;
}

function formatApiError(payload, status) {
  const codeMessages = {
    authentication_required: t("message.1353b49714de"),
    admin_required: t("message.be2752e93d9d"),
    project_access_denied: t("message.ac32ba597a9b"),
    project_not_found: t("message.cd1fcbd4b624"),
    app_not_found: t("message.cd1fcbd4b624"),
    activity_not_found: t("message.7f61e849f97e"),
    project_already_exists: t("message.38c9fa440047"),
    activity_already_exists: t("message.e384296911a8"),
    identity_already_registered: t("message.e27adb1122a5"),
    user_not_found: t("message.41cafa64bec1"),
    last_active_admin: t("message.e089b19d2feb"),
    rate_limit_exceeded: t("message.01b95783a916"),
    payload_too_large: t("message.f9adeb9e3b6d"),
    invalid_json: t("message.7b10c7c4bbde"),
    invalid_response: t("message.1794b352a30c"),
    server_error: t("message.d8f79fb75360")
  };
  if (payload?.code === "validation_failed" && payload.errors && typeof payload.errors === "object") {
    const validationMessages = {
      "Option values must be unique.": t("message.5953fca030e4"),
      "Option value must be a scalar.": t("message.f13b5564a821"),
      "Option must be a scalar or value/label object.": t("message.90287decb311"),
      "Option value must be 1-255 characters.": t("message.5f20043f6071"),
      "Options must not be empty.": t("message.278c9017d958"),
      "Select fields require at least one option.": t("message.278c9017d958"),
      "Options must be an array.": t("message.90287decb311"),
      "Options are only supported for select and checkbox fields.": t("message.180b9757ee33"),
      "Unsupported field type.": t("message.0f40270382da"),
      "System columns cannot be added to schema fields.": t("message.ce483023ffc1"),
      "Field definition must be an object.": t("message.aeefaf9f242e"),
      "Label must not exceed 255 characters.": t("message.883e4236f746"),
      "Column width must be between 80 and 800 pixels.": t("message.5844da877b43"),
      "Maximum length must be between 1 and 1000000.": t("message.08841396c496"),
      "Project name is required.": t("message.2ac840f2a6c1"),
      "Project name cannot be empty.": t("message.2ac840f2a6c1"),
      "Project name must not exceed 255 characters.": t("message.4310225e8504"),
      "App key is required.": t("message.eecac6026514"),
      "App key must be 1-64 lowercase alphanumeric, underscore or hyphen characters.": t("message.f2878a66df8c"),
      "A public frontend requires a URL.": t("message.f6ca1a8f6cf6"),
      "Frontend URL must be a string.": t("message.29686cfd16d2"),
      "Frontend URL is too long or contains invalid characters.": t("message.572ca3c0c842"),
      "Frontend URL must be an absolute HTTP or HTTPS URL without credentials.": t("message.8725a61a7234"),
      "Public visibility must be true or false.": t("message.3cc316d802d4"),
      "Schema must be an object.": t("message.aeefaf9f242e"),
      "Schema must contain a \"fields\" object.": t("message.529c767875a9"),
      "Schema fields must be a JSON object keyed by field name.": t("message.aeefaf9f242e"),
      "Schema may contain at most 256 fields.": t("message.dadc424ea476")
    };
    const details = Object.entries(payload.errors).map(([field, message]) => {
      const [key, part, index] = field.split(".");
      const column = state.columnsTable?.getData()?.find(row => row.field === key);
      const label = column?.label || key;
      const location = part === "options"
        ? t("errors.option_location", { label, row: /^\d+$/.test(index || "") ? Number(index) + 1 : "" })
        : t("errors.field_location", { label });
      return `${location}: ${validationMessages[message] || t("message.2a600dc02f7c")}`;
    });
    return `${t("message.25cc48d137de")}${details.join("\n")}`;
  }
  const message = codeMessages[payload?.code] || t("errors.http", { status });
  return payload?.request_id ? `${message}${t("message.97270c4912e2")}${payload.request_id}` : message;
}

async function request(path, options = {}) {
  const headers = { Accept: "application/json", ...(options.headers || {}) };
  if (options.body) headers["Content-Type"] = "application/json";
  headers['X-Console-Session'] = '1';
  if (options.basic) headers.Authorization = credentials();
  const response = await fetch(`${apiRoot}/${path}`, { ...options, headers });
  const payload = await response.json().catch(() => ({ code: "invalid_response" }));
  if (!response.ok) {
    const error = new Error(formatApiError(payload, response.status));
    error.code = payload.code;
    throw error;
  }
  return payload;
}

function currentProject() {
  return state.projects.find(project => project.app_key === state.currentApp) || null;
}

function isAdmin() { return state.sessionUser?.role === "admin"; }
function canManageProject(project = currentProject()) { return isAdmin() || project?.access_role === "project_admin"; }
function canDeleteProject(project) {
  return isAdmin() || (project?.access_role === "project_admin" && project.created_by_user_id != null
    && Number(project.created_by_user_id) === Number(state.sessionUser?.id));
}

function canWriteActivities(project = currentProject()) {
  return isAdmin() || ["editor", "project_admin"].includes(project?.access_role);
}

function configureConsoleAccess() {
  const admin = isAdmin();
  const usersButton = document.querySelector('[data-view="users"]');
  usersButton.hidden = !admin; usersButton.disabled = !admin;
  const trashButton = document.querySelector('[data-view="trash"]');
  trashButton.hidden = !admin; trashButton.disabled = !admin;
  const usersOption = els.mobileViewSelect.querySelector('[value="users"]');
  usersOption.hidden = !admin; usersOption.disabled = !admin;
  const trashOption = els.mobileViewSelect.querySelector('[value="trash"]');
  trashOption.hidden = !admin; trashOption.disabled = !admin;
  const activitiesButton = document.querySelector('[data-view="activities"]');
  activitiesButton.hidden = false; activitiesButton.disabled = !currentProject();
  els.mobileViewSelect.querySelector('[value="activities"]').disabled = activitiesButton.disabled;
  els.newProjectButton.disabled = false; els.newUserButton.disabled = !admin;
  els.importFile.closest("label").hidden = !admin;
}

function hasActivityChanges() {
  return state.newActivityRows.size > 0 || state.deletedActivityRows.size > 0 || state.dirtyActivityFields.size > 0;
}

function hasUnsavedChanges() {
  return state.schemaDirty || hasActivityChanges();
}

function confirmDiscard() {
  return !hasUnsavedChanges() || confirm(t("message.868d5437b3ae"));
}

function updateUrl() {
  const url = new URL(location.href);
  url.searchParams.set("view", state.view);
  if (state.currentApp) url.searchParams.set("app", state.currentApp);
  else url.searchParams.delete("app");
  history.replaceState(null, "", url);
}

function showView(view, force = false) {
  if (!force && !confirmDiscard()) return;
  if (["users", "trash"].includes(view) && !isAdmin()) view = "projects";
  if (view === "columns" && !canManageProject()) view = "projects";
  if (view === "activities" && !currentProject()) view = "projects";
  if (!["projects", "columns", "activities", "users", "trash"].includes(view)) view = "projects";
  state.view = view;
  document.body.classList.toggle("activity-spreadsheet-view", view === "activities");
  document.querySelectorAll(".view").forEach(section => { section.hidden = section.id !== `${view}View`; });
  document.querySelectorAll("[data-view]").forEach(button => button.classList.toggle("active", button.dataset.view === view));
  els.mobileViewSelect.value = ["activities", "users", "trash"].includes(view) ? view : "projects";
  updateUrl();
  if (view === "columns") openColumns();
  if (view === "activities") loadActivities();
  if (view === "users") loadUsers();
  if (view === "trash") loadDeletedProjects();
}

async function connect(restore = false) {
  if (!restore && (!els.userid.value || !els.password.value)) {
    setLoginStatus(t("message.e09b9a5730bc"), true);
    return;
  }
  els.connectButton.disabled = true;
  setLoginStatus(t("message.8f3f2866f749"));
  try {
    const session = await request("console-session.php", restore ? {} : { method: 'POST', basic: true });
    els.password.value = '';
    state.sessionUser = session.user; state.projects = session.projects;
    if (!currentProject()) state.currentApp = state.projects[0]?.app_key || "";
    configureConsoleAccess();
    renderProjects();
    populateProjectSelect();
    showAdminConsole();
    setStatus(`${state.projects.length}${t("message.88c406f67b0f")}`, false, false);
    showView(state.view, true);
  } catch (error) {
    const message = restore
      ? t("message.e09b9a5730bc")
      : error.code === 'authentication_required'
        ? t("message.5a5920b3fe2f")
        : `${t("message.4c1ac64e5908")}${error.message}`;
    setLoginStatus(message, !restore);
    if (restore) {
      els.loginScreen.hidden = false;
      els.userid.focus();
    } else {
      els.password.select();
    }
  } finally {
    if (restore) els.sessionLoading.hidden = true;
    els.connectButton.disabled = false;
  }
}

function safeFrontendUrl(value) {
  if (!value) return null;
  try { const url = new URL(value); return ["http:", "https:"].includes(url.protocol) && !url.username && !url.password ? url.href : null; }
  catch { return null; }
}

function setFrontendLink(link, value) {
  const url = safeFrontendUrl(value);
  link.hidden = !url;
  if (url) link.href = url; else link.removeAttribute("href");
}

function renderProjects() {
  if (!state.projects.length) {
    els.projectsGrid.className = "projects-grid empty-state";
    els.projectsGrid.textContent = t("message.458ce45954f7");
    return;
  }
  els.projectsGrid.className = "projects-grid";
  els.projectsGrid.replaceChildren(...state.projects.map(project => {
    const card = document.createElement("article");
    card.className = "project-card card shadow-sm p-3 d-flex flex-column justify-content-between gap-3";
    const header = document.createElement("div");
    header.className = "project-card-header";
    const title = document.createElement("h3"); title.textContent = project.project_name;
    const key = document.createElement("span"); key.className = "key"; key.textContent = project.app_key;
    header.append(title, key);
    if (project.id === null) {
      const meta = document.createElement("p"); meta.className = "project-card-meta";
      meta.textContent = t("projects.configuration_source");
      header.append(meta);
    }
    const frontendUrl = safeFrontendUrl(project.frontend_url);
    if (frontendUrl) {
      const link = document.createElement("a"); link.href = frontendUrl; link.target = "_blank"; link.rel = "noopener noreferrer";
      link.className = "project-frontend-link"; link.setAttribute("aria-label", `${project.project_name}${t("message.5ca4c0cd8aee")}`); link.title = t("message.872c0c3f355f");
      link.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18"/></svg>';
      header.append(link);
    }
    const actions = document.createElement("div"); actions.className = "project-card-actions";
    actions.append(actionButton(t("projects.activities_with_count", { count: Number(project.activity_count ?? 0) }), () => selectProject(project.app_key, "activities"), "primary"));
    if (canManageProject(project)) actions.append(actionButton(t("message.c6339337ca8d"), () => selectProject(project.app_key, "columns")));
    if (canDeleteProject(project)) actions.append(actionButton(t("message.5cbdf1fd7082"), () => deleteProject(project), "danger"));
    card.append(header, actions);
    return card;
  }));
}

function actionButton(label, handler, className = "secondary") {
  const button = document.createElement("button");
  button.type = "button"; button.textContent = label; button.className = `btn btn-sm ${className === "primary" ? "btn-success" : className === "danger" ? "btn-outline-danger" : "btn-outline-secondary"}`; button.addEventListener("click", handler);
  return button;
}

function selectProject(appKey, view) {
  if (!confirmDiscard()) return;
  state.currentApp = appKey;
  state.schemaDirty = false; state.rows = [];
  populateProjectSelect(); configureConsoleAccess();
  showView(view, true);
}

async function deleteProject(project) {
  if (!confirm(`${project.project_name} (${project.app_key}${t("message.c8bb899ae504")}`)) return;
  try {
    await request(`projects.php?app=${encodeURIComponent(project.app_key)}`, { method: "DELETE" });
    state.projects = state.projects.filter(item => item.app_key !== project.app_key);
    if (state.currentApp === project.app_key) state.currentApp = state.projects[0]?.app_key || "";
    renderProjects(); populateProjectSelect(); updateUrl();
    setStatus(`${project.project_name}${t("message.bed71fc59d9a")}`);
  } catch (error) { setStatus(`${t("message.7677abdf5309")}${error.message}`, true); }
}

async function loadDeletedProjects() {
  if (!isAdmin()) return;
  els.trashStatus.textContent = t("message.d1c13ac5cca4");
  try {
    const projects = await request("project-trash-data.php");
    els.trashProjectsBody.replaceChildren(...projects.map(project => {
      const row = document.createElement("tr");
      for (const [label, value] of [[t("message.598ab78312d8"), project.project_name], ["app_key", project.app_key], [t("message.9979f7e68dd7"), project.deleted_at], [t("message.d54eb82b87f9"), project.activity_count], [t("message.6aebe956b622"), project.assignment_count]]) {
        const cell = document.createElement("td"); cell.dataset.label = label; cell.textContent = value ?? ""; row.append(cell);
      }
      const actions = document.createElement("td"); actions.className = "trash-actions"; actions.dataset.label = t("message.f3ea6d345e2a");
      const restore = document.createElement("button"); restore.type = "button"; restore.className = "btn btn-outline-secondary trash-action-button";
      restore.setAttribute("aria-label", `${project.project_name}${t("message.031021fa2faa")}`); restore.title = t("message.442ee7eb767e");
      restore.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/><path d="M12 7v5l3 2"/></svg>';
      restore.addEventListener("click", () => actOnDeletedProject(project, "restore"));
      const purge = document.createElement("button"); purge.type = "button"; purge.className = "btn btn-outline-danger trash-action-button";
      purge.setAttribute("aria-label", `${project.project_name}${t("message.b38ad644a8fa")}`); purge.title = t("message.022f49f83427");
      purge.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16"/><path d="M10 4h4"/><path d="M6 7l1 13h10l1-13"/><path d="M10 11v6M14 11v6"/></svg>';
      purge.addEventListener("click", () => actOnDeletedProject(project, "purge"));
      actions.append(restore, purge); row.append(actions); return row;
    }));
    els.trashStatus.textContent = `${projects.length}${t("message.c09e69758b62")}`;
  } catch (error) { els.trashStatus.textContent = `${t("message.39a1228bde4e")}${error.message}`; }
}

async function actOnDeletedProject(project, action) {
  if (action === "restore" && !confirm(t("trash.confirm_restore", { name: project.project_name, key: project.app_key }))) return;
  if (action === "purge") {
    const entered = prompt(t("trash.confirm_purge", { name: project.project_name, activities: project.activity_count, assignments: project.assignment_count, key: project.app_key }));
    if (entered !== project.app_key) return;
  }
  els.trashStatus.textContent = t("message.f11b0168dcd5");
  try {
    await request("project-trash-data.php", { method: "POST", body: JSON.stringify({ action, app_key: project.app_key, confirm_app_key: action === "purge" ? project.app_key : undefined }) });
    if (action === "restore") {
      state.projects = await request("projects.php");
      if (!currentProject()) state.currentApp = state.projects[0]?.app_key || "";
      renderProjects(); populateProjectSelect(); configureConsoleAccess();
    }
    await loadDeletedProjects();
    els.trashStatus.textContent = `${project.project_name}${t("message.eba95ba0c666")}${action === "restore" ? t("message.442ee7eb767e") : t("message.022f49f83427")}${t("message.00d9ca256f4a")}`;
  } catch (error) { els.trashStatus.textContent = `${t("message.19a0fb5a8447")}${error.message}`; }
}

function replaceProject(project) {
  const index = state.projects.findIndex(item => item.app_key === project.app_key);
  if (index >= 0) state.projects[index] = project; else state.projects.push(project);
  if (project.app_key === state.currentApp) state.schema = project.schema;
}

function populateProjectSelect() {
  els.appSelect.replaceChildren(...state.projects.map(project => new Option(
    `${project.project_name}${!isAdmin() && project.access_role ? ` (${project.access_role})` : ""}`,
    project.app_key
  )));
  els.appSelect.value = state.currentApp;
  const selectedFilter = els.userProjectFilter.value;
  els.userProjectFilter.replaceChildren(new Option(t("message.c15ccc4dc740"), ""), ...state.projects.filter(project => project.id !== null).map(project => new Option(project.project_name, project.id)));
  els.userProjectFilter.value = selectedFilter;
}

async function createProject() {
  els.newAppKey.value = els.newAppKey.value.trim().toLowerCase();
  if (!els.projectForm.reportValidity()) return;
  try {
    const project = await request("projects.php", { method: "POST", body: JSON.stringify({
      project_name: els.newProjectName.value, app_key: els.newAppKey.value,
      frontend_url: els.newProjectFrontendUrl.value, frontend_public: els.newProjectFrontendPublic.checked
    }) });
    replaceProject(project); state.currentApp = project.app_key;
    renderProjects(); populateProjectSelect(); configureConsoleAccess(); els.projectDialog.close(); els.projectForm.reset();
    setStatus(`${project.project_name}${t("message.d7239b2491cf")}`); showView("columns", true);
  } catch (error) { setStatus(`${t("message.16cf5004f021")}${error.message}`, true); }
}

function orderedFields(schema = state.schema) {
  return Object.entries(schema?.fields || {}).sort((a, b) => (a[1].order ?? 0) - (b[1].order ?? 0));
}

function openColumns() {
  const project = currentProject(); if (!project) return;
  state.schema = structuredClone(project.schema || { fields: {} }); state.schemaDirty = false;
  els.columnsProjectKey.textContent = project.app_key; els.projectName.value = project.project_name;
  els.projectFrontendUrl.value = project.frontend_url || ""; els.projectFrontendPublic.checked = Boolean(project.frontend_public);
  renderColumnsTable();
  updateSchemaDirty();
}

function columnDefinitionRow(field = "", definition = {}) {
  const type = definition.type || "text";
  return {
    cmmColumnKey: `column-${state.nextColumnKey++}`,
    field,
    label: definition.label || field,
    type,
    required: Boolean(definition.required),
    visible: definition.admin?.visible !== false,
    editable: definition.admin?.editable !== false,
    width: Number(definition.admin?.width) || 150,
    options: optionFieldTypes.has(type) ? optionsText(definition.options) : "",
    maxLength: definition.maxLength ?? null
  };
}

function renderColumnsTable() {
  if (typeof window.Tabulator !== "function") {
    throw new Error(t("message.69bf481d3ba3"));
  }
  const data = orderedFields().map(([field, definition]) => columnDefinitionRow(field, definition));
  state.columnsTable?.destroy();
  state.columnsTable = new window.Tabulator(els.columnsTable, {
    data,
    index: "cmmColumnKey",
    height: "100%",
    layout: "fitData",
    placeholder: t("message.5320d71b2d56"),
    validationMode: "highlight",
    movableRows: true,
    editTriggerEvent: "click",
    rowFormatter: updateColumnOptionsState,
    rowHeader: { rowHandle: true, formatter: "handle", headerSort: false, resizable: false, frozen: true, width: 42, minWidth: 42, hozAlign: "center" },
    columnDefaults: { headerSort: false, resizable: "header" },
    columns: [
      { field: "cmmColumnKey", visible: false, download: false },
      { title: "field", field: "field", editor: "input", width: 180, minWidth: 130,
        validator: (_cell, value) => printable(value).trim().length >= 1 && printable(value).trim().length <= 64 },
      { title: t("message.67c008e4fd30"), field: "label", editor: "input", width: 220, minWidth: 140,
        validator: (_cell, value) => printable(value).trim().length >= 1 && printable(value).trim().length <= 255 },
      { title: t("message.1496fa30a1d2"), field: "type", editor: "list", editorParams: { values: fieldTypes }, width: 130 },
      ...["required", "visible", "editable"].map((field, index) => ({
        title: [t("message.df7b17a1e3d8"), t("message.a46924362170"), t("message.d107b864c378")][index], field, editor: "tickCross", formatter: "tickCross",
        formatterParams: { allowEmpty: false }, hozAlign: "center", headerHozAlign: "center", width: 74
      })),
      { title: t("message.f677b2368d17"), field: "width", editor: "number", editorParams: { min: 80, max: 800 }, sorter: "number", width: 100,
        validator: (_cell, value) => Number.isInteger(Number(value)) && Number(value) >= 80 && Number(value) <= 800 },
      { title: t("message.230e04ab4e6c"), field: "options", editor: columnOptionsEditor,
        editable: cell => optionFieldTypes.has(printable(cell.getRow().getData().type)),
        formatter: "textarea", variableHeight: true, width: 300, minWidth: 200 },
      { title: t("message.f3ea6d345e2a"), headerSort: false, width: 86, minWidth: 86, formatter: () => {
        const button = document.createElement("button"); button.type = "button"; button.className = "btn btn-sm btn-outline-danger column-delete-button"; button.textContent = t("message.5cbdf1fd7082"); return button;
      }, cellClick: (_event, cell) => {
        if (!confirm(t("message.3bb9a0a9421e"))) return;
        cell.getRow().delete(); markSchemaDirty();
      } }
    ]
  });
  state.columnsTable.on("cellEdited", cell => {
    if (cell.getField() === "type") {
      const optionsCell = cell.getRow().getCell("options");
      if (!optionFieldTypes.has(printable(cell.getValue()))) optionsCell?.setValue("");
      updateColumnOptionsState(cell.getRow());
    }
    markSchemaDirty();
  });
  state.columnsTable.on("rowMoved", markSchemaDirty);
}

function optionsText(options) {
  return (options || []).map(option => typeof option === "object" ? `${option.value}|${option.label || option.value}` : String(option)).join("\n");
}

function updateColumnOptionsState(row) {
  const optionsCell = row.getCell("options");
  if (!optionsCell) return;
  optionsCell.getElement().classList.toggle("column-options-disabled", !optionFieldTypes.has(printable(row.getData().type)));
}

function columnOptionsEditor(cell, onRendered, success, cancel) {
  if (!optionFieldTypes.has(printable(cell.getRow().getData().type))) return false;
  const editor = document.createElement("textarea");
  editor.value = printable(cell.getValue());
  Object.assign(editor.style, { width: "100%", height: "100%", boxSizing: "border-box", padding: "4px", resize: "vertical" });
  const commit = () => success(editor.value);
  editor.addEventListener("blur", commit);
  editor.addEventListener("keydown", event => {
    if (event.key === "Escape") { event.preventDefault(); event.stopPropagation(); cancel(); }
    if (event.key === "Enter" && (event.ctrlKey || event.metaKey)) { event.preventDefault(); commit(); }
  });
  onRendered(() => { editor.focus(); editor.setSelectionRange(editor.value.length, editor.value.length); });
  return editor;
}

function markSchemaDirty() { state.schemaDirty = true; updateSchemaDirty(); }
function updateSchemaDirty() { els.saveColumnsButton.disabled = !state.schemaDirty; }

function collectSchema() {
  const fields = {}; const seen = new Set();
  const rows = state.columnsTable ? state.columnsTable.getRows("active").map(row => row.getData()) : [];
  rows.forEach((row, order) => {
    const field = printable(row.field).trim();
    if (!field || seen.has(field)) throw new Error(!field ? t("message.b105e2f606dc") : `field ${field}${t("message.70ef8b848b4d")}`);
    if (field.length > 64) throw new Error(`field ${field}${t("message.1856bcaa3cae")}`);
    seen.add(field);
    const type = printable(row.type);
    if (!fieldTypes.includes(type)) throw new Error(`field ${field}${t("message.8167fcdb1889")}`);
    const label = printable(row.label).trim();
    if (!label) throw new Error(`field ${field}${t("message.e6d71d2c58e6")}`);
    if (label.length > 255) throw new Error(`field ${field}${t("message.21ddbfadb9fe")}`);
    const width = Number(row.width);
    if (!Number.isInteger(width) || width < 80 || width > 800) throw new Error(`field ${field}${t("message.ddb13b8fa27e")}`);
    const definition = {
      label, type, required: Boolean(row.required), order,
      admin: { visible: Boolean(row.visible), editable: Boolean(row.editable), width }
    };
    if (row.maxLength !== null && row.maxLength !== undefined) definition.maxLength = row.maxLength;
    const options = printable(row.options).split(/\r?\n/).map(item => item.trim()).filter(Boolean).map(item => {
      const split = item.indexOf("|");
      return split < 0 ? item : { value: item.slice(0, split).trim(), label: item.slice(split + 1).trim() };
    });
    if ((type === "select" || type === "checkbox") && options.length) definition.options = options;
    fields[field] = definition;
  });
  return { fields };
}

async function saveColumns() {
  if (state.columnsTable && state.columnsTable.validate() !== true) {
    setStatus(t("message.84e9e565bf53"), true); return;
  }
  let schema;
  try { schema = collectSchema(); } catch (error) { setStatus(error.message, true); return; }
  try {
    const updated = await request(`projects.php?app=${encodeURIComponent(state.currentApp)}`, { method: "PUT", body: JSON.stringify({
      project_name: els.projectName.value,
      frontend_url: els.projectFrontendUrl.value, frontend_public: els.projectFrontendPublic.checked, schema
    }) });
    replaceProject(updated); state.schemaDirty = false; renderColumnsTable(); updateSchemaDirty(); renderProjects(); populateProjectSelect();
    setStatus(t("message.56c66905dc25"));
  } catch (error) { setStatus(`${t("message.cef70d9b3017")}${error.message}`, true); }
}

async function loadActivities(force = false) {
  if (!force && hasActivityChanges() && !confirmDiscard()) return;
  const project = currentProject();
  if (!project) {
    state.activityTable?.destroy(); state.activityTable = null; state.rows = [];
    state.newActivityRows.clear(); state.deletedActivityRows.clear(); state.dirtyActivityFields.clear();
    setFrontendLink(els.activitiesFrontendLink, null); els.appSelect.replaceChildren();
    els.jsonExport.removeAttribute("href"); els.csvExport.removeAttribute("href");
    updateDirtyControls(); setStatus(t("message.3728e237b3ef"), true);
    return;
  }
  state.schema = project.schema || { fields: {} }; els.search.value = "";
  setFrontendLink(els.activitiesFrontendLink, project.frontend_url); els.appSelect.value = project.app_key;
  setStatus(t("message.59d659673552"), false, false);
  try {
    const rows = await request(`activities.php?app=${encodeURIComponent(state.currentApp)}`);
    state.newActivityRows.clear(); state.deletedActivityRows.clear(); state.dirtyActivityFields.clear();
    state.rows = rows.map(row => ({ ...row, cmmRowKey: `saved-${row.id}` }));
    project.activity_count = rows.length;
    renderProjects();
    updateExportLinks(); renderActivityTable(); updateDirtyControls();
    setStatus(`${rows.length}${t("message.9b6015e28745")}${canWriteActivities(project) ? "" : t("message.998135a8a918")}`, false, false);
  } catch (error) { setStatus(`${t("message.39a1228bde4e")}${error.message}`, true); }
}

function activityColumns() {
  const system = [
    { field: "id", label: t("message.66ac32497808"), type: "text", admin: { width: 210 } },
    { field: "osmid", label: "OSM ID", type: "text", required: true, admin: { width: 150 } },
    { field: "latitude", label: t("message.aee1a47ed194"), type: "number", admin: { width: 140, editable: false } },
    { field: "longitude", label: t("message.6b136ced4af4"), type: "number", admin: { width: 140, editable: false } },
    { field: "form_key", label: "Form", type: "text", admin: { width: 110 } },
    { field: "created_at", label: t("message.25293dc60993"), type: "text", admin: { width: 160, editable: false } },
    { field: "updated_at", label: t("message.504d4c11e0f5"), type: "text", admin: { width: 160, editable: false } }
  ];
  const dynamic = orderedFields().filter(([, def]) => def.admin?.visible !== false).map(([field, def]) => ({ field, ...def }));
  return [...system, ...dynamic];
}

function renderActivityTable() {
  if (typeof window.Tabulator !== "function") {
    throw new Error(t("message.69bf481d3ba3"));
  }
  state.activityTable?.destroy();
  state.activityTable = new window.Tabulator(els.activityTable, {
    data: state.rows,
    index: "cmmRowKey",
    layout: "fitDataTable",
    placeholder: t("message.4a010c466653"),
    validationMode: "highlight",
    selectableRange: 1,
    selectableRangeColumns: true,
    selectableRangeClearCells: true,
    selectableRangeInitializeDefault: false,
    editTriggerEvent: "dblclick",
    headerSortClickElement: "icon",
    clipboard: true,
    clipboardCopyStyled: false,
    clipboardCopyConfig: { rowHeaders: false, columnHeaders: false },
    clipboardCopyRowRange: "range",
    clipboardPasteParser: "range",
    clipboardPasteAction: "range",
    rowHeader: { resizable: false, width: 42, hozAlign: "center", formatter: "rownum", cssClass: "range-header-col", editor: false, headerSort: false, headerFilter: false },
    columnDefaults: { resizable: "header", headerFilter: "input", headerFilterPlaceholder: t("message.cd46f1f0c21c") },
    columns: [
      { field: "cmmRowKey", visible: false, headerSort: false, headerFilter: false, clipboard: false, download: false },
      { title: t("message.f3ea6d345e2a"), width: 92, minWidth: 92, headerSort: false, headerFilter: false, clipboard: false, download: false, formatter: deleteActionFormatter, cellClick: deleteActionClick },
      ...activityColumns().map(tabulatorColumn)
    ],
    rowFormatter: formatActivityRow
  });
  state.activityTable.on("cellEdited", cell => markActivityCellDirty(cell));
  state.activityTable.on("clipboardPasted", (_clipboard, pastedData, rows) => {
    if (!canWriteActivities()) return;
    rows.forEach((rowComponent, index) => {
      const fields = Object.keys(pastedData[index] || {});
      fields.forEach(field => markActivityDirty(rowComponent.getData(), field, rowComponent.getIndex()));
      rowComponent.reformat();
    });
    updateDirtyControls();
  });
  state.activityTable.on("tableBuilt", applyGlobalActivityFilter);
}

function tabulatorColumn(column) {
  const definition = {
    title: `${column.label || column.field}${column.required ? " *" : ""}`,
    field: column.field,
    width: Number(column.admin?.width) || 150,
    minWidth: 80,
    editable: cell => canWriteActivities() && !state.deletedActivityRows.has(String(cell.getRow().getIndex()))
      && column.admin?.editable !== false
      && (column.field !== "id" || isNewActivityKey(cell.getRow().getIndex())),
    validator: column.required ? ((_cell, value) => Array.isArray(value) ? value.length > 0 : printable(value).trim() !== "") : undefined,
    headerFilterFunc: (filterValue, rowValue) => matchesActivitySearch(rowValue, parseActivitySearch(filterValue)),
    formatterClipboard: false,
    mutatorEdit: value => normalizeActivityValue(column, value),
    mutatorClipboard: value => normalizeActivityValue(column, value)
  };
  const options = (column.options || []).map(option => typeof option === "object" ? option : { value: option, label: option });
  if (column.type === "textarea") Object.assign(definition, { editor: "textarea", formatter: "textarea", variableHeight: true });
  else if (column.type === "number") Object.assign(definition, { editor: "number", sorter: "number" });
  else if (column.type === "date") Object.assign(definition, { editor: "date", sorter: "date" });
  else if (column.type === "select") Object.assign(definition, {
    editor: "list", editorParams: { values: options, clearable: !column.required }, formatter: cell => optionLabels(cell.getValue(), options)
  });
  else if (column.type === "checkbox" && options.length) Object.assign(definition, {
    editor: "list", editorParams: { values: options, multiselect: true, clearable: !column.required }, formatter: cell => optionLabels(cell.getValue(), options)
  });
  else if (column.type === "checkbox") Object.assign(definition, { editor: "tickCross", formatter: "tickCross", sorter: "boolean" });
  else if (column.type === "url") Object.assign(definition, { editor: "input", formatter: urlFormatter });
  else Object.assign(definition, { editor: "input" });
  return definition;
}

function normalizeActivityValue(column, value) {
  if (column.type === "number" && value !== "") return Number(value);
  if (column.type === "checkbox" && Array.isArray(column.options)) {
    return Array.isArray(value) ? value.map(String) : printable(value).split(",").map(item => item.trim()).filter(Boolean);
  }
  if (column.type === "checkbox") return value === true || value === "true" || value === 1 || value === "1";
  return value ?? "";
}

function optionLabels(value, options) {
  const labels = new Map(options.map(option => [String(option.value), option.label || option.value]));
  const values = Array.isArray(value) ? value : [value];
  return values.filter(item => item !== "" && item != null).map(item => labels.get(String(item)) || item).join(", ");
}

function urlFormatter(cell) {
  const value = printable(cell.getValue());
  if (!value) return "";
  try {
    const url = new URL(value);
    if (!['http:', 'https:'].includes(url.protocol)) return value;
    const link = document.createElement("a"); link.className = "activity-url"; link.href = url.href; link.target = "_blank"; link.rel = "noopener noreferrer"; link.textContent = value;
    return link;
  } catch { return value; }
}

function deleteActionFormatter(cell) {
  if (!canWriteActivities()) return "";
  const rowComponent = cell.getRow(); const row = rowComponent.getData(); const key = String(rowComponent.getIndex());
  const button = document.createElement("button");
  const deleted = state.deletedActivityRows.has(key); const newRow = isNewActivityKey(key);
  button.dataset.rowKey = key; button.dataset.newRow = String(newRow);
  button.type = "button"; button.className = `btn btn-sm activity-delete-button ${deleted ? "btn-outline-secondary" : "btn-outline-danger"}`;
  button.textContent = deleted ? t("message.c7f325f13f16") : (newRow ? t("message.a328a2d80d00") : t("message.5cbdf1fd7082"));
  return button;
}

function deleteActionClick(event, cell) {
  if (!canWriteActivities()) return;
  if (!event.target.closest(".activity-delete-button")) return;
  event.stopPropagation();
  const rowComponent = cell.getRow(); const row = rowComponent.getData(); const key = String(rowComponent.getIndex());
  toggleDelete(row, rowComponent, key, isNewActivityKey(key));
}

function isNewActivityKey(key) { return String(key).startsWith("new-"); }

function formatActivityRow(rowComponent) {
  const key = String(rowComponent.getIndex()); const element = rowComponent.getElement();
  element.dataset.rowKey = key;
  const dirtyFields = state.dirtyActivityFields.get(key);
  element.classList.toggle("activity-dirty", state.newActivityRows.has(key) || Boolean(dirtyFields?.size));
  element.classList.toggle("activity-deleted", state.deletedActivityRows.has(key));
  rowComponent.getCells().forEach(cell => cell.getElement().classList.toggle("dirty-cell", Boolean(dirtyFields?.has(cell.getField()))));
}

function markActivityDirty(row, field, rowKey = row.cmmRowKey) {
  const key = String(rowKey); const fields = state.dirtyActivityFields.get(key) || new Set();
  fields.add(field); state.dirtyActivityFields.set(key, fields);
}

function markActivityCellDirty(cell) {
  if (!canWriteActivities()) return;
  const rowComponent = cell.getRow(); markActivityDirty(rowComponent.getData(), cell.getField(), rowComponent.getIndex());
  cell.getRow().reformat(); updateDirtyControls();
}

function printable(value) {
  if (Array.isArray(value)) return value.join(",");
  if (value && typeof value === "object") return JSON.stringify(value);
  return value == null ? "" : String(value);
}

function parseActivitySearch(value) {
  const input = printable(value).trim();
  const quoted = input.length >= 2 && input.startsWith('"') && input.endsWith('"');
  return { term: (quoted ? input.slice(1, -1) : input).toLocaleLowerCase(), quoted };
}

function matchesActivitySearch(value, query) {
  const text = printable(value).toLocaleLowerCase();
  return query.quoted && query.term === "" ? text.trim() === "" : text.includes(query.term);
}

async function addActivityRow() {
  if (!canWriteActivities()) return;
  const row = { id: "", osmid: "", form_key: "", created_at: "", updated_at: "", cmmRowKey: `new-${state.nextRowKey++}` };
  state.newActivityRows.add(row.cmmRowKey); state.dirtyActivityFields.set(row.cmmRowKey, new Set(["id"]));
  if (state.activityTable) {
    const component = await state.activityTable.addRow(row, false);
    state.rows = state.activityTable.getData();
    await state.activityTable.scrollToRow(component, "bottom", false);
    component.reformat();
  } else {
    state.rows.unshift(row);
  }
  updateDirtyControls();
  setStatus(t("message.754fe5ec5a62"), false, false);
}

function toggleDelete(row, rowComponent = null, rowKey = row.cmmRowKey, newRow = isNewActivityKey(rowKey)) {
  if (!canWriteActivities()) return;
  const key = String(rowKey);
  if (newRow) {
    state.newActivityRows.delete(key); state.dirtyActivityFields.delete(key);
    if (state.activityTable) {
      (rowComponent?.delete() || state.activityTable.deleteRow(key)).then(() => {
        state.rows = state.activityTable.getData(); updateDirtyControls();
      });
    } else {
      state.rows = state.rows.filter(item => item.cmmRowKey !== key);
    }
  } else {
    if (state.deletedActivityRows.has(key)) state.deletedActivityRows.delete(key);
    else state.deletedActivityRows.add(key);
    (rowComponent || state.activityTable?.getRow(key))?.reformat();
  }
  updateDirtyControls();
}

function rowPayload(row) {
  const payload = { app: state.currentApp };
  activityColumns().forEach(column => { if (!['created_at', 'updated_at', 'latitude', 'longitude'].includes(column.field)) payload[column.field] = normalizeActivityValue(column, row[column.field]); });
  return payload;
}

async function saveAll() {
  if (!canWriteActivities()) return;
  if (state.activityTable) state.rows = state.activityTable.getData();
  const requiredColumns = activityColumns().filter(column => column.required);
  const invalid = [];
  state.rows.forEach(row => {
    const key = String(row.cmmRowKey);
    if (state.deletedActivityRows.has(key) || (!isNewActivityKey(key) && !state.dirtyActivityFields.has(key))) return;
    requiredColumns.forEach(column => {
      const value = row[column.field];
      if (Array.isArray(value) ? value.length === 0 : printable(value).trim() === "") {
        invalid.push({ key, id: row.id || t("message.1f4f028acd27"), field: column.field, label: column.label || column.field });
      }
    });
  });
  if (invalid.length) {
    const first = invalid[0];
    setStatus(`${t("message.b28a6f170b5b")}${invalid.length}${t("message.6029cc794ccf")}${first.id} / ${first.label}。`, true);
    if (state.activityTable) {
      els.search.value = "";
      state.activityTable.clearFilter();
      state.activityTable.clearHeaderFilter();
      const cell = state.activityTable.getRow(first.key)?.getCell(first.field);
      try {
        await state.activityTable.scrollToRow(first.key, "center", false);
        await state.activityTable.scrollToColumn(first.field, "middle", false);
      } catch { cell?.getElement()?.scrollIntoView({ block: "center", inline: "center" }); }
      cell?.getElement()?.classList.add("tabulator-validation-fail");
      cell?.edit();
    }
    return;
  }
  const payload = { app: state.currentApp, creates: [], updates: [], deletes: [] };
  state.rows.forEach(row => {
    const key = String(row.cmmRowKey);
    if (state.deletedActivityRows.has(key)) payload.deletes.push(row.id);
    else if (isNewActivityKey(key)) payload.creates.push(rowPayload(row));
    else if (state.dirtyActivityFields.has(key)) payload.updates.push(rowPayload(row));
  });
  setStatus(t("message.eb7b20a3ab69")); els.saveAllButton.disabled = true;
  try {
    const result = await request("activities-batch.php", { method: "POST", body: JSON.stringify(payload) });
    await loadActivities(true);
    setStatus(`${t("message.ec65daeb370f")}${result.created.length}${t("message.b3a91fbe7826")}${result.updated.length}${t("message.da31817c08d1")}${result.deleted.length}）。`);
  } catch (error) { updateDirtyControls(); setStatus(`${t("message.db1615e70550")}${error.message}`, true); }
}

function updateDirtyControls() {
  const keys = new Set([...state.newActivityRows, ...state.deletedActivityRows, ...state.dirtyActivityFields.keys()]);
  const count = keys.size;
  const writable = canWriteActivities();
  els.addButton.disabled = !writable; els.saveAllButton.disabled = !writable || count === 0; els.discardButton.disabled = !writable || count === 0; els.dirtyCount.textContent = count ? String(count) : "";
}

function applyGlobalActivityFilter() {
  if (!state.activityTable) return;
  const query = parseActivitySearch(els.search.value);
  if (!query.quoted && !query.term) { state.activityTable.clearFilter(); return; }
  const fields = activityColumns().map(column => column.field);
  state.activityTable.setFilter(row => fields.some(field => matchesActivitySearch(row[field], query)));
}

function updateExportLinks() {
  const base = `${apiRoot}/activities.php?app=${encodeURIComponent(state.currentApp)}`;
  els.jsonExport.href = base; els.jsonExport.download = `${state.currentApp}-activities.json`;
  els.csvExport.href = `${base}&format=csv`; els.csvExport.download = `${state.currentApp}-activities.csv`;
}

async function importFile(file) {
  if (hasActivityChanges() && !confirmDiscard()) { els.importFile.value = ""; return; }
  const text = await file.text();
  if (file.name.toLowerCase().endsWith(".csv") || file.type === "text/csv") return previewCsv(text);
  let rows;
  try { rows = JSON.parse(text); } catch { setStatus(t("message.1b2142b04ce8"), true); return; }
  if (!Array.isArray(rows)) { setStatus(t("message.92cc85e6f8be"), true); return; }
  try {
    const dry = await request("activity-import.php", { method: "POST", body: JSON.stringify({ app: state.currentApp, rows, dry_run: true }) });
    if (!confirm(`${dry.valid}${t("message.44ca537d5ac0")}${dry.created}${t("message.b3a91fbe7826")}${dry.updated}${t("message.3e4f7bd641f7")}`)) return;
    await request("activity-import.php", { method: "POST", body: JSON.stringify({ app: state.currentApp, rows, dry_run: false }) });
    await loadActivities(true); setStatus(`${dry.valid}${t("message.1e476909030d")}`);
  } catch (error) { setStatus(`${t("message.d247983650d6")}${error.message}`, true); }
  finally { els.importFile.value = ""; }
}

async function previewCsv(csv) {
  try {
    const preview = await request("activity-import-csv.php", { method: "POST", body: JSON.stringify({ app: state.currentApp, csv, dry_run: true }) });
    state.pendingCsv = { csv, preview }; els.csvSummary.textContent = `${preview.valid}${t("message.44ca537d5ac0")}${preview.created}${t("message.b3a91fbe7826")}${preview.updated}）`;
    const updateFields = preview.schema_update_fields || preview.missing_fields || [];
    const details = [];
    if (preview.missing_fields?.length) details.push(`${t("message.70ef5e74ea5a")}${preview.missing_fields.join(", ")}`);
    const typeChanges = Object.entries(preview.schema_changes || {}).filter(([, change]) => change.type)
      .map(([field, change]) => `${field}: ${change.type.from} → ${change.type.to}`);
    if (typeChanges.length) details.push(`${t("message.3b003c713424")}${typeChanges.join(", ")}`);
    const optionChanges = Object.entries(preview.schema_changes || {}).filter(([, change]) => change.added_options?.length)
      .map(([field, change]) => `${field}: +${change.added_options.join(", +")}`);
    if (optionChanges.length) details.push(`${t("message.6794f6098f2e")}${optionChanges.join(" / ")}`);
    const normalized = Object.entries(preview.normalizations || {}).map(([field, count]) => `${field} ${count}${t("message.04d7e6fc2cc7")}`);
    if (normalized.length) details.push(`${t("message.96b2c74f5454")}${normalized.join(", ")}`);
    els.csvMissing.textContent = details.length ? details.join("\n") : t("message.869f659a5257");
    els.addMissingLabel.hidden = updateFields.length === 0; els.addMissingColumns.checked = true; els.csvDialog.showModal();
  } catch (error) { setStatus(`${t("message.b4381a45f284")}${error.message}`, true); els.importFile.value = ""; }
}

async function applyCsv() {
  const pending = state.pendingCsv; if (!pending) return;
  try {
    const updateFields = pending.preview.schema_update_fields || pending.preview.missing_fields || [];
    if (els.addMissingColumns.checked && updateFields.length) {
      const project = currentProject(); const fields = structuredClone(project.schema?.fields || {});
      updateFields.forEach(field => { fields[field] = pending.preview.schema_candidate.fields[field]; });
      const updated = await request(`projects.php?app=${encodeURIComponent(state.currentApp)}`, { method: "PUT", body: JSON.stringify({ schema: { fields } }) });
      replaceProject(updated);
    }
    await request("activity-import-csv.php", { method: "POST", body: JSON.stringify({ app: state.currentApp, csv: pending.csv, dry_run: false }) });
    state.pendingCsv = null; els.csvDialog.close(); els.importFile.value = ""; await loadActivities(true);
    setStatus(`${pending.preview.valid}${t("message.f899fc5cca61")}`);
  } catch (error) { setStatus(`${t("message.6ccd0903edcc")}${error.message}`, true); }
}

async function loadUsers(resetPage = false) {
  if (resetPage) state.userPage = 1;
  const params = new URLSearchParams({ page: String(state.userPage), per_page: "25" });
  if (els.usersSearch.value.trim()) params.set("search", els.usersSearch.value.trim());
  if (els.userStatusFilter.value) params.set("status", els.userStatusFilter.value);
  if (els.userVerifiedFilter.value) params.set("verified", els.userVerifiedFilter.value);
  if (els.userRoleFilter.value) params.set("role", els.userRoleFilter.value);
  if (els.userProjectFilter.value) params.set("project_id", els.userProjectFilter.value);
  setStatus(t("message.9ac20e559949"), false, false);
  try {
    const result = await request(`admin-users.php?${params}`);
    state.users = result.items; state.userPagination = result.pagination; renderUsers();
    setStatus(`${result.pagination.total}${t("message.f0450f797116")}`, false, false);
  } catch (error) { setStatus(`${t("message.1e9b249c15c2")}${error.message}`, true); }
}

function formatJapanDateTime(value) {
  if (!value) return "-";
  // Database timestamps are stored in UTC without a timezone suffix.
  const date = new Date(value.replace(" ", "T") + "Z");
  if (Number.isNaN(date.getTime())) return value;
  const parts = new Intl.DateTimeFormat("en-CA", {
    timeZone: "Asia/Tokyo", year: "numeric", month: "2-digit", day: "2-digit",
    hour: "2-digit", minute: "2-digit", second: "2-digit", hourCycle: "h23"
  }).formatToParts(date);
  const fields = Object.fromEntries(parts.map(part => [part.type, part.value]));
  return `${fields.year}-${fields.month}-${fields.day} ${fields.hour}:${fields.minute}:${fields.second} JST`;
}

function renderUsers() {
  els.usersBody.replaceChildren(...state.users.map(user => {
    const tr = document.createElement("tr");
    const statusIcons = {
      active: '<path d="m5 12 4 4L19 6"/>',
      pending: '<circle cx="12" cy="12" r="8"/><path d="M12 8v5l3 2"/>',
      disabled: '<circle cx="12" cy="12" r="8"/><path d="M6 6l12 12"/>'
    };
    const statusIcon = statusIcons[user.status] || '<circle cx="12" cy="12" r="8"/>';
    tr.innerHTML = `<td><span class="user-name-actions"><button class="user-status-icon status-${escapeAttribute(user.status)}" type="button" aria-label="${escapeAttribute(user.userid)}${t("message.f2ec10125b6a")}${escapeAttribute(user.status)}${t("message.e48726ed6fbc")}${escapeAttribute(user.status)}${t("message.a73af58f19b7")}${statusIcon}</svg></button><span class="user-name">${escapeHtml(user.userid)}</span></span></td><td>${escapeHtml(user.email || "—")}</td>
      <td>${escapeHtml(user.role)}</td><td>${user.email ? (user.email_verified_at ? t("message.1f12f1d2e9de") : t("message.8ac888c7718d")) : ""}</td>
      <td>${escapeHtml(formatJapanDateTime(user.last_login_at))}</td><td>${user.owned_project_count}</td><td>${user.project_count}</td><td></td>`;
    tr.querySelectorAll("td").forEach((cell, index) => {
      cell.dataset.label = [t("message.c30499da17fc"), t("message.32ed60ff9f0c"), t("message.200de0cf73dd"), t("message.dddb07c1bf41"), t("message.c993ef4c6b59"), t("message.5b8068de67f5"), t("message.ecf536eb2831"), t("message.f3ea6d345e2a")][index];
    });
    tr.querySelector(".user-status-icon").addEventListener("click", () => openUser(user.id));
    const resetButton = actionButton("", () => openPasswordReset(user.id));
    resetButton.classList.add("user-password-button");
    resetButton.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="8" cy="15" r="4"/><path d="m11 12 8-8 2 2-2 2 1 1-2 2-1-1-3 3"/></svg>';
    resetButton.setAttribute("aria-label", `${user.userid}${t("message.4ec88ab9e1fb")}`);
    resetButton.title = t("message.ffaa3e6f99de");
    tr.querySelector(".user-name-actions").append(resetButton);
    tr.lastElementChild.append(actionButton(t("message.12d470debc6c"), () => openUser(user.id)));
    return tr;
  }));
  if (!state.users.length) {
    const tr = document.createElement("tr"); tr.className = "users-empty-row"; const td = document.createElement("td"); td.colSpan = 8; td.textContent = t("message.4d6206aa7961"); tr.append(td); els.usersBody.append(tr);
  }
  const pagination = state.userPagination || { page: 1, total_pages: 1, total: 0 };
  els.usersPageInfo.textContent = `${pagination.page} / ${pagination.total_pages}${t("message.1936d001a9c3")}${pagination.total}${t("message.5b2d4f518226")}`;
  els.usersPrevButton.disabled = pagination.page <= 1;
  els.usersNextButton.disabled = pagination.page >= pagination.total_pages;
}

function renderProjectAssignments(container, assignments = []) {
  const selected = new Map(assignments.map(item => [Number(item.project_id), item.role || "editor"]));
  const projects = state.projects.filter(project => project.id !== null);
  if (!projects.length) { container.textContent = t("message.57c6b820d3d1"); return; }
  const controls = document.createElement("div"); controls.className = "project-assignment-controls";
  const filterTitle = document.createElement("p"); filterTitle.className = "project-assignment-filter-title"; filterTitle.textContent = t("message.177609d3edb9");
  const filterRow = document.createElement("div"); filterRow.className = "project-assignment-filter-row";
  const search = document.createElement("input"); search.type = "search"; search.className = "form-control";
  search.placeholder = t("message.d9961985b1b2"); search.setAttribute("aria-label", t("message.e6de7a3103aa"));
  const searchLabel = document.createElement("label"); searchLabel.className = "project-assignment-search";
  searchLabel.append(document.createTextNode(t("message.73c7fc7c7843")), search);
  const selectedOnly = document.createElement("label");
  const selectedOnlyCheckbox = document.createElement("input"); selectedOnlyCheckbox.type = "checkbox";
  selectedOnly.append(selectedOnlyCheckbox, document.createTextNode(t("message.7fbf9d99a9fb")));
  const count = document.createElement("span"); count.className = "project-assignment-count";
  filterRow.append(searchLabel, selectedOnly); controls.append(filterTitle, filterRow, count);
  const list = document.createElement("div"); list.className = "project-assignment-list";
  const rows = projects.map(project => {
    const row = document.createElement("div"); row.className = "project-assignment"; row.dataset.projectId = project.id;
    const checkbox = document.createElement("input"); checkbox.type = "checkbox"; checkbox.checked = selected.has(Number(project.id));
    const label = document.createElement("label"); label.append(checkbox, document.createTextNode(`${project.project_name} (${project.app_key})`));
    const role = document.createElement("select");
    role.append(new Option("viewer", "viewer"), new Option("contributor", "contributor"), new Option("editor", "editor"), new Option("project_admin", "project_admin"));
    role.value = selected.get(Number(project.id)) || "editor"; role.disabled = !checkbox.checked;
    checkbox.addEventListener("change", () => { role.disabled = !checkbox.checked; updateFilter(); });
    row.append(label, role); return row;
  });
  function updateFilter() {
    const query = search.value.trim().toLocaleLowerCase();
    let visible = 0; let assigned = 0;
    rows.forEach(row => {
      const checked = row.querySelector('input[type="checkbox"]').checked;
      if (checked) assigned++;
      row.hidden = (selectedOnlyCheckbox.checked && !checked) || !row.textContent.toLocaleLowerCase().includes(query);
      if (!row.hidden) visible++;
    });
    count.textContent = `${assigned}${t("message.15beec700d96")}${visible}${t("message.4e686c3a9744")}${rows.length}${t("message.5b2d4f518226")}`;
  }
  list.replaceChildren(...rows); container.replaceChildren(controls, list);
  search.addEventListener("input", updateFilter); selectedOnlyCheckbox.addEventListener("change", updateFilter);
  updateFilter();
}

function collectProjectAssignments(container) {
  return [...container.querySelectorAll(".project-assignment")].filter(row => row.querySelector('input[type="checkbox"]').checked).map(row => ({
    project_id: Number(row.dataset.projectId), role: row.querySelector("select").value
  }));
}

function renderUserMetadata(user) {
  const metadata = [
    [t("message.c30499da17fc"), user.userid], [t("message.32ed60ff9f0c"), user.email || t("message.64bdfa48f26c")],
    ...(user.email ? [[t("message.dddb07c1bf41"), user.email_verified_at || t("message.8ac888c7718d")]] : []),
    [t("message.66d381a4cbfe"), user.created_at], [t("message.c993ef4c6b59"), formatJapanDateTime(user.last_login_at)],
    [t("message.f64218e0d95e"), `${user.activity_count}${t("message.04d7e6fc2cc7")}`], [t("message.371d2b828cdb"), `${user.project_count}${t("message.04d7e6fc2cc7")}`]
  ];
  els.userMetadata.replaceChildren(...metadata.flatMap(([label, value]) => {
    const dt = document.createElement("dt"); dt.textContent = label; const dd = document.createElement("dd"); dd.textContent = value; return [dt, dd];
  }));
}

function configureUserEmail(user) {
  const hasEmail = !!user.email;
  const unverified = hasEmail && !user.email_verified_at;
  els.userAddEmailSection.hidden = hasEmail;
  els.userMailActions.hidden = !unverified || user.status === "disabled";
  els.userMailAddress.textContent = user.email || "";
  els.userMailDescription.textContent = t("message.b9553404635f");
  els.resendVerificationButton.hidden = !unverified || user.status === "disabled";
}

async function openUser(id) {
  try {
    const user = await request(`admin-users.php?id=${encodeURIComponent(id)}`); state.currentUser = user;
    els.userDialogTitle.textContent = `${t("message.d1e5f8a1131f")}${user.userid}`;
    renderUserMetadata(user);
    els.editUserStatus.value = user.status; els.editUserRole.value = user.role;
    renderProjectAssignments(els.editUserProjects, user.projects);
    configureUserEmail(user);
    els.addUserEmail.value = ""; els.userEmailStatus.textContent = ""; els.userEmailStatus.classList.remove("error");
    els.userDialog.showModal();
  } catch (error) { setStatus(`${t("message.7bb7ae898ea3")}${error.message}`, true); }
}

async function openPasswordReset(id) {
  try {
    const user = await request(`admin-users.php?id=${encodeURIComponent(id)}`);
    state.passwordUser = user;
    els.passwordResetTitle.textContent = `${user.userid}${t("message.4ec88ab9e1fb")}`;
    els.passwordResetEmailSection.hidden = !user.email || user.status === "disabled" || (user.status === "active" && !user.email_verified_at);
    els.passwordResetEmailAddress.textContent = user.email || "";
    els.editUserPassword.value = ""; els.editUserPassword.setCustomValidity("");
    els.editUserPasswordConfirmation.value = ""; els.editUserPasswordConfirmation.setCustomValidity("");
    els.userPasswordStatus.textContent = "";
    els.passwordResetDialog.showModal();
  } catch (error) { setStatus(`${t("message.710454a8b117")}${error.message}`, true); }
}

async function addUserEmail() {
  const user = state.currentUser;
  if (!user || user.email || !els.addUserEmail.reportValidity()) return;
  const email = els.addUserEmail.value.trim();
  if (!confirm(`${user.userid}${t("message.9ceb96186bb1")}${email}${t("message.e60e09179e7c")}`)) return;
  els.addUserEmailButton.disabled = true;
  els.userEmailStatus.textContent = t("message.e997427e4e70");
  els.userEmailStatus.classList.remove("error");
  try {
    const updated = await request("admin-users.php", { method: "POST", body: JSON.stringify({ action: "add_email", id: user.id, email }) });
    state.currentUser = updated;
    renderUserMetadata(updated); configureUserEmail(updated);
    els.userEmailStatus.textContent = updated.verification_email_sent
      ? t("message.0fbb049e2b0b")
      : t("message.5c846cc26118");
    els.userEmailStatus.classList.toggle("error", !updated.verification_email_sent);
    await loadUsers();
  } catch (error) {
    els.userEmailStatus.textContent = error.code === "identity_already_registered"
      ? t("message.5f0e428c4023")
      : `${t("message.17e0a556aaad")}${error.message}`;
    els.userEmailStatus.classList.add("error");
  } finally { els.addUserEmailButton.disabled = false; }
}

async function saveUser() {
  const user = state.currentUser; if (!user) return;
  try {
    const updated = await request(`admin-users.php?id=${encodeURIComponent(user.id)}`, { method: "PUT", body: JSON.stringify({
      status: els.editUserStatus.value, role: els.editUserRole.value, projects: collectProjectAssignments(els.editUserProjects)
    }) });
    state.currentUser = updated; els.userDialog.close(); await loadUsers(); setStatus(`${updated.userid}${t("message.cf20183f45da")}`);
  } catch (error) { setStatus(`${t("message.162eb73dc227")}${error.message}`, true); }
}

async function createUser() {
  updateNewUserMode();
  const direct = els.newUserEmail.value.trim() === "";
  els.newUserPasswordConfirmation.setCustomValidity(direct && els.newUserPassword.value !== els.newUserPasswordConfirmation.value ? t("message.4ecb0d950894") : "");
  if (!els.newUserForm.reportValidity()) return;
  try {
    const payload = {
      userid: els.newUserId.value, email: els.newUserEmail.value, role: els.newUserRole.value,
      projects: collectProjectAssignments(els.newUserProjects)
    };
    if (direct) {
      payload.password = els.newUserPassword.value;
      payload.password_confirmation = els.newUserPasswordConfirmation.value;
    }
    const user = await request("admin-users.php", { method: "POST", body: JSON.stringify(payload) });
    els.newUserDialog.close(); els.newUserForm.reset(); updateNewUserMode(); await loadUsers(true);
    if (user.creation_mode === "direct") setStatus(`${user.userid}${t("message.1211d9d9b99b")}`);
    else setStatus(`${user.userid}${t("message.445aa521e166")}${user.password_setup_email_sent ? t("message.0fb2d276b267") : t("message.fb57928a121b")}`);
  } catch (error) { setStatus(`${t("message.462841e38d21")}${error.message}`, true); }
}

function updateNewUserMode() {
  const direct = els.newUserEmail.value.trim() === "";
  els.newUserPasswordFields.hidden = !direct;
  els.newUserPassword.required = direct; els.newUserPassword.disabled = !direct;
  els.newUserPasswordConfirmation.required = direct; els.newUserPasswordConfirmation.disabled = !direct;
  els.newUserSubmitButton.textContent = direct ? t("message.aec344de806d") : t("message.68bb049d6e5a");
  els.newUserModeHint.textContent = direct
    ? t("message.aefe82ca3ad5")
    : t("message.39c2f45faf07");
  if (!direct) els.newUserPasswordConfirmation.setCustomValidity("");
}

async function userMailAction(action, user) {
  if (!user) return;
  const label = action === "resend_verification" ? t("message.b6ca4f615a51") : t("message.b54c9ca70481");
  if (!user.email || !confirm(t("users.confirm_send_email", { email: user.email, message: label }))) return;
  try {
    const result = await request("admin-users.php", { method: "POST", body: JSON.stringify({ action, id: user.id }) });
    const sent = action === "resend_verification" ? result.verification_email_sent : result.reset_email_sent;
    setStatus(`${label}${t("message.eba95ba0c666")}${sent ? t("message.7860024cecc2") : t("message.04da77994baf")}。`, !sent);
  } catch (error) { setStatus(`${label}${t("message.dab970d4ed88")}${error.message}`, true); }
}

async function setUserPassword() {
  const user = state.passwordUser; if (!user) return;
  const password = els.editUserPassword.value;
  const confirmation = els.editUserPasswordConfirmation.value;
  els.editUserPassword.setCustomValidity(password ? "" : t("message.f1f150d02c2b"));
  els.editUserPasswordConfirmation.setCustomValidity(!confirmation
    ? t("message.763fc411bd44")
    : (password !== confirmation ? t("message.e21670475873") : ""));
  if (!els.editUserPassword.reportValidity() || !els.editUserPasswordConfirmation.reportValidity()) return;
  els.setUserPasswordButton.disabled = true; els.userPasswordStatus.textContent = t("message.e59f52ab4974");
  try {
    const result = await request("admin-users.php", { method: "POST", body: JSON.stringify({
      action: "set_password", id: user.id, password, password_confirmation: confirmation
    }) });
    state.passwordUser = result.user;
    if (user.id === state.sessionUser?.id) {
      els.userid.value = user.userid; els.password.value = password;
      await request('console-session.php', { method: 'POST', basic: true });
      els.password.value = '';
    }
    els.editUserPassword.value = ""; els.editUserPasswordConfirmation.value = "";
    els.passwordResetDialog.close();
    setStatus(`${user.userid}${t("message.830552887f67")}`);
  } catch (error) {
    els.userPasswordStatus.textContent = `${t("message.e77c499f3e01")}${error.message}`;
  } finally { els.setUserPasswordButton.disabled = false; }
}

function escapeHtml(value) { const span = document.createElement("span"); span.textContent = String(value ?? ""); return span.innerHTML; }
function escapeAttribute(value) { return escapeHtml(value).replace(/"/g, "&quot;"); }

els.loginForm.addEventListener("submit", event => { event.preventDefault(); connect(); });
els.logoutButton.addEventListener("click", logout);
document.querySelectorAll("[data-view]").forEach(button => button.addEventListener("click", () => showView(button.dataset.view)));
els.mobileViewSelect.addEventListener("change", () => {
  const view = els.mobileViewSelect.value;
  showView(view);
  els.mobileViewSelect.value = ["activities", "users", "trash"].includes(state.view) ? state.view : "projects";
});
els.statusModal.addEventListener("close", () => {
  if (statusReturnDialog && !els.adminMain.hidden) statusReturnDialog.showModal();
  statusReturnDialog = null;
});
document.querySelectorAll("[data-close-dialog]").forEach(button => button.addEventListener("click", () => document.getElementById(button.dataset.closeDialog).close()));
els.newProjectButton.addEventListener("click", () => els.projectDialog.showModal());
els.newAppKey.addEventListener("input", () => {
  const start = els.newAppKey.selectionStart;
  const end = els.newAppKey.selectionEnd;
  const normalized = els.newAppKey.value.toLowerCase();
  if (normalized === els.newAppKey.value) return;
  els.newAppKey.value = normalized;
  if (start !== null && end !== null) els.newAppKey.setSelectionRange(start, end);
});
els.projectForm.addEventListener("submit", event => { event.preventDefault(); if (event.submitter?.value === "create") createProject(); });
els.columnsBackButton.addEventListener("click", () => {
  showView("projects");
  if (state.view === "projects") { state.schemaDirty = false; updateSchemaDirty(); }
});
els.addColumnButton.addEventListener("click", async () => {
  if (!state.columnsTable) return;
  const row = await state.columnsTable.addRow(columnDefinitionRow(), false);
  markSchemaDirty();
  await row.scrollTo(); row.getCell("field")?.edit();
});
els.saveColumnsButton.addEventListener("click", saveColumns);
els.projectName.addEventListener("input", markSchemaDirty); els.projectFrontendUrl.addEventListener("input", markSchemaDirty);
els.projectFrontendPublic.addEventListener("change", markSchemaDirty);
els.activitiesBackButton.addEventListener("click", () => {
  showView("projects");
  if (state.view === "projects") {
    state.newActivityRows.clear(); state.deletedActivityRows.clear(); state.dirtyActivityFields.clear();
    updateDirtyControls();
  }
});
els.addButton.addEventListener("click", addActivityRow);
els.discardButton.addEventListener("click", () => loadActivities()); els.saveAllButton.addEventListener("click", saveAll);
els.search.addEventListener("input", applyGlobalActivityFilter);
els.appSelect.addEventListener("change", () => { if (!confirmDiscard()) { els.appSelect.value = state.currentApp; return; } state.currentApp = els.appSelect.value; state.rows = []; showView("activities", true); });
els.importFile.addEventListener("change", () => els.importFile.files[0] && importFile(els.importFile.files[0]));
els.csvForm.addEventListener("submit", event => { event.preventDefault(); if (event.submitter?.value === "import") applyCsv(); });
els.usersLoadButton.addEventListener("click", () => loadUsers(true));
els.usersSearch.addEventListener("keydown", event => { if (event.key === "Enter") { event.preventDefault(); loadUsers(true); } });
els.usersPrevButton.addEventListener("click", () => { state.userPage--; loadUsers(); });
els.usersNextButton.addEventListener("click", () => { state.userPage++; loadUsers(); });
els.newUserButton.addEventListener("click", () => { els.newUserForm.reset(); updateNewUserMode(); renderProjectAssignments(els.newUserProjects); els.newUserDialog.showModal(); });
els.newUserEmail.addEventListener("input", updateNewUserMode);
els.newUserPasswordConfirmation.addEventListener("input", () => els.newUserPasswordConfirmation.setCustomValidity(""));
els.newUserForm.addEventListener("submit", event => { event.preventDefault(); if (event.submitter?.value === "create") createUser(); });
els.userForm.addEventListener("submit", event => { event.preventDefault(); if (event.submitter?.value === "save") saveUser(); });
els.addUserEmailButton.addEventListener("click", addUserEmail);
els.resendVerificationButton.addEventListener("click", () => userMailAction("resend_verification", state.currentUser));
els.sendPasswordResetButton.addEventListener("click", () => userMailAction("send_password_reset", state.passwordUser));
els.setUserPasswordButton.addEventListener("click", setUserPassword);
els.editUserPassword.addEventListener("input", () => els.editUserPassword.setCustomValidity(""));
els.editUserPasswordConfirmation.addEventListener("input", () => els.editUserPasswordConfirmation.setCustomValidity(""));
window.addEventListener("beforeunload", event => { if (hasUnsavedChanges()) { event.preventDefault(); event.returnValue = ""; } });
