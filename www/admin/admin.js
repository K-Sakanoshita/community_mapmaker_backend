"use strict";

window.addEventListener('DOMContentLoaded', async () => {
  if (new URLSearchParams(location.search).get('view') === 'projects') { location.replace(SiteLanguage.url(new URL('../#projects', document.baseURI))); return; }
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
const fieldTypes = ["text", "textarea", "number", "date", "datetime", "select", "checkbox", "url", "image", "boolean"];
const optionFieldTypes = new Set(["select", "checkbox"]);
const state = {
  activityLoadController: null, activityLoading: false, activityLoadFailed: false,
  projects: [], currentApp: new URLSearchParams(location.search).get("app") || "",
  view: new URLSearchParams(location.search).get("view") || "projects",
  sessionUser: null,
  schema: { fields: {} }, rows: [], columnsTable: null, activityTable: null, nextColumnKey: 1, nextRowKey: 1,
  newActivityRows: new Set(), deletedActivityRows: new Set(), dirtyActivityFields: new Map(),
  bulkDeleteVersion: 0, bulkDeleteTarget: null, bulkDeleteLoading: false, bulkDeleteBusy: false, csvImportVersion: 0, csvImporting: false, csvApplying: false, columnImportVersion: 0, pendingColumnImport: null, schemaDirty: false, pendingCsv: null, userLoadVersion: 0, users: [], userPage: 1, userPagination: null, currentUser: null, passwordUser: null,
  deletedUsers: [], deletedUserPage: 1, deletedUserPagination: null, deletedUserLoadVersion: 0, deletedUsersLoaded: false, deletedUserTarget: null, deletedUserBusy: false
};
const ids = [
  "deleteAllActivitiesButton", "bulkActivityDeleteDialog", "bulkActivityDeleteForm", "bulkActivityDeleteProject", "bulkActivityDeleteCount", "bulkActivityDeleteKey",
  "bulkActivityDeleteConfirm", "bulkActivityDeleteCancel", "bulkActivityDeleteClose", "bulkActivityDeleteRetry",
  "csvImportStatus", "exportColumnsButton", "importColumnsButton", "importColumnsFile", "importColumnsDialog", "importColumnsSummary", "importColumnsChanges", "applyColumnsImportButton",
  "deletedUsersSection", "deletedUsersBody", "deletedUsersStatus", "deletedUsersRetryButton", "deletedUsersPrevButton", "deletedUsersNextButton", "deletedUsersPageInfo",
  "deletedUserActionDialog", "deletedUserActionForm", "deletedUserActionTitle", "deletedUserActionTarget", "deletedUserActionDescription", "deletedUserActionConfirmation", "deletedUserActionSubmit",
  "projectAdminsDialog", "projectAdminsTitle", "projectAdminsName", "projectAdminsForm", "projectAdminsControls", "projectAdminUserid", "projectAdminsStatus", "projectAdminsList",
  "activityLoadStatus", "activityRetryButton", "sessionLoading", "loginScreen", "loginForm", "loginStatus", "userid", "password", "connectButton", "adminHeader", "adminMain", "currentAdmin", "logoutButton", "navigationProjects", "navigationDetail", "navigationDetailItem", "navigationCurrent", "navigationCurrentItem", "projectDetailContent", "projectManagementActions",
  "status", "statusToast", "newProjectButton", "trashStatus", "trashProjectsBody",
  "pageRenderingEnabled", "pageRenderingPath", "pageRenderingTitle", "pageRenderingDescription", "pageRenderingImage",
  "columnsProjectKey", "projectName", "projectFrontendUrl", "projectFrontendPublic", "projectRecruiting", "columnsTable", "addColumnButton", "saveColumnsButton",
  "activitiesFrontendLink", "appSelect", "search", "addButton", "discardButton", "saveAllButton", "dirtyCount",
  "jsonExport", "csvExport", "importFile", "activityTable", "projectDialog", "projectForm",
  "newProjectName", "newAppKey", "newProjectFrontendUrl", "newProjectFrontendPublic", "csvDialog", "csvForm", "csvSummary", "csvMissing", "addMissingLabel", "addMissingColumns",
  "deleteUserSection", "deleteUserButton", "deleteUserConfirmation", "deletedUserNotice",
  "newUserButton", "usersSearch", "userStatusFilter", "userVerifiedFilter", "userRoleFilter", "userProjectFilter", "usersLoadButton",
  "usersBody", "usersPrevButton", "usersNextButton", "usersPageInfo", "newUserDialog", "newUserForm", "newUserId", "newUserEmail",
  "newUserModeHint", "newUserPasswordFields", "newUserPassword", "newUserPasswordConfirmation", "newUserSubmitButton", "newUserRole", "newUserProjects",
  "headerLoginLink", "userDialog", "userForm", "userDialogTitle", "userMetadata", "userPasswordResetButton", "editUserStatus", "editUserRole",
  "editUserProjects", "userAddEmailSection", "addUserEmail", "addUserEmailButton", "userEmailStatus", "userMailActions", "userMailAddress", "userMailDescription", "resendVerificationButton",
  "passwordResetDialog", "passwordResetTitle", "passwordResetUserid", "passwordResetEmailSection", "passwordResetEmailAddress", "sendPasswordResetButton", "editUserPassword", "editUserPasswordConfirmation", "setUserPasswordButton", "userPasswordStatus"
];
const els = Object.fromEntries(ids.map(id => [id, document.getElementById(id)]));

let statusToastTimer = null;

function isProgressMessage(message) { return message.endsWith("…") || message.endsWith("..."); }

function hideStatusToast() {
  clearTimeout(statusToastTimer);
  if (typeof els.statusToast.hidePopover === "function" && els.statusToast.matches(":popover-open")) els.statusToast.hidePopover();
  els.statusToast.hidden = true;
}

function setStatus(message, error = false, showToast = true) {
  hideStatusToast();
  els.status.textContent = message;
  els.statusToast.classList.toggle("is-error", error);
  if (!showToast || !message || isProgressMessage(message)) return;
  els.statusToast.hidden = false;
  // A manual popover stays above open dialogs without blocking input or moving focus.
  if (typeof els.statusToast.showPopover === "function") els.statusToast.showPopover();
  statusToastTimer = setTimeout(hideStatusToast, 5000);
}

function setLoginStatus(message, error = false) {
  els.loginStatus.textContent = message;
  els.loginStatus.classList.toggle("error", error);
  els.loginStatus.classList.toggle("success", !error && !isProgressMessage(message));
}

function showAdminConsole() {
  window.SiteSessionHeader.render(state.sessionUser);
  els.loginScreen.hidden = true;
  els.adminHeader.hidden = false;
  els.adminMain.hidden = false;
}

async function logout() {
  if (!confirmDiscard()) return;
  cancelActivityLoad();
  try { await request('console-session.php', { method: 'DELETE' }); }
  catch (error) { setStatus(`${t("message.7a3196c1a7d1")}${error.message}`, true); return; }
  state.columnsTable?.destroy(); state.columnsTable = null;
  state.activityTable?.destroy(); state.activityTable = null;
  state.userLoadVersion++; state.deletedUserLoadVersion++; state.deletedUsersLoaded = false; state.deletedUsers = []; state.deletedUserTarget = null; els.deletedUsersBody.replaceChildren();
  state.projects = []; state.rows = []; state.users = []; state.currentUser = null; state.passwordUser = null; state.sessionUser = null;
  state.schema = { fields: {} }; state.schemaDirty = false;
  state.newActivityRows.clear(); state.deletedActivityRows.clear(); state.dirtyActivityFields.clear();
  document.querySelectorAll("dialog[open]").forEach(dialog => dialog.close());
  document.body.classList.remove("activity-spreadsheet-view");
  els.adminHeader.hidden = true; els.adminMain.hidden = true; els.loginScreen.hidden = false;
  window.SiteSessionHeader.render(null);
  hideStatusToast();
  els.currentAdmin.textContent = ""; els.password.value = ""; els.status.textContent = "";
  state.view = "projects"; updateUrl();
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
    activity_delete_conflict: t("bulk_activities.conflict"),
    project_already_exists: t("message.38c9fa440047"),
    activity_already_exists: t("message.e384296911a8"),
    identity_already_registered: t("message.e27adb1122a5"),
    user_not_found: t("message.41cafa64bec1"),
    project_creator_required: t("admins.owner_required"),
    last_project_administrator: t("admins.last"),
    last_active_admin: t("message.e089b19d2feb"),
    user_deleted: t("users.deleted_readonly"),
    user_not_deleted: t("users.not_deleted"),
    self_deletion_not_allowed: t("users.delete_self"),
    rate_limit_exceeded: t("message.01b95783a916"),
    payload_too_large: t("message.f9adeb9e3b6d"),
    invalid_json: t("message.7b10c7c4bbde"),
    invalid_response: t("message.1794b352a30c"),
    server_error: t("message.d8f79fb75360")
  };
  if (payload?.code === "validation_failed" && payload.errors && typeof payload.errors === "object") {
    const validationMessages = {
      "Page rendering must be an object.": t("page_validation.object"),
      "Unknown page rendering setting.": t("page_validation.unknown"),
      "Enabled must be true or false.": t("page_validation.enabled"),
      "Invalid text or maximum length exceeded.": t("page_validation.text"),
      "Use a root-relative path containing {osm_type} and {osm_id} once each.": t("page_validation.path"),
      "Use {name} and optional {project_name}.": t("page_validation.title"),
      "Image URL must be an absolute HTTP or HTTPS URL without credentials or fragments.": t("page_validation.image"),
      "Page rendering requires an absolute frontend URL without query or fragment.": t("page_validation.frontend"),
      "Enter the exact project key to move all activities to deleted.": t("bulk_activities.key_error"),
      "A valid deletion preview is required.": t("bulk_activities.preview_error"),
      "Latitude and longitude must be supplied together.": t("validation.coordinates_pair"),
      "Activity ID is required for import.": t("validation.activity_id_required"),
      "Activity ID is duplicated in this import.": t("validation.activity_id_duplicate"),
      "Activity must be 1-128 safe characters.": t("validation.activity_id_format"),
      "OSM ID is required.": t("validation.osmid_required"),
      "OSM ID must look like node/123, way/456, or relation/789.": t("validation.osmid_format"),
      "CSV content is empty.": t("validation.csv_empty"),
      "CSV must contain a header row.": t("validation.csv_header"),
      "CSV must contain an id (or activity_key) column.": t("validation.csv_id_header"),
      "CSV header names must not be empty.": t("validation.csv_empty_header"),
      "CSV header names must be unique.": t("validation.csv_duplicate_header"),
      "CSV row has more values than the header.": t("validation.csv_extra_values"),
      "CSV must contain at least one data row.": t("validation.csv_no_rows"),
      "At most 5000 activities can be imported at once.": t("validation.import_limit"),
      "Expected a numeric value.": t("validation.number"),
      "Expected a valid YYYY-MM-DD date.": t("validation.date"),
      "Expected a valid URL.": t("validation.url"),
      "This field is required.": t("validation.required"),
      "Value is not an allowed option.": t("validation.option"),
      "One or more values are not allowed options.": t("validation.options"),

      "Enter the exact user ID to confirm deletion.": t("users.delete_confirm_error"),
      "This user cannot be assigned as a project administrator.": t("admins.target_invalid"),
      "This user is not a project administrator.": t("admins.not_assigned"),
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
    const leaves = [];
    function flattenErrors(errors, prefix = []) {
      for (const [key, value] of Object.entries(errors)) {
        const path = [...prefix, ...key.split(".")];
        if (value && typeof value === "object") flattenErrors(value, path);
        else leaves.push([path, value]);
      }
    }
    flattenErrors(payload.errors);
    const details = leaves.map(([path, message]) => {
      let rowLocation = "";
      if ((path[0] === "csv_rows" || path[0] === "rows") && /^\d+$/.test(path[1] || "")) {
        rowLocation = t(path[0] === "csv_rows" ? "errors.csv_row" : "errors.data_row", { row: Number(path[1]) + (path[0] === "rows" ? 1 : 0) });
        path = path.slice(2);
      }
      const [key, part, index] = path;
      const systemLabels = { id: "ID", activity: "ID", activity_key: "ID", osmid: "OSM ID", csv: "CSV", headers: t("errors.csv_headers"), rows: t("errors.import_rows") };
      const column = state.columnsTable?.getData()?.find(row => row.field === key);
      const label = systemLabels[key] || column?.label || state.schema?.fields?.[key]?.label || key;
      const fieldLocation = key === "headers" && /^\d+$/.test(part || "")
        ? t("errors.csv_header_column", { column: Number(part) + 1 })
        : part === "options" ? t("errors.option_location", { label, row: /^\d+$/.test(index || "") ? Number(index) + 1 : "" })
        : label ? t("errors.field_location", { label }) : "";
      const location = [rowLocation, fieldLocation].filter(Boolean).join(" / ");
      const lengthLimit = typeof message === "string" && message.match(/^Must not exceed (\d+) bytes\.$/);
      const reason = (Object.hasOwn(validationMessages, message) ? validationMessages[message] : null) || (lengthLimit ? t("validation.max_bytes", { max: lengthLimit[1] }) : typeof message === "string" ? message : t("message.2a600dc02f7c"));
      return `${location}: ${reason}`;
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

function projectRoleLabel(role) {
  return ["viewer", "contributor", "editor", "project_admin"].includes(role) ? t(`project_role.${role}`) : role === "banned" ? t("users.banned") : role;
}
function isProjectUsers() { return state.view === "users"; }
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
  const url = SiteLanguage.url(location.href);
  url.searchParams.set("view", state.view);
  if (state.currentApp && state.view !== "all-users") url.searchParams.set("app", state.currentApp);
  else url.searchParams.delete("app");
  history.replaceState(null, "", url);
}

function showView(view, force = false) {
  if (!force && !confirmDiscard()) return;
  if (view === "projects") { location.assign(SiteLanguage.url(new URL("../#projects", document.baseURI))); return; }
  if (["all-users", "trash"].includes(view) && !isAdmin()) view = currentProject() ? "detail" : "projects";
  if (view === "users" && (!canManageProject() || !currentProject()?.id)) view = currentProject() ? "detail" : "projects";
  if (view === "columns" && !canManageProject()) view = currentProject() ? "detail" : "projects";
  if (["detail", "columns", "activities"].includes(view) && !currentProject()) view = "projects";
  if (!["projects", "detail", "columns", "activities", "users", "all-users", "trash", "create"].includes(view)) view = "projects";
  if (view === "projects") { location.assign(SiteLanguage.url(new URL("../#projects", document.baseURI))); return; }
  if (view !== state.view) {
    state.schemaDirty = false;
    state.newActivityRows.clear(); state.deletedActivityRows.clear(); state.dirtyActivityFields.clear();
    updateSchemaDirty(); updateDirtyControls();
  }
  if (view !== "activities") cancelActivityLoad();
  state.view = view;
  document.body.classList.toggle("activity-spreadsheet-view", view === "activities");
  document.querySelectorAll(".view").forEach(section => { section.hidden = section.id !== `${view === "all-users" ? "users" : view}View`; });
  document.querySelectorAll("[data-view]").forEach(button => button.classList.toggle("active", button.dataset.view === view));
  els.navigationDetailItem.hidden = ["projects", "create", "all-users"].includes(view) || !currentProject();
  if (view === "projects") els.navigationProjects.setAttribute("aria-current", "page");
  else els.navigationProjects.removeAttribute("aria-current");
  if (view === "detail") els.navigationDetail.setAttribute("aria-current", "page");
  else els.navigationDetail.removeAttribute("aria-current");
  const detailUrl = new URL(location.href); detailUrl.searchParams.set("app", state.currentApp); detailUrl.searchParams.set("view", "detail");
  els.navigationDetail.href = detailUrl.href;
  els.navigationDetail.textContent = currentProject()?.project_name || t("navigation.detail");
  const labels = { columns: t("navigation.manage_project"), activities: t("projects.manage_activities"), users: t("navigation.users"), trash: t("navigation.trash"), create: t("navigation.create") };
  els.navigationProjects.closest("li").hidden = view === "all-users";
  labels["all-users"] = t("users.all");
  if (view === "all-users") document.getElementById("headerAllUsersLink").setAttribute("aria-current", "page");
  else document.getElementById("headerAllUsersLink").removeAttribute("aria-current");
  els.navigationCurrent.textContent = labels[view] || "";
  document.title = `${labels[view] || currentProject()?.project_name || t("navigation.detail")} | Community Map Maker`;
  els.navigationCurrentItem.hidden = !labels[view];
  updateUrl();
  if (view === "create") els.projectDialog.showModal();
  if (view === "detail") renderProjectDetail();
  if (view === "columns") openColumns();
  if (view === "activities") loadActivities();
  if (["users", "all-users"].includes(view)) {
    configureUsersView(); loadUsers(true);
  }
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
    if (state.view === "mypage") { location.assign(SiteLanguage.url(new URL("../mypage.html", document.baseURI))); return; }
    const requestedProjectUnavailable = state.view !== "all-users" && !!state.currentApp && !currentProject();
    if (!currentProject()) state.currentApp = state.projects[0]?.app_key || "";
    if (requestedProjectUnavailable) state.view = "projects";
    configureConsoleAccess();
    populateProjectSelect();
    showAdminConsole();
    setStatus(`${state.projects.length}${t("message.88c406f67b0f")}`, false, false);
    if (state.view === "users" && isAdmin() && currentProject()?.id) {
      els.userProjectFilter.value = String(currentProject().id);
    }
    if (!restore && !["create", "all-users", "users"].includes(state.view)) state.view = "projects";
    showView(state.view, true);
    if (requestedProjectUnavailable) setStatus(t("projects.unavailable"), true);
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


function renderProjectDetail() {
  const project = currentProject();
  if (!project) return;
  const panel = document.createElement("article"); panel.className = "project-card card";
  const title = document.createElement("h3"); title.textContent = project.project_name;
  const key = document.createElement("p"); key.className = "key"; key.textContent = `KEY: ${project.app_key}`;
  const count = document.createElement("p"); count.className = "project-card-count";
  const label = document.createElement("span"); label.textContent = t("projects.activity_count_label");
  const total = document.createElement("strong"); total.textContent = Number(project.activity_count ?? 0).toLocaleString(document.documentElement.lang);
  count.append(label, total);
  const actions = document.createElement("div"); actions.className = "project-detail-menu";
  if (canManageProject()) actions.append(actionButton(t("navigation.manage_project"), () => showView("columns")));
  actions.append(actionButton(t("projects.manage_activities"), () => showView("activities"), "primary"));
  if (canManageProject() && project.id) actions.append(actionButton(t("navigation.users"), () => showView("users")));
  if (isAdmin()) {
    actions.append(actionButton(t("navigation.trash"), () => showView("trash")));
  }
  panel.append(title, key, count, actions);
  const url = safeFrontendUrl(project.frontend_url);
  if (url) {
    const map = document.createElement("a"); map.href = url; map.target = "_blank"; map.rel = "noopener noreferrer";
    map.textContent = t("projects.open_map"); map.className = "project-frontend-link"; panel.append(map);
  }
  els.projectDetailContent.replaceChildren(panel);
  loadProjectDetailPreview(project, panel);
  ProjectLeave.load({ appKey: project.app_key, actions, info: panel, onLeave: () => {
    location.assign(SiteLanguage.url(new URL("../mypage.html", document.baseURI)));
  } });
}

async function loadProjectDetailPreview(project, panel) {
  // Public preview metadata enriches the detail without delaying its management menu.
  const query = `app=${encodeURIComponent(project.app_key)}`;
  const responses = await Promise.allSettled([
    request(`project-preview.php?${query}`), request(`public-projects.php?${query}`),
  ]);
  if (!panel.isConnected || state.currentApp !== project.app_key) return;
  const preview = responses[0].status === "fulfilled" ? responses[0].value : null;
  if (preview && typeof preview.description === "string" && preview.description) {
    const description = document.createElement("p"); description.className = "section-desc";
    description.textContent = preview.description; panel.insertBefore(description, panel.querySelector(".project-card-count"));
  }
  const imageUrl = safeFrontendUrl(preview?.image_url);
  if (imageUrl) {
    const image = document.createElement("img"); image.className = "admin-project-image";
    image.alt = project.project_name; image.decoding = "async"; image.referrerPolicy = "no-referrer";
    image.hidden = true; image.addEventListener("load", () => { image.hidden = false; });
    image.addEventListener("error", () => { image.hidden = true; });
    image.src = imageUrl; panel.prepend(image);
  }
  const summaries = responses[1].status === "fulfilled" ? responses[1].value : [];
  const summary = Array.isArray(summaries) ? summaries.find(item => item.app_key === project.app_key) : null;
  if (Array.isArray(summary?.contributors)) {
    const names = summary.contributors.filter(name => typeof name === "string");
    const contributors = document.createElement("details"); contributors.className = "admin-project-contributors";
    const caption = document.createElement("summary"); caption.textContent = t("navigation.contributors", { count: names.length });
    const icons = document.createElement("span"); icons.className = "admin-contributor-icons";
    names.slice(0, 8).forEach(name => {
      const icon = document.createElement("span"); icon.className = "admin-contributor-icon";
      icon.textContent = Array.from(name)[0]?.toUpperCase() || "?"; icon.title = name;
      icon.setAttribute("role", "img"); icon.setAttribute("aria-label", name); icons.append(icon);
    });
    caption.append(icons); contributors.append(caption);
    const list = document.createElement("ul"); names.forEach(name => {
      const item = document.createElement("li"); item.textContent = name; list.append(item);
    });
    contributors.append(list); panel.insertBefore(contributors, panel.querySelector(".project-detail-menu"));
  }
}

let projectAdminsApp = null;
let projectAdminsBusy = false;

async function openProjectAdmins(project) {
  if (projectAdminsBusy) return;
  projectAdminsApp = project.app_key;
  els.projectAdminsName.textContent = `${project.project_name} (${project.app_key})`;
  els.projectAdminUserid.value = "";
  els.projectAdminsList.replaceChildren();
  els.projectAdminsStatus.textContent = t("admins.loading");
  els.projectAdminsDialog.showModal();
  await updateProjectAdmins("GET");
}

function renderProjectAdmins(items) {
  els.projectAdminsList.replaceChildren();
  for (const item of items) {
    const row = document.createElement("li");
    row.className = "list-group-item d-flex flex-wrap align-items-center justify-content-between gap-2";
    const name = document.createElement("span");
    name.textContent = item.userid + (item.is_creator ? ` (${t("admins.creator")})` : "")
      + (!item.can_manage ? ` (${t("admins.unavailable")})` : "");
    row.append(name);
    if (!item.is_creator) row.append(actionButton(t("admins.remove"), async () => {
      if (projectAdminsBusy || !confirm(t("admins.confirm", { userid: item.userid }))) return;
      await updateProjectAdmins("DELETE", item.userid);
    }, "danger"));
    els.projectAdminsList.append(row);
  }
}

async function updateProjectAdmins(method, userid = null) {
  if (projectAdminsBusy) return;
  projectAdminsBusy = true;
  const app = projectAdminsApp;
  els.projectAdminsControls.disabled = true;
  for (const button of els.projectAdminsList.querySelectorAll("button")) button.disabled = true;
  els.projectAdminsStatus.classList.remove("text-danger");
  try {
    const result = await request(`project-administrators.php?app=${encodeURIComponent(app)}`, {
      method, ...(method === "GET" ? {} : { body: JSON.stringify({ userid }) })
    });
    if (projectAdminsApp !== app || !els.projectAdminsDialog.open) return;
    if (method === "DELETE" && userid === state.sessionUser?.userid) {
      els.projectAdminsDialog.close();
      await connect(true);
      return;
    }
    renderProjectAdmins(result.administrators);
    els.projectAdminsStatus.textContent = method === "GET"
      ? (result.administrators.length ? "" : t("admins.empty")) : t("admins.saved");
    if (method === "POST") els.projectAdminUserid.value = "";
  } catch (error) {
    if (projectAdminsApp === app) {
      els.projectAdminsStatus.textContent = error.message;
      els.projectAdminsStatus.classList.add("text-danger");
    }
  } finally {
    projectAdminsBusy = false;
    els.projectAdminsControls.disabled = false;
    for (const button of els.projectAdminsList.querySelectorAll("button")) button.disabled = false;
  }
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
  if (!confirm(t("projects.confirm_move_to_deleted", { name: project.project_name, key: project.app_key }))) return;
  try {
    await request(`projects.php?app=${encodeURIComponent(project.app_key)}`, { method: "DELETE" });
    state.projects = state.projects.filter(item => item.app_key !== project.app_key);
    if (state.currentApp === project.app_key) state.currentApp = state.projects[0]?.app_key || "";
    populateProjectSelect(); configureConsoleAccess(); showView("projects", true);
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
      populateProjectSelect(); configureConsoleAccess();
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
  els.appSelect.replaceChildren(...[currentProject()].filter(Boolean).map(project => new Option(
    `${project.project_name}${!isAdmin() && project.access_role ? ` (${projectRoleLabel(project.access_role)})` : ""}`,
    project.app_key
  )));
  els.appSelect.value = state.currentApp; els.appSelect.disabled = true;
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
    populateProjectSelect(); configureConsoleAccess(); els.projectDialog.close(); els.projectForm.reset();
    setStatus(`${project.project_name}${t("message.d7239b2491cf")}`); showView("columns", true);
  } catch (error) { setStatus(`${t("message.16cf5004f021")}${error.message}`, true); }
}

function orderedFields(schema = state.schema) {
  return Object.entries(schema?.fields || {}).sort((a, b) => (a[1].order ?? 0) - (b[1].order ?? 0));
}

function openColumns() {
  state.columnImportVersion++; state.pendingColumnImport = null; els.importColumnsButton.disabled = false;
  const project = currentProject(); if (!project) return;
  state.schema = structuredClone(project.schema || { fields: {} }); state.schemaDirty = false;
  els.columnsProjectKey.textContent = project.app_key; els.projectName.value = project.project_name;
  els.projectFrontendUrl.value = project.frontend_url || ""; els.projectFrontendPublic.checked = Boolean(project.frontend_public);
  els.projectManagementActions.replaceChildren();
  if (project.id !== null) {
    const usersLink = document.createElement("a"), usersUrl = new URL(location.href);
    usersUrl.searchParams.set("view", "users"); usersUrl.searchParams.set("app", project.app_key);
    usersLink.href = usersUrl.href; usersLink.className = "btn btn-outline-secondary activity-action-button";
    usersLink.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="8" r="3"/><path d="M3 21v-2a6 6 0 0 1 12 0v2M16 5a3 3 0 0 1 0 6M21 21v-2a6 6 0 0 0-4-5"/></svg>';
    const usersLabel = document.createElement("span"); usersLabel.textContent = t("navigation.users");
    usersLink.append(usersLabel);
    usersLink.addEventListener("click", event => {
      if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
      event.preventDefault(); showView("users");
    });
    els.projectManagementActions.append(usersLink);
  }
  if (canDeleteProject(project)) {
    const deleteButton = actionButton(t("projects.move_to_deleted"), () => deleteProject(project), "danger");
    deleteButton.className = "btn btn-outline-danger activity-action-button";
    const deleteLabel = document.createElement("span"); deleteLabel.textContent = t("projects.move_to_deleted");
    deleteButton.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M5 6l1 14h12l1-14M10 10v7M14 10v7"/></svg>';
    deleteButton.append(deleteLabel);
    els.projectManagementActions.append(deleteButton);
  }
  els.projectRecruiting.checked = Boolean(project.recruiting);
  const page = project.page_rendering || {};
  els.pageRenderingEnabled.checked = Boolean(page.enabled);
  els.pageRenderingPath.value = page.path_template || "/parks/{osm_type}/{osm_id}";
  els.pageRenderingTitle.value = page.title_template || "{name} | {project_name}";
  els.pageRenderingDescription.value = page.default_description || "";
  els.pageRenderingImage.value = page.default_image_url || "";
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
    originalType: type, originalOptions: structuredClone(definition.options || []), initialOptionsText: optionsText(definition.options),
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
  const fields = Object.create(null); const seen = new Set();
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
    const options = row.type === row.originalType && row.options === row.initialOptionsText ? structuredClone(row.originalOptions) : printable(row.options).split(/\r?\n/).map(item => item.trim()).filter(Boolean).map(item => {
      const split = item.indexOf("|");
      return split < 0 ? item : { value: item.slice(0, split).trim(), label: item.slice(split + 1).trim() };
    });
    if ((type === "select" || type === "checkbox") && options.length) definition.options = options;
    fields[field] = definition;
  });
  return { fields };
}

async function validateColumnSchema(schema, app) {
  const result = await request(`project-schema.php?app=${encodeURIComponent(app)}`, { method: "POST", body: JSON.stringify(schema) });
  // Normalise PHP's empty array representation for the editor and transfer format.
  if (Array.isArray(result.schema.fields) && result.schema.fields.length === 0) result.schema.fields = {};
  return result.schema;
}

async function exportColumns() {
  const app = state.currentApp;
  try {
    const schema = await validateColumnSchema(collectSchema(), app);
    const blob = new Blob([window.ColumnTransfer.stringify(schema)], { type: "application/json;charset=utf-8" });
    const url = URL.createObjectURL(blob), link = document.createElement("a");
    link.href = url; link.download = `${app}-columns.json`; link.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  } catch (error) { setStatus(error.message, true); }
}

async function previewColumnsImport() {
  const file = els.importColumnsFile.files[0]; if (!file) return;
  const app = state.currentApp, version = ++state.columnImportVersion;
  state.pendingColumnImport = null; els.importColumnsButton.disabled = true;
  try {
    if (file.size > 1048576) throw new Error(t("columns.file_too_large"));
    const candidate = window.ColumnTransfer.parse(await file.text());
    const schema = await validateColumnSchema(candidate, app);
    if (version !== state.columnImportVersion || state.view !== "columns" || state.currentApp !== app) return;
    const before = { fields: Object.fromEntries(state.columnsTable.getRows("active").map(row => [printable(row.getData().field).trim(), {}])) };
    const diff = window.ColumnTransfer.diff(before, schema);
    state.pendingColumnImport = { app, schema };
    els.importColumnsSummary.textContent = t("columns.import_summary", { file: file.name, count: diff.total, added: diff.added.length, removed: diff.removed.length });
    els.importColumnsChanges.textContent = t("columns.import_changes", { added: diff.added.join(", ") || "—", removed: diff.removed.join(", ") || "—" });
    els.importColumnsDialog.showModal();
  } catch (error) {
    if (version === state.columnImportVersion && state.view === "columns" && state.currentApp === app) {
      const systemField = error.message.match(/^columns\.system_schema_mismatch\.(id|osmid)$/)?.[1];
      setStatus(systemField ? t("columns.system_schema_mismatch", { field: systemField }) : error.message.startsWith("columns.") ? t(error.message) : error.message, true);
    }
  } finally {
    if (version === state.columnImportVersion) els.importColumnsButton.disabled = false;
    els.importColumnsFile.value = "";
  }
}

function applyColumnsImport() {
  const pending = state.pendingColumnImport;
  if (!pending || state.view !== "columns" || pending.app !== state.currentApp) return;
  state.schema = structuredClone(pending.schema); renderColumnsTable(); markSchemaDirty();
  els.importColumnsDialog.close(); state.pendingColumnImport = null;
  setStatus(t("columns.imported"));
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
      frontend_url: els.projectFrontendUrl.value, frontend_public: els.projectFrontendPublic.checked, recruiting: els.projectRecruiting.checked, schema,
      page_rendering: {
        enabled: els.pageRenderingEnabled.checked, path_template: els.pageRenderingPath.value,
        title_template: els.pageRenderingTitle.value, default_description: els.pageRenderingDescription.value,
        default_image_url: els.pageRenderingImage.value || null
      }
    }) });
    replaceProject(updated); state.schemaDirty = false; renderColumnsTable(); updateSchemaDirty(); populateProjectSelect();
    setStatus(t("message.56c66905dc25"));
  } catch (error) { setStatus(`${t("message.cef70d9b3017")}${error.message}`, true); }
}

function cancelActivityLoad() {
  state.activityLoadController?.abort();
  state.activityLoadController = null;
  state.activityLoading = false;
}

async function loadActivities(force = false) {
  state.csvImportVersion++; state.csvImporting = false; els.csvImportStatus.textContent = "";
  if (!force && hasActivityChanges() && !confirmDiscard()) return;
  cancelActivityLoad();
  const project = currentProject();
  if (!project) {
    state.activityTable?.destroy(); state.activityTable = null; state.rows = [];
    state.newActivityRows.clear(); state.deletedActivityRows.clear(); state.dirtyActivityFields.clear();
    setFrontendLink(els.activitiesFrontendLink, null); els.appSelect.replaceChildren();
    els.jsonExport.removeAttribute("href"); els.csvExport.removeAttribute("href");
    els.activityLoadStatus.textContent = ""; els.activityRetryButton.hidden = true;
    updateDirtyControls(); setStatus(t("message.3728e237b3ef"), true);
    return;
  }
  const app = state.currentApp;
  const controller = new AbortController();
  state.activityLoadController = controller; state.activityLoading = true; state.activityLoadFailed = false;
  state.schema = project.schema || { fields: {} }; els.search.value = "";
  state.rows = []; state.newActivityRows.clear(); state.deletedActivityRows.clear(); state.dirtyActivityFields.clear();
  els.activityRetryButton.hidden = true;
  els.activityLoadStatus.textContent = t("activities.loading", { count: 0 });
  setFrontendLink(els.activitiesFrontendLink, project.frontend_url); els.appSelect.value = app;
  updateExportLinks(); updateDirtyControls();
  let loaded = 0;
  try {
    await renderActivityTable();
    if (controller.signal.aborted) return;
    const table = state.activityTable;
    let cursor = null;
    do {
      const params = new URLSearchParams({ app, batch: "1", limit: "100" });
      if (cursor !== null) params.set("cursor", cursor);
      const result = await request(`activities.php?${params}`, { signal: controller.signal });
      if (controller.signal.aborted) return;
      if (!Array.isArray(result.items) || !(result.next_cursor === null ||
          (typeof result.next_cursor === "string" && /^[1-9][0-9]*$/.test(result.next_cursor))) ||
          (result.next_cursor !== null && (!result.items.length || (cursor !== null && BigInt(result.next_cursor) >= BigInt(cursor))))) {
        throw new Error(t("activities.invalid_batch"));
      }
      const rows = result.items.map(row => ({ ...row, cmmRowKey: `saved-${row.id}` }));
      await table.addData(rows, false);
      if (controller.signal.aborted) return;
      state.rows.push(...rows); loaded += rows.length;
      cursor = result.next_cursor;
      els.activityLoadStatus.textContent = t("activities.loading", { count: loaded.toLocaleString(document.documentElement.lang) });
      // Yield a frame so the first batch and progress can paint before continuing.
      await new Promise(resolve => requestAnimationFrame(resolve));
    } while (cursor !== null && !controller.signal.aborted);
    if (controller.signal.aborted) return;
    project.activity_count = loaded;
    els.activityLoadStatus.textContent = "";
    setStatus(`${loaded}${t("message.9b6015e28745")}${canWriteActivities(project) ? "" : t("message.998135a8a918")}`, false, false);
  } catch (error) {
    if (controller.signal.aborted) return;
    state.activityLoadFailed = true;
    els.activityLoadStatus.textContent = t("activities.load_failed", { count: loaded.toLocaleString(document.documentElement.lang) });
    els.activityRetryButton.hidden = false;
    setStatus(`${t("message.39a1228bde4e")}${error.message}`, true);
  } finally {
    if (state.activityLoadController === controller) {
      state.activityLoadController = null; state.activityLoading = false; updateDirtyControls();
    }
  }
}

function activityColumns() {
  const system = [
    { field: "id", label: t("message.66ac32497808"), type: "text", admin: { width: 210 } },
    { field: "osmid", label: "OSM ID", type: "text", required: true, admin: { width: 150 } },
    { field: "latitude", label: t("message.aee1a47ed194"), type: "number", admin: { width: 140, editable: false } },
    { field: "longitude", label: t("message.6b136ced4af4"), type: "number", admin: { width: 140, editable: false } },
    { field: "created_at", label: t("message.25293dc60993"), type: "text", admin: { width: 160, editable: false } },
    { field: "updated_at", label: t("message.504d4c11e0f5"), type: "text", admin: { width: 160, editable: false } }
  ];
  const dynamic = orderedFields().filter(([, def]) => def.admin?.visible !== false).map(([field, def]) => ({ field, ...def }));
  return [...system, ...dynamic];
}

function formatActivityClipboard(type, output) {
  if (type !== "plain") return output;
  const table = state.activityTable;
  // Use the same export selection as Tabulator, quoting spreadsheet TSV cells.
  const rows = table.modules.export.generateExportList(table.options.clipboardCopyConfig, false, "range", "clipboard");
  return rows.map(row => row.columns.map(column => {
    const value = column?.value;
    const text = value == null ? "" : typeof value === "object" ? JSON.stringify(value) : String(value);
    return /[\t\r\n"]/.test(text) ? `"${text.replaceAll('"', '""')}"` : text;
  }).join("\t")).join("\n");
}

function activityClipboardCells(text) {
  // Newlines/tabs inside quoted cells are values, not row/column separators.
  const rows = [], row = [];
  let value = "", quoted = false, closed = false;
  for (let i = 0; i < text.length; i++) {
    const char = text[i];
    if (quoted) {
      if (char === '"') {
        if (text[i + 1] === '"') { value += '"'; i++; }
        else { quoted = false; closed = true; }
      } else value += char;
    } else if (char === '"' && value === "" && !closed) {
      quoted = true;
    } else if (char === "\t" || char === "\n" || char === "\r") {
      row.push(value); value = ""; closed = false;
      if (char !== "\t") {
        rows.push(row.splice(0));
        if (char === "\r" && text[i + 1] === "\n") i++;
        if (i === text.length - 1) return rows;
      }
    } else {
      if (closed) return false;
      value += char;
    }
  }
  if (quoted) return false;
  row.push(value); rows.push(row);
  return rows;
}

function parseActivityClipboard(text) {
  const data = activityClipboardCells(text);
  const bounds = this.table.modules.selectRange.activeRange?.getBounds();
  if (!data || !bounds?.start) return false;
  const columns = this.table.columnManager.getVisibleColumnsByIndex();
  const start = columns.indexOf(bounds.start.column);
  if (start < 0) return false;
  // Preserve Tabulator's range width and repeating-cell paste behavior.
  const width = bounds.start === bounds.end ? data[0].length : columns.indexOf(bounds.end.column) - start + 1;
  const selected = columns.slice(start, start + width);
  return data.map(values => Object.fromEntries(selected.map((column, index) => [column.field, values[index % values.length]])));
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
    clipboardCopyFormatter: formatActivityClipboard,
    clipboardPasteParser: parseActivityClipboard,
    clipboardPasteAction: "range",
    rowHeader: { resizable: false, width: 42, hozAlign: "center", formatter: "rownum", cssClass: "range-header-col", editor: false, headerSort: false, headerFilter: false },
    columnDefaults: { resizable: "header", headerFilter: "input", headerFilterPlaceholder: t("message.cd46f1f0c21c") },
    columns: [
      { field: "cmmRowKey", visible: false, headerSort: false, headerFilter: false, clipboard: false, download: false },
      { title: t("message.f3ea6d345e2a"), width: 128, minWidth: 128, headerSort: false, headerFilter: false, clipboard: false, download: false, formatter: deleteActionFormatter, cellClick: deleteActionClick },
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
  const table = state.activityTable;
  return new Promise(resolve => table.on("tableBuilt", () => {
    if (state.activityTable === table) applyGlobalActivityFilter();
    resolve();
  }));
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
  button.textContent = deleted ? t("message.c7f325f13f16") : (newRow ? t("message.a328a2d80d00") : t("activities.delete"));
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
  // form_key is internal metadata, retained even though it is not a table column.
  const payload = { app: state.currentApp, form_key: row.form_key ?? "" };
  activityColumns().forEach(column => { if (!['created_at', 'updated_at', 'latitude', 'longitude'].includes(column.field)) payload[column.field] = normalizeActivityValue(column, row[column.field]); });
  return payload;
}

async function saveAll() {
  if (state.activityLoading || state.activityLoadFailed) return;
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
  const incomplete = state.activityLoading || state.activityLoadFailed;
  const bulkAllowed = !!currentProject() && canManageProject();
  els.deleteAllActivitiesButton.hidden = !bulkAllowed;
  els.deleteAllActivitiesButton.closest(".activity-danger-actions").hidden = !bulkAllowed;
  els.deleteAllActivitiesButton.disabled = !bulkAllowed || state.bulkDeleteLoading || state.bulkDeleteBusy || state.csvImporting || state.csvApplying || hasActivityChanges();
  els.deleteAllActivitiesButton.title = hasActivityChanges() ? t("bulk_activities.save_discard_first") : t("bulk_activities.scope");
  els.importFile.disabled = !writable || incomplete || state.csvImporting || state.csvApplying || state.bulkDeleteBusy;
  els.addButton.disabled = !writable || incomplete; els.saveAllButton.disabled = !writable || incomplete || count === 0; els.discardButton.disabled = !writable || count === 0; els.dirtyCount.textContent = count ? String(count) : "";
}

async function openBulkActivityDelete() {
  const project = currentProject();
  if (!project || !canManageProject() || hasActivityChanges() || state.csvImporting || state.csvApplying || state.bulkDeleteBusy) return;
  const app = project.app_key, version = ++state.bulkDeleteVersion;
  state.bulkDeleteTarget = null; state.bulkDeleteLoading = true;
  els.bulkActivityDeleteProject.textContent = `${project.project_name} (KEY: ${app})`;
  els.bulkActivityDeleteKey.value = ""; els.bulkActivityDeleteConfirm.disabled = true;
  els.bulkActivityDeleteRetry.hidden = true; els.bulkActivityDeleteCount.textContent = t("bulk_activities.loading");
  if (!els.bulkActivityDeleteDialog.open) els.bulkActivityDeleteDialog.showModal();
  updateDirtyControls();
  try {
    const result = await request(`activity-delete-all.php?app=${encodeURIComponent(app)}`);
    if (version !== state.bulkDeleteVersion || app !== state.currentApp || state.view !== "activities" || !els.bulkActivityDeleteDialog.open) return;
    if (result.app_key !== app || !Number.isSafeInteger(result.count) || result.count < 0 || typeof result.snapshot_max_id !== "string" || !/^(0|[1-9][0-9]*)$/.test(result.snapshot_max_id)) throw new Error(t("bulk_activities.preview_error"));
    state.bulkDeleteTarget = { ...result, app };
    els.bulkActivityDeleteProject.textContent = `${result.project_name} (KEY: ${app})`;
    els.bulkActivityDeleteCount.textContent = result.count ? t("bulk_activities.count", { count: result.count }) : t("bulk_activities.empty");
  } catch (error) {
    if (version === state.bulkDeleteVersion) { els.bulkActivityDeleteCount.textContent = error.message; els.bulkActivityDeleteRetry.hidden = false; }
  } finally {
    if (version === state.bulkDeleteVersion) { state.bulkDeleteLoading = false; updateDirtyControls(); }
  }
}

async function submitBulkActivityDelete(event) {
  event.preventDefault();
  const target = state.bulkDeleteTarget;
  if (!target || target.app !== state.currentApp || state.view !== "activities" || !canManageProject() || state.bulkDeleteBusy || hasActivityChanges() || !target.count || els.bulkActivityDeleteKey.value !== target.app) return;
  state.bulkDeleteBusy = true;
  [els.bulkActivityDeleteConfirm, els.bulkActivityDeleteCancel, els.bulkActivityDeleteClose, els.bulkActivityDeleteKey, els.bulkActivityDeleteRetry].forEach(control => { control.disabled = true; });
  cancelActivityLoad(); updateDirtyControls();
  try {
    const result = await request("activity-delete-all.php", { method: "POST", body: JSON.stringify({ app: target.app, confirm_app_key: els.bulkActivityDeleteKey.value, snapshot_max_id: target.snapshot_max_id, expected_count: target.count }) });
    if (!Number.isSafeInteger(result.deleted_count) || result.deleted_count !== target.count || !Number.isSafeInteger(result.remaining_count) || result.remaining_count < 0) throw new Error(t("bulk_activities.result_error"));
    els.bulkActivityDeleteDialog.close(); state.bulkDeleteTarget = null;
    if (state.currentApp === target.app && state.view === "activities") {
      const project = currentProject(); if (project) project.activity_count = result.remaining_count;
      await loadActivities(true);
      setStatus(t("bulk_activities.success", { count: result.deleted_count }) + (result.remaining_count ? "\n" + t("bulk_activities.remaining", { count: result.remaining_count }) : ""));
    }
  } catch (error) {
    state.bulkDeleteTarget = null; els.bulkActivityDeleteKey.value = "";
    els.bulkActivityDeleteCount.textContent = error.message; els.bulkActivityDeleteRetry.hidden = false;
    setStatus(error.message, true);
  } finally {
    state.bulkDeleteBusy = false;
    [els.bulkActivityDeleteCancel, els.bulkActivityDeleteClose, els.bulkActivityDeleteKey, els.bulkActivityDeleteRetry].forEach(control => { control.disabled = false; });
    els.bulkActivityDeleteConfirm.disabled = true; updateDirtyControls();
  }
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
  if (state.activityLoading || state.activityLoadFailed) return;
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

function csvCoordinateSummary(lookup) {
  if (!lookup) return "";
  const lines = [];
  if (lookup.filled) lines.push(t("coordinates.filled", { count: lookup.filled }));
  if (lookup.preserved) lines.push(t("coordinates.preserved", { count: lookup.preserved }));
  if (lookup.unresolved?.length) {
    lines.push(t("coordinates.unresolved", { count: lookup.unresolved.length }));
    lines.push(...lookup.unresolved.slice(0, 10).map(row => t("coordinates.unresolved_row", { line: row.line, osmid: row.osmid })));
    if (lookup.unresolved.length > 10) lines.push(t("coordinates.more", { count: lookup.unresolved.length - 10 }));
  }
  return lines.join("\n");
}

async function previewCsv(csv) {
  if (state.csvApplying) return;
  const app = state.currentApp, version = ++state.csvImportVersion;
  state.csvImporting = true; state.pendingCsv = null;
  els.csvImportStatus.textContent = t("coordinates.loading"); updateDirtyControls();
  try {
    const preview = await request("activity-import-csv.php", { method: "POST", body: JSON.stringify({ app, csv, dry_run: true }) });
    if (version !== state.csvImportVersion || state.currentApp !== app || state.view !== "activities") return;
    state.pendingCsv = { csv, preview, app }; els.csvSummary.textContent = `${preview.valid}${t("message.44ca537d5ac0")}${preview.created}${t("message.b3a91fbe7826")}${preview.updated}）`;
    const updateFields = preview.schema_update_fields || preview.missing_fields || [];
    const details = [];
    const coordinates = csvCoordinateSummary(preview.coordinate_lookup);
    els.csvImportStatus.textContent = coordinates;
    if (coordinates) details.push(coordinates);
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
  } catch (error) {
    if (version !== state.csvImportVersion || state.currentApp !== app || state.view !== "activities") return;
    els.csvImportStatus.textContent = `${t("message.b4381a45f284")}${error.message}`;
    setStatus(els.csvImportStatus.textContent, true); els.importFile.value = "";
  } finally {
    if (version === state.csvImportVersion) { state.csvImporting = false; updateDirtyControls(); }
  }
}

async function applyCsv() {
  const pending = state.pendingCsv;
  if (!pending || state.csvApplying || pending.app !== state.currentApp || state.view !== "activities") return;
  const submit = els.csvForm.querySelector('button[value="import"]');
  state.csvApplying = true; submit.disabled = true; updateDirtyControls();
  try {
    const updateFields = pending.preview.schema_update_fields || pending.preview.missing_fields || [];
    if (els.addMissingColumns.checked && updateFields.length) {
      const project = currentProject(); const fields = structuredClone(project.schema?.fields || {});
      updateFields.forEach(field => { fields[field] = pending.preview.schema_candidate.fields[field]; });
      const updated = await request(`projects.php?app=${encodeURIComponent(pending.app)}`, { method: "PUT", body: JSON.stringify({ schema: { fields } }) });
      replaceProject(updated);
    }
    const result = await request("activity-import-csv.php", { method: "POST", body: JSON.stringify({ app: pending.app, csv: pending.csv, dry_run: false }) });
    state.pendingCsv = null; els.csvDialog.close(); els.importFile.value = "";
    if (state.currentApp === pending.app && state.view === "activities") {
      await loadActivities(true);
      els.csvImportStatus.textContent = csvCoordinateSummary(result.coordinate_lookup);
      setStatus(`${pending.preview.valid}${t("message.f899fc5cca61")}\n${els.csvImportStatus.textContent}`);
    }
  } catch (error) { setStatus(`${t("message.6ccd0903edcc")}${error.message}`, true); }
  finally { state.csvApplying = false; submit.disabled = false; updateDirtyControls(); }
}

function configureUsersView() {
  const scoped = isProjectUsers();
  state.users = []; state.userPagination = null; els.usersBody.replaceChildren();
  state.deletedUserLoadVersion++; state.deletedUsers = []; state.deletedUsersLoaded = false; state.deletedUserPage = 1;
  els.deletedUsersBody.replaceChildren(); els.deletedUsersSection.open = false; els.deletedUsersSection.hidden = scoped;
  els.deletedUsersStatus.textContent = ""; els.deletedUsersRetryButton.hidden = true;
  document.getElementById("usersTitle").textContent = scoped ? t("navigation.users") : t("users.all");
  document.getElementById("usersDescription").textContent = scoped ? t("users.scoped_description", { project: currentProject().project_name }) : t("users.global_description");
  document.getElementById("usersSearchLabel").textContent = scoped ? t("message.c30499da17fc") : t("users.identity_search");
  [els.userStatusFilter, els.userVerifiedFilter, els.userRoleFilter, els.userProjectFilter].forEach(control => { control.closest("label").hidden = scoped; control.value = ""; });
  els.usersSearch.value = "";
  els.newUserButton.hidden = scoped;
  const head = document.querySelector("#usersView thead tr");
  if (!head.dataset.globalHeaders) head.dataset.globalHeaders = head.innerHTML;
  if (scoped) {
    head.replaceChildren(...[t("message.c30499da17fc"), t("users.project_access"), t("message.f3ea6d345e2a")].map(text => { const th = document.createElement("th"); th.textContent = text; return th; }));
  } else head.innerHTML = head.dataset.globalHeaders;
}

async function loadUsers(resetPage = false) {
  const version = ++state.userLoadVersion, view = state.view, app = state.currentApp;
  state.deletedUserLoadVersion++; state.deletedUsersLoaded = false;
  if (resetPage) { state.userPage = 1; state.deletedUserPage = 1; }
  const params = new URLSearchParams({ page: String(state.userPage), per_page: "25" });
  if (els.usersSearch.value.trim()) params.set("search", els.usersSearch.value.trim());
  if (els.userStatusFilter.value) params.set("status", els.userStatusFilter.value);
  if (els.userVerifiedFilter.value) params.set("verified", els.userVerifiedFilter.value);
  if (els.userRoleFilter.value) params.set("role", els.userRoleFilter.value);
  if (els.userProjectFilter.value) params.set("project_id", els.userProjectFilter.value);
  setStatus(t("message.9ac20e559949"), false, false);
  try {
    if (isProjectUsers()) params.set("app", state.currentApp);
    const result = await request(`${isProjectUsers() ? "project-users.php" : "admin-users.php"}?${params}`);
    if (version !== state.userLoadVersion || view !== state.view || app !== state.currentApp) return;
    if (state.userPage > result.pagination.total_pages) { state.userPage = result.pagination.total_pages; return loadUsers(); }
    state.users = result.items; state.userPagination = result.pagination; renderUsers();
    if (view === "all-users" && els.deletedUsersSection.open) loadDeletedUsers();
    setStatus(`${result.pagination.total}${t("message.f0450f797116")}`, false, false);
  } catch (error) { if (version === state.userLoadVersion && view === state.view && app === state.currentApp) setStatus(`${t("message.1e9b249c15c2")}${error.message}`, true); }
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
  if (isProjectUsers()) {
    renderProjectUsers();
  } else els.usersBody.replaceChildren(...state.users.map(renderGlobalUserRow));
  if (!state.users.length) {
    const tr = document.createElement("tr"); tr.className = "users-empty-row"; const td = document.createElement("td"); td.colSpan = isProjectUsers() ? 3 : 8; td.textContent = t("message.4d6206aa7961"); tr.append(td); els.usersBody.append(tr);
  }
  const pagination = state.userPagination || { page: 1, total_pages: 1, total: 0 };
  els.usersPageInfo.textContent = `${pagination.page} / ${pagination.total_pages}${t("message.1936d001a9c3")}${pagination.total}${t("message.5b2d4f518226")}`;
  els.usersPrevButton.disabled = pagination.page <= 1;
  els.usersNextButton.disabled = pagination.page >= pagination.total_pages;
}

function renderGlobalUserRow(user) {
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
    if (user.status !== "deleted") tr.querySelector(".user-name-actions").append(resetButton);
    tr.lastElementChild.append(actionButton(t("message.12d470debc6c"), () => openUser(user.id)));
    if (user.status === "deleted") {
      const actions = document.createElement("div"); actions.className = "deleted-user-actions";
      const restore = actionButton(t("users.restore"), () => openDeletedUserAction(user, "restore"));
      const purge = actionButton(t("users.purge"), () => openDeletedUserAction(user, "purge"), "danger");
      actions.append(restore, purge); tr.lastElementChild.append(actions);
    }
    return tr;
}

async function loadDeletedUsers() {
  if (state.view !== "all-users" || !isAdmin() || !els.deletedUsersSection.open) return;
  const version = ++state.deletedUserLoadVersion;
  const params = new URLSearchParams({ page: String(state.deletedUserPage), per_page: "25", status: "deleted" });
  for (const [key, control] of [["search",els.usersSearch],["verified",els.userVerifiedFilter],["role",els.userRoleFilter],["project_id",els.userProjectFilter]]) {
    if (control.value.trim()) params.set(key, control.value.trim());
  }
  els.deletedUsersStatus.textContent = t("users.deleted_loading");
  els.deletedUsersRetryButton.hidden = true;
  els.deletedUsersPrevButton.disabled = true; els.deletedUsersNextButton.disabled = true;
  try {
    const result = await request(`admin-users.php?${params}`);
    if (version !== state.deletedUserLoadVersion || state.view !== "all-users") return;
    if (state.deletedUserPage > result.pagination.total_pages) {
      state.deletedUserPage = result.pagination.total_pages; return loadDeletedUsers();
    }
    state.deletedUsers = result.items; state.deletedUserPagination = result.pagination; state.deletedUsersLoaded = true;
    els.deletedUsersBody.replaceChildren(...state.deletedUsers.map(renderGlobalUserRow));
    if (!state.deletedUsers.length) {
      const row = document.createElement("tr"), cell = document.createElement("td");
      row.className = "users-empty-row"; cell.colSpan = 8; cell.textContent = t("users.deleted_empty"); row.append(cell); els.deletedUsersBody.append(row);
    }
    const page = result.pagination;
    els.deletedUsersPageInfo.textContent = `${page.page} / ${page.total_pages}${t("message.1936d001a9c3")}${page.total}${t("message.5b2d4f518226")}`;
    els.deletedUsersPrevButton.disabled = page.page <= 1;
    els.deletedUsersNextButton.disabled = page.page >= page.total_pages;
    els.deletedUsersStatus.textContent = "";
  } catch (error) {
    if (version !== state.deletedUserLoadVersion || state.view !== "all-users") return;
    state.deletedUsersLoaded = false; els.deletedUsersBody.replaceChildren();
    els.deletedUsersStatus.textContent = error.message; els.deletedUsersRetryButton.hidden = false;
  }
}

function openDeletedUserAction(user, action) {
  if (state.view !== "all-users" || !isAdmin() || user.status !== "deleted" || state.deletedUserBusy) return;
  state.deletedUserTarget = { user, action };
  els.deletedUserActionTitle.textContent = t(action === "purge" ? "users.purge" : "users.restore");
  els.deletedUserActionTarget.textContent = user.userid;
  els.deletedUserActionDescription.textContent = t(action === "purge" ? "users.purge_description" : "users.restore_description");
  els.deletedUserActionConfirmation.value = ""; els.deletedUserActionSubmit.disabled = true;
  els.deletedUserActionSubmit.textContent = t(action === "purge" ? "users.purge" : "users.restore");
  els.deletedUserActionSubmit.className = action === "purge" ? "btn btn-danger" : "btn btn-success";
  els.deletedUserActionDialog.showModal();
}

async function submitDeletedUserAction(event) {
  event.preventDefault();
  const target = state.deletedUserTarget;
  if (!target || state.deletedUserBusy || state.view !== "all-users" || !isAdmin() || els.deletedUserActionConfirmation.value !== target.user.userid) return;
  state.deletedUserBusy = true; els.deletedUserActionSubmit.disabled = true;
  try {
    const result = await request("admin-users.php", { method: "POST", body: JSON.stringify({ action: target.action, id: target.user.id, confirm_userid: els.deletedUserActionConfirmation.value }) });
    if (result[target.action === "purge" ? "user_purged" : "user_restored"] !== true) throw new Error(t("users.lifecycle_failed"));
    if (state.deletedUserTarget === target) { els.deletedUserActionDialog.close(); state.deletedUserTarget = null; }
    if (state.currentUser?.id === target.user.id) { els.userDialog.close(); state.currentUser = null; }
    if (state.view === "all-users") {
      await loadUsers();
      setStatus(t(target.action === "purge" ? "users.purged_success" : "users.restored_success", { user: target.user.userid }));
    }
  } catch (error) { setStatus(error.message, true); }
  finally {
    state.deletedUserBusy = false;
    els.deletedUserActionSubmit.disabled = !state.deletedUserTarget || els.deletedUserActionConfirmation.value !== state.deletedUserTarget.user.userid;
  }
}

function renderProjectUsers() {
  els.usersBody.replaceChildren(...state.users.map(user => {
    const row = document.createElement("tr");
    const name = document.createElement("td"); name.textContent = user.userid; name.dataset.label = t("message.c30499da17fc");
    const access = document.createElement("td"); access.dataset.label = t("users.project_access");
    const role = document.createElement("select"); role.className = "form-select"; role.setAttribute("aria-label", `${user.userid}: ${t("users.project_access")}`);
    ["viewer", "contributor", "editor", "project_admin", "banned"].forEach(value => role.append(new Option(projectRoleLabel(value), value)));
    role.append(new Option(t("users.remove"), "")); role.value = user.project_role;
    const actions = document.createElement("td"); actions.dataset.label = t("message.f3ea6d345e2a");
    const save = actionButton(t("users.save"), async () => {
      const value = role.value || null;
      if (value === user.project_role) return;
      save.disabled = true;
      try {
        await request("project-users.php", { method: "PUT", body: JSON.stringify({ app: state.currentApp, id: user.id, role: value }) });
        await loadUsers(); setStatus(t("users.saved"));
      } catch (error) { setStatus(error.message, true); }
      finally { save.disabled = false; }
    });
    role.disabled = user.is_global_admin; save.disabled = user.is_global_admin;
    access.append(role); actions.append(save);
    if (user.is_global_admin) { const note = document.createElement("small"); note.textContent = t("users.global_admin"); actions.append(note); }
    row.append(name, access, actions); return row;
  }));
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
    ["viewer", "contributor", "editor", "project_admin"].forEach(value => role.append(new Option(projectRoleLabel(value), value)));
    if (container === els.editUserProjects) role.append(new Option(t("users.banned"), "banned"));
    role.setAttribute("aria-label", `${project.project_name}: ${t("users.project_access")}`);
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

function restoreDeletedUserControls() {
  els.userForm.querySelectorAll("[data-deletion-disabled]").forEach(control => {
    control.disabled = control.dataset.deletionDisabled === "true"; delete control.dataset.deletionDisabled;
  });
}

function configureUserDeletion(user) {
  const deleted = user.status === "deleted";
  els.deletedUserNotice.hidden = !deleted;
  els.deleteUserConfirmation.value = ""; els.deleteUserButton.disabled = true;
  els.deleteUserSection.hidden = state.view !== "all-users" || !isAdmin() || deleted || user.id === state.sessionUser?.id;
  if (deleted) {
    els.userAddEmailSection.hidden = true; els.userMailActions.hidden = true;
    els.userForm.querySelectorAll("input, select, button:not(.dialog-close)").forEach(control => {
      control.dataset.deletionDisabled = String(control.disabled); control.disabled = true;
    });
  }
}

async function softDeleteUser() {
  const user = state.currentUser;
  if (!user || state.view !== "all-users" || !isAdmin() || user.status === "deleted" || user.id === state.sessionUser?.id || els.deleteUserConfirmation.value !== user.userid) return;
  els.deleteUserButton.disabled = true;
  try {
    const result = await request("admin-users.php", { method: "POST", body: JSON.stringify({ action: "soft_delete", id: user.id, confirm_userid: els.deleteUserConfirmation.value }) });
    if (result.user_deleted !== true) throw new Error(t("users.delete_failed"));
    if (state.currentUser?.id === user.id) { els.userDialog.close(); state.currentUser = null; }
    if (state.view === "all-users") await loadUsers(true);
    setStatus(t("users.deleted_success", { user: user.userid }));
  } catch (error) { setStatus(error.message, true); }
  finally { els.deleteUserButton.disabled = els.deleteUserConfirmation.value !== state.currentUser?.userid; }
}

async function openUser(id) {
  if (!isAdmin() || state.view !== "all-users") return;
  try {
    const user = await request(`admin-users.php?id=${encodeURIComponent(id)}`); restoreDeletedUserControls(); state.currentUser = user;
    els.userDialogTitle.textContent = `${t("message.d1e5f8a1131f")}${user.userid}`;
    renderUserMetadata(user);
    els.editUserStatus.value = user.status; els.editUserRole.value = user.role;
    renderProjectAssignments(els.editUserProjects, user.projects);
    configureUserEmail(user);
    els.addUserEmail.value = ""; els.userEmailStatus.textContent = ""; els.userEmailStatus.classList.remove("error");
    configureUserDeletion(user);
    els.userDialog.showModal();
  } catch (error) { setStatus(`${t("message.7bb7ae898ea3")}${error.message}`, true); }
}

async function openPasswordReset(id) {
  try {
    const user = await request(`admin-users.php?id=${encodeURIComponent(id)}`);
    if (user.status === "deleted") { setStatus(t("users.deleted_readonly"), true); return; }
    state.passwordUser = user;
    els.passwordResetUserid.textContent = user.userid;
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
[document.getElementById("headerMyPageLink"), els.currentAdmin].forEach(link => link.addEventListener("click", event => {
  if (!state.sessionUser) return;
  event.preventDefault();
  if (confirmDiscard()) location.assign(SiteLanguage.url(new URL("../mypage.html", document.baseURI)));
}));
document.getElementById("headerAllUsersLink").addEventListener("click", event => {
  if (!state.sessionUser) return;
  event.preventDefault(); showView("all-users");
});
els.logoutButton.addEventListener("click", logout);
document.querySelectorAll("[data-view]").forEach(link => link.addEventListener("click", event => {
  event.preventDefault(); showView(link.dataset.view);
}));
document.getElementById("headerProjectListLink").addEventListener("click", event => {
  if (!state.sessionUser) return;
  event.preventDefault(); showView("projects");
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
els.addColumnButton.addEventListener("click", async () => {
  if (!state.columnsTable) return;
  const row = await state.columnsTable.addRow(columnDefinitionRow(), false);
  markSchemaDirty();
  await row.scrollTo(); row.getCell("field")?.edit();
});
els.deleteAllActivitiesButton.addEventListener("click", openBulkActivityDelete);
els.bulkActivityDeleteRetry.addEventListener("click", openBulkActivityDelete);
els.bulkActivityDeleteForm.addEventListener("submit", submitBulkActivityDelete);
els.bulkActivityDeleteKey.addEventListener("input", () => {
  const target = state.bulkDeleteTarget;
  els.bulkActivityDeleteConfirm.disabled = state.bulkDeleteLoading || state.bulkDeleteBusy || !target?.count || target.app !== state.currentApp || els.bulkActivityDeleteKey.value !== target.app;
});
els.bulkActivityDeleteDialog.addEventListener("cancel", event => { if (state.bulkDeleteBusy) event.preventDefault(); });
els.bulkActivityDeleteDialog.addEventListener("close", () => {
  state.bulkDeleteVersion++; state.bulkDeleteTarget = null; state.bulkDeleteLoading = false; updateDirtyControls();
});
els.exportColumnsButton.addEventListener("click", exportColumns);
els.importColumnsButton.addEventListener("click", () => els.importColumnsFile.click());
els.importColumnsFile.addEventListener("change", previewColumnsImport);
els.applyColumnsImportButton.addEventListener("click", applyColumnsImport);
els.importColumnsDialog.addEventListener("close", () => { state.pendingColumnImport = null; });
els.saveColumnsButton.addEventListener("click", saveColumns);
els.projectName.addEventListener("input", markSchemaDirty); els.projectFrontendUrl.addEventListener("input", markSchemaDirty);
els.projectFrontendPublic.addEventListener("change", markSchemaDirty);
els.projectRecruiting.addEventListener("change", markSchemaDirty);
els.addButton.addEventListener("click", addActivityRow);
els.discardButton.addEventListener("click", () => loadActivities()); els.saveAllButton.addEventListener("click", saveAll);
els.search.addEventListener("input", applyGlobalActivityFilter);
els.appSelect.addEventListener("change", () => { if (!confirmDiscard()) { els.appSelect.value = state.currentApp; return; } state.currentApp = els.appSelect.value; state.rows = []; showView("activities", true); });
els.importFile.addEventListener("change", () => els.importFile.files[0] && importFile(els.importFile.files[0]));
els.csvForm.addEventListener("submit", event => { event.preventDefault(); if (event.submitter?.value === "import") applyCsv(); });
els.deletedUsersSection.addEventListener("toggle", () => {
  if (els.deletedUsersSection.open && !state.deletedUsersLoaded) loadDeletedUsers();
});
els.deletedUsersRetryButton.addEventListener("click", loadDeletedUsers);
els.deletedUsersPrevButton.addEventListener("click", () => { if (state.deletedUserPage > 1) { state.deletedUserPage--; loadDeletedUsers(); } });
els.deletedUsersNextButton.addEventListener("click", () => { if (state.deletedUserPage < state.deletedUserPagination?.total_pages) { state.deletedUserPage++; loadDeletedUsers(); } });
els.deletedUserActionConfirmation.addEventListener("input", () => {
  els.deletedUserActionSubmit.disabled = state.deletedUserBusy || els.deletedUserActionConfirmation.value !== state.deletedUserTarget?.user.userid;
});
els.deletedUserActionForm.addEventListener("submit", submitDeletedUserAction);
els.usersLoadButton.addEventListener("click", () => loadUsers(true));
els.usersSearch.addEventListener("keydown", event => { if (event.key === "Enter") { event.preventDefault(); loadUsers(true); } });
els.usersPrevButton.addEventListener("click", () => { state.userPage--; loadUsers(); });
els.usersNextButton.addEventListener("click", () => { state.userPage++; loadUsers(); });
els.newUserButton.addEventListener("click", () => { els.newUserForm.reset(); updateNewUserMode(); renderProjectAssignments(els.newUserProjects); els.newUserDialog.showModal(); });
els.newUserEmail.addEventListener("input", updateNewUserMode);
els.newUserPasswordConfirmation.addEventListener("input", () => els.newUserPasswordConfirmation.setCustomValidity(""));
els.newUserForm.addEventListener("submit", event => { event.preventDefault(); if (event.submitter?.value === "create") createUser(); });
els.deleteUserConfirmation.addEventListener("input", () => {
  els.deleteUserButton.disabled = els.deleteUserConfirmation.value !== state.currentUser?.userid;
});
els.deleteUserButton.addEventListener("click", softDeleteUser);
els.userForm.addEventListener("submit", event => { event.preventDefault(); if (event.submitter?.value === "save") saveUser(); });
els.addUserEmailButton.addEventListener("click", addUserEmail);
els.resendVerificationButton.addEventListener("click", () => userMailAction("resend_verification", state.currentUser));
els.userPasswordResetButton.addEventListener("click", async () => {
  if (!state.currentUser) return;
  els.userPasswordResetButton.disabled = true;
  try { await openPasswordReset(state.currentUser.id); }
  finally { els.userPasswordResetButton.disabled = false; }
});
els.passwordResetDialog.addEventListener("close", () => {
  if (els.userDialog.open && !els.passwordResetDialog.open) {
    els.userPasswordResetButton.focus();
  }
});
els.sendPasswordResetButton.addEventListener("click", () => userMailAction("send_password_reset", state.passwordUser));
els.setUserPasswordButton.addEventListener("click", setUserPassword);
els.editUserPassword.addEventListener("input", () => els.editUserPassword.setCustomValidity(""));
els.editUserPasswordConfirmation.addEventListener("input", () => els.editUserPasswordConfirmation.setCustomValidity(""));
window.addEventListener("beforeunload", event => { if (hasUnsavedChanges()) { event.preventDefault(); event.returnValue = ""; } });

els.projectAdminsForm.addEventListener("submit", event => {
  event.preventDefault();
  if (!projectAdminsBusy && els.projectAdminsForm.reportValidity()) updateProjectAdmins("POST", els.projectAdminUserid.value.trim());
});

els.activityRetryButton.addEventListener("click", () => loadActivities());

window.addEventListener("site-language-before-change", event => { if (!confirmDiscard()) event.preventDefault(); });

for (const control of [els.pageRenderingEnabled, els.pageRenderingPath, els.pageRenderingTitle, els.pageRenderingDescription, els.pageRenderingImage]) {
  control.addEventListener(control.type === "checkbox" ? "change" : "input", markSchemaDirty);
}
