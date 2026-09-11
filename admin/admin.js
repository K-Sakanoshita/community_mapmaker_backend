"use strict";

window.addEventListener('DOMContentLoaded', () => connect(true));

const apiRoot = "../api";
const fieldTypes = ["text", "textarea", "number", "date", "select", "checkbox", "url", "wikimedia"];
const optionFieldTypes = new Set(["select", "checkbox"]);
const state = {
  projects: [], currentApp: new URLSearchParams(location.search).get("app") || "",
  view: new URLSearchParams(location.search).get("view") || "projects",
  sessionUser: null,
  schema: { fields: {} }, rows: [], columnsTable: null, activityTable: null, nextColumnKey: 1, nextRowKey: 1,
  newActivityRows: new Set(), deletedActivityRows: new Set(), dirtyActivityFields: new Map(),
  schemaDirty: false, pendingCsv: null, users: [], userPage: 1, userPagination: null, currentUser: null
};
const ids = [
  "loginScreen", "loginForm", "loginStatus", "userid", "password", "connectButton", "adminHeader", "adminMain", "currentAdmin", "logoutButton",
  "status", "projectsGrid", "newProjectButton",
  "columnsProjectKey", "projectName", "projectEnabled", "columnsTable", "addColumnButton", "saveColumnsButton",
  "activitiesProjectKey", "appSelect", "search", "loadButton", "addButton", "discardButton", "saveAllButton", "dirtyCount",
  "jsonExport", "csvExport", "importFile", "activityTable", "projectDialog", "projectForm",
  "newProjectName", "newAppKey", "csvDialog", "csvForm", "csvSummary", "csvMissing", "addMissingLabel", "addMissingColumns",
  "newUserButton", "usersSearch", "userStatusFilter", "userVerifiedFilter", "userRoleFilter", "userProjectFilter", "usersLoadButton",
  "usersBody", "usersPrevButton", "usersNextButton", "usersPageInfo", "newUserDialog", "newUserForm", "newUserId", "newUserEmail",
  "newUserModeHint", "newUserPasswordFields", "newUserPassword", "newUserPasswordConfirmation", "newUserSubmitButton", "newUserRole", "newUserProjects",
  "userDialog", "userForm", "userDialogTitle", "userMetadata", "editUserStatus", "editUserRole",
  "editUserProjects", "resendVerificationButton", "sendPasswordResetButton", "editUserPassword", "editUserPasswordConfirmation",
  "setUserPasswordButton", "userPasswordStatus", "userRecentActivities"
];
const els = Object.fromEntries(ids.map(id => [id, document.getElementById(id)]));

function setStatus(message, error = false) {
  els.status.textContent = message;
  els.status.classList.toggle("error", error);
  els.status.classList.toggle("success", !error && !/中|してください/.test(message));
}

function setLoginStatus(message, error = false) {
  els.loginStatus.textContent = message;
  els.loginStatus.classList.toggle("error", error);
  els.loginStatus.classList.toggle("success", !error && !/中|してください/.test(message));
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
  catch (error) { setStatus(`ログアウトできません: ${error.message}`, true); return; }
  state.columnsTable?.destroy(); state.columnsTable = null;
  state.activityTable?.destroy(); state.activityTable = null;
  state.projects = []; state.rows = []; state.users = []; state.currentUser = null; state.sessionUser = null;
  state.schema = { fields: {} }; state.schemaDirty = false;
  state.newActivityRows.clear(); state.deletedActivityRows.clear(); state.dirtyActivityFields.clear();
  document.querySelectorAll("dialog[open]").forEach(dialog => dialog.close());
  document.body.classList.remove("activity-spreadsheet-view");
  els.adminHeader.hidden = true; els.adminMain.hidden = true; els.loginScreen.hidden = false;
  els.currentAdmin.textContent = ""; els.password.value = ""; els.status.textContent = "";
  document.querySelectorAll('[data-view="columns"], [data-view="activities"], [data-view="users"]').forEach(button => { button.disabled = true; });
  els.newProjectButton.disabled = true; els.newUserButton.disabled = true;
  setLoginStatus("ログアウトしました。再度ログインしてください。");
  (els.userid.value ? els.password : els.userid).focus();
}

function credentials() {
  return `Basic ${btoa(unescape(encodeURIComponent(`${els.userid.value}:${els.password.value}`)))}`;
}

async function request(path, options = {}) {
  const headers = { Accept: "application/json", ...(options.headers || {}) };
  if (options.body) headers["Content-Type"] = "application/json";
  headers['X-Console-Session'] = '1';
  if (options.basic) headers.Authorization = credentials();
  const response = await fetch(`${apiRoot}/${path}`, { ...options, headers });
  const payload = await response.json().catch(() => ({ code: "invalid_response" }));
  if (!response.ok) {
    const detail = payload.errors ? `: ${JSON.stringify(payload.errors)}` : "";
    throw new Error(`${payload.code || `HTTP ${response.status}`}${detail}`);
  }
  return payload;
}

function currentProject() {
  return state.projects.find(project => project.app_key === state.currentApp) || null;
}

function isAdmin() { return state.sessionUser?.role === "admin"; }

function canWriteActivities(project = currentProject()) {
  return isAdmin() || ["editor", "project_admin"].includes(project?.access_role);
}

function configureConsoleAccess() {
  const admin = isAdmin();
  document.querySelectorAll('[data-view="projects"], [data-view="columns"], [data-view="users"]').forEach(button => {
    button.hidden = !admin;
    button.disabled = !admin || ((button.dataset.view === "columns") && !state.currentApp);
  });
  const activitiesButton = document.querySelector('[data-view="activities"]');
  activitiesButton.hidden = false; activitiesButton.disabled = admin && !state.currentApp;
  els.newProjectButton.disabled = !admin; els.newUserButton.disabled = !admin;
  els.importFile.closest("label").hidden = !admin;
}

function hasActivityChanges() {
  return state.newActivityRows.size > 0 || state.deletedActivityRows.size > 0 || state.dirtyActivityFields.size > 0;
}

function hasUnsavedChanges() {
  return state.schemaDirty || hasActivityChanges();
}

function confirmDiscard() {
  return !hasUnsavedChanges() || confirm("未保存の変更があります。破棄して移動しますか？");
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
  if (!isAdmin() && view !== "activities") view = "activities";
  if (view === "columns" && !currentProject()) view = "projects";
  if (view === "activities" && !currentProject() && isAdmin()) view = "projects";
  state.view = view;
  document.body.classList.toggle("activity-spreadsheet-view", view === "activities");
  document.querySelectorAll(".view").forEach(section => { section.hidden = section.id !== `${view}View`; });
  document.querySelectorAll("[data-view]").forEach(button => button.classList.toggle("active", button.dataset.view === view));
  updateUrl();
  if (view === "columns") openColumns();
  if (view === "activities") loadActivities();
  if (view === "users") loadUsers();
}

async function connect(restore = false) {
  if (!restore && (!els.userid.value || !els.password.value)) {
    setLoginStatus("ユーザーIDとパスワードを入力してください。", true);
    return;
  }
  els.connectButton.disabled = true;
  setLoginStatus("認証中…");
  try {
    const session = await request("console-session.php", restore ? {} : { method: 'POST', basic: true });
    els.password.value = '';
    state.sessionUser = session.user; state.projects = session.projects;
    if (!currentProject()) state.currentApp = state.projects[0]?.app_key || "";
    configureConsoleAccess();
    if (isAdmin()) renderProjects();
    populateProjectSelect();
    showAdminConsole();
    setStatus(`${state.projects.length}件のProjectを読み込みました。`);
    if (!isAdmin()) state.view = "activities";
    showView(state.view, true);
  } catch (error) {
    setLoginStatus(restore ? 'ユーザーIDとパスワードを入力してください。' : `ログインできません: ${error.message}`, !restore);
    els.password.select();
  } finally { els.connectButton.disabled = false; }
}

function renderProjects() {
  if (!state.projects.length) {
    els.projectsGrid.className = "projects-grid empty-state";
    els.projectsGrid.textContent = "Projectはまだありません。";
    return;
  }
  els.projectsGrid.className = "projects-grid";
  els.projectsGrid.replaceChildren(...state.projects.map(project => {
    const card = document.createElement("article");
    card.className = "project-card";
    const fieldCount = Object.keys(project.schema?.fields || {}).length;
    const header = document.createElement("div");
    header.className = "project-card-header";
    const title = document.createElement("h3"); title.textContent = project.project_name;
    const key = document.createElement("span"); key.className = "key"; key.textContent = project.app_key;
    const meta = document.createElement("p"); meta.className = "project-card-meta";
    meta.textContent = `${fieldCount}カラム / ${project.enabled ? "有効" : "無効"}${project.id === null ? " / 設定ファイル由来" : ""}`;
    header.append(title, key, meta);
    const actions = document.createElement("div"); actions.className = "project-card-actions";
    actions.append(
      actionButton("Activities", () => selectProject(project.app_key, "activities"), "primary"),
      actionButton("カラム定義", () => selectProject(project.app_key, "columns")),
      actionButton(project.enabled ? "無効化" : "有効化", () => toggleProject(project), "secondary"),
      actionButton("削除", () => deleteProject(project), "danger")
    );
    card.append(header, actions);
    return card;
  }));
}

function actionButton(label, handler, className = "secondary") {
  const button = document.createElement("button");
  button.type = "button"; button.textContent = label; button.className = className; button.addEventListener("click", handler);
  return button;
}

function selectProject(appKey, view) {
  if (!confirmDiscard()) return;
  state.currentApp = appKey;
  state.schemaDirty = false; state.rows = [];
  populateProjectSelect();
  showView(view, true);
}

async function toggleProject(project) {
  try {
    const updated = await request(`projects.php?app=${encodeURIComponent(project.app_key)}`, {
      method: "PUT", body: JSON.stringify({ enabled: !project.enabled })
    });
    replaceProject(updated); renderProjects(); populateProjectSelect();
    setStatus(`${updated.project_name}を${updated.enabled ? "有効化" : "無効化"}しました。`);
  } catch (error) { setStatus(`更新に失敗しました: ${error.message}`, true); }
}

async function deleteProject(project) {
  if (!confirm(`${project.project_name} (${project.app_key}) を削除しますか？\nActivity本体は削除されません。`)) return;
  try {
    await request(`projects.php?app=${encodeURIComponent(project.app_key)}`, { method: "DELETE" });
    state.projects = state.projects.filter(item => item.app_key !== project.app_key);
    if (state.currentApp === project.app_key) state.currentApp = state.projects[0]?.app_key || "";
    renderProjects(); populateProjectSelect(); updateUrl();
    setStatus(`${project.project_name}を削除しました。Activity本体は保持されています。`);
  } catch (error) { setStatus(`削除に失敗しました: ${error.message}`, true); }
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
  els.userProjectFilter.replaceChildren(new Option("すべて", ""), ...state.projects.filter(project => project.id !== null).map(project => new Option(project.project_name, project.id)));
  els.userProjectFilter.value = selectedFilter;
}

async function createProject() {
  els.newAppKey.value = els.newAppKey.value.trim().toLowerCase();
  if (!els.projectForm.reportValidity()) return;
  try {
    const project = await request("projects.php", { method: "POST", body: JSON.stringify({
      project_name: els.newProjectName.value, app_key: els.newAppKey.value
    }) });
    replaceProject(project); state.currentApp = project.app_key;
    renderProjects(); populateProjectSelect(); els.projectDialog.close(); els.projectForm.reset();
    document.querySelectorAll('[data-view="columns"], [data-view="activities"]').forEach(button => { button.disabled = false; });
    setStatus(`${project.project_name}を作成しました。`); showView("columns", true);
  } catch (error) { setStatus(`作成に失敗しました: ${error.message}`, true); }
}

function orderedFields(schema = state.schema) {
  return Object.entries(schema?.fields || {}).sort((a, b) => (a[1].order ?? 0) - (b[1].order ?? 0));
}

function openColumns() {
  const project = currentProject(); if (!project) return;
  state.schema = structuredClone(project.schema || { fields: {} }); state.schemaDirty = false;
  els.columnsProjectKey.textContent = project.app_key; els.projectName.value = project.project_name; els.projectEnabled.checked = project.enabled;
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
    throw new Error("Tabulatorを読み込めません。ネットワーク接続またはContent Security Policyを確認してください。");
  }
  const data = orderedFields().map(([field, definition]) => columnDefinitionRow(field, definition));
  state.columnsTable?.destroy();
  state.columnsTable = new window.Tabulator(els.columnsTable, {
    data,
    index: "cmmColumnKey",
    height: "100%",
    layout: "fitDataTable",
    placeholder: "カラムはありません。",
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
      { title: "表示名", field: "label", editor: "input", width: 220, minWidth: 140,
        validator: (_cell, value) => printable(value).trim().length >= 1 && printable(value).trim().length <= 255 },
      { title: "型", field: "type", editor: "list", editorParams: { values: fieldTypes }, width: 130 },
      ...["required", "visible", "editable"].map((field, index) => ({
        title: ["必須", "表示", "編集"][index], field, editor: "tickCross", formatter: "tickCross",
        formatterParams: { allowEmpty: false }, hozAlign: "center", headerHozAlign: "center", width: 74
      })),
      { title: "幅(px)", field: "width", editor: "number", editorParams: { min: 80, max: 800 }, sorter: "number", width: 100,
        validator: (_cell, value) => Number.isInteger(Number(value)) && Number(value) >= 80 && Number(value) <= 800 },
      { title: "選択肢（1行1件）", field: "options", editor: columnOptionsEditor,
        editable: cell => optionFieldTypes.has(printable(cell.getRow().getData().type)),
        formatter: "textarea", variableHeight: true, width: 300, minWidth: 200 },
      { title: "操作", headerSort: false, width: 86, minWidth: 86, formatter: () => {
        const button = document.createElement("button"); button.type = "button"; button.className = "danger column-delete-button"; button.textContent = "削除"; return button;
      }, cellClick: (_event, cell) => {
        if (!confirm("このカラムをSchemaから外しますか？既存ActivityのJSON値は保持されます。")) return;
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
    if (!field || seen.has(field)) throw new Error(!field ? "fieldは空にできません。" : `field ${field} が重複しています。`);
    if (field.length > 64) throw new Error(`field ${field} は64文字以内にしてください。`);
    seen.add(field);
    const type = printable(row.type);
    if (!fieldTypes.includes(type)) throw new Error(`field ${field} の型が不正です。`);
    const label = printable(row.label).trim();
    if (!label) throw new Error(`field ${field} の表示名は空にできません。`);
    if (label.length > 255) throw new Error(`field ${field} の表示名は255文字以内にしてください。`);
    const width = Number(row.width);
    if (!Number.isInteger(width) || width < 80 || width > 800) throw new Error(`field ${field} の幅は80〜800の整数にしてください。`);
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
    setStatus("入力内容に誤りがあります。赤く表示されたセルを確認してください。", true); return;
  }
  let schema;
  try { schema = collectSchema(); } catch (error) { setStatus(error.message, true); return; }
  try {
    const updated = await request(`projects.php?app=${encodeURIComponent(state.currentApp)}`, { method: "PUT", body: JSON.stringify({
      project_name: els.projectName.value, enabled: els.projectEnabled.checked, schema
    }) });
    replaceProject(updated); state.schemaDirty = false; renderColumnsTable(); updateSchemaDirty(); renderProjects(); populateProjectSelect();
    setStatus("Project名とカラム定義を保存しました。既存ActivityのJSONは変更していません。");
  } catch (error) { setStatus(`カラム定義の保存に失敗しました: ${error.message}`, true); }
}

async function loadActivities(force = false) {
  if (!force && hasActivityChanges() && !confirmDiscard()) return;
  const project = currentProject();
  if (!project) {
    state.activityTable?.destroy(); state.activityTable = null; state.rows = [];
    state.newActivityRows.clear(); state.deletedActivityRows.clear(); state.dirtyActivityFields.clear();
    els.activitiesProjectKey.textContent = "割り当てなし"; els.appSelect.replaceChildren();
    els.jsonExport.removeAttribute("href"); els.csvExport.removeAttribute("href");
    updateDirtyControls(); setStatus("割り当てられたProjectがありません。管理者へ割り当てを依頼してください。", true);
    return;
  }
  state.schema = project.schema || { fields: {} }; els.search.value = "";
  els.activitiesProjectKey.textContent = `${project.app_key}${project.access_role && project.access_role !== "admin" ? ` / ${project.access_role}` : ""}`; els.appSelect.value = project.app_key;
  setStatus("Activitiesを読み込み中…");
  try {
    const rows = await request(`activities.php?app=${encodeURIComponent(state.currentApp)}`);
    state.newActivityRows.clear(); state.deletedActivityRows.clear(); state.dirtyActivityFields.clear();
    state.rows = rows.map(row => ({ ...row, cmmRowKey: `saved-${row.id}` }));
    updateExportLinks(); renderActivityTable(); updateDirtyControls();
    setStatus(`${rows.length}件のActivityを読み込みました。${canWriteActivities(project) ? "" : " このProjectは閲覧のみです。"}`);
  } catch (error) { setStatus(`読み込みに失敗しました: ${error.message}`, true); }
}

function activityColumns() {
  const system = [
    { field: "id", label: "Activity ID", type: "text", admin: { width: 210 } },
    { field: "osmid", label: "OSM ID", type: "text", required: true, admin: { width: 150 } },
    { field: "form_key", label: "Form", type: "text", admin: { width: 110 } },
    { field: "created_at", label: "作成日時", type: "text", admin: { width: 160, editable: false } },
    { field: "updated_at", label: "更新日時", type: "text", admin: { width: 160, editable: false } }
  ];
  const dynamic = orderedFields().filter(([, def]) => def.admin?.visible !== false).map(([field, def]) => ({ field, ...def }));
  return [...system, ...dynamic];
}

function renderActivityTable() {
  if (typeof window.Tabulator !== "function") {
    throw new Error("Tabulatorを読み込めません。ネットワーク接続またはContent Security Policyを確認してください。");
  }
  state.activityTable?.destroy();
  state.activityTable = new window.Tabulator(els.activityTable, {
    data: state.rows,
    index: "cmmRowKey",
    layout: "fitDataTable",
    placeholder: "Activityはありません。",
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
    columnDefaults: { resizable: "header", headerFilter: "input", headerFilterPlaceholder: "絞り込み" },
    columns: [
      { field: "cmmRowKey", visible: false, headerSort: false, headerFilter: false, clipboard: false, download: false },
      { title: "操作", width: 92, minWidth: 92, headerSort: false, headerFilter: false, clipboard: false, download: false, formatter: deleteActionFormatter, cellClick: deleteActionClick },
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
    title: column.label || column.field,
    field: column.field,
    width: Number(column.admin?.width) || 150,
    minWidth: 80,
    editable: cell => canWriteActivities() && !state.deletedActivityRows.has(String(cell.getRow().getIndex()))
      && column.admin?.editable !== false
      && (column.field !== "id" || isNewActivityKey(cell.getRow().getIndex())),
    validator: column.required ? ((_cell, value) => Array.isArray(value) ? value.length > 0 : printable(value).trim() !== "") : undefined,
    headerFilterFunc: (filterValue, rowValue) => printable(rowValue).toLocaleLowerCase().includes(printable(filterValue).trim().toLocaleLowerCase()),
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
  button.type = "button"; button.className = `activity-delete-button ${deleted ? "secondary" : "danger"}`;
  button.textContent = deleted ? "元に戻す" : (newRow ? "破棄" : "削除");
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
  setStatus("新しい行を追加しました。一括保存するまでDBには反映されません。");
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
  activityColumns().forEach(column => { if (!['created_at', 'updated_at'].includes(column.field)) payload[column.field] = normalizeActivityValue(column, row[column.field]); });
  return payload;
}

async function saveAll() {
  if (!canWriteActivities()) return;
  const validation = state.activityTable?.validate();
  if (validation !== true) { setStatus("必須項目を入力してから保存してください。", true); validation?.[0]?.getElement()?.scrollIntoView({ block: "center" }); return; }
  if (state.activityTable) state.rows = state.activityTable.getData();
  const payload = { app: state.currentApp, creates: [], updates: [], deletes: [] };
  state.rows.forEach(row => {
    const key = String(row.cmmRowKey);
    if (state.deletedActivityRows.has(key)) payload.deletes.push(row.id);
    else if (isNewActivityKey(key)) payload.creates.push(rowPayload(row));
    else if (state.dirtyActivityFields.has(key)) payload.updates.push(rowPayload(row));
  });
  setStatus("変更をトランザクションで一括保存中…"); els.saveAllButton.disabled = true;
  try {
    const result = await request("activities-batch.php", { method: "POST", body: JSON.stringify(payload) });
    await loadActivities(true);
    setStatus(`一括保存しました（追加${result.created.length}・更新${result.updated.length}・削除${result.deleted.length}）。`);
  } catch (error) { updateDirtyControls(); setStatus(`一括保存に失敗しました。DBはロールバックされ、編集内容は画面に保持されています: ${error.message}`, true); }
}

function updateDirtyControls() {
  const keys = new Set([...state.newActivityRows, ...state.deletedActivityRows, ...state.dirtyActivityFields.keys()]);
  const count = keys.size;
  const writable = canWriteActivities();
  els.addButton.disabled = !writable; els.saveAllButton.disabled = !writable || count === 0; els.discardButton.disabled = !writable || count === 0; els.dirtyCount.textContent = count ? `(${count})` : "";
}

function applyGlobalActivityFilter() {
  if (!state.activityTable) return;
  const needle = els.search.value.trim().toLocaleLowerCase();
  if (!needle) { state.activityTable.clearFilter(); return; }
  const fields = activityColumns().map(column => column.field);
  state.activityTable.setFilter(row => fields.some(field => printable(row[field]).toLocaleLowerCase().includes(needle)));
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
  try { rows = JSON.parse(text); } catch { setStatus("JSONファイルを解析できません。", true); return; }
  if (!Array.isArray(rows)) { setStatus("Import JSONはActivity配列にしてください。", true); return; }
  try {
    const dry = await request("activity-import.php", { method: "POST", body: JSON.stringify({ app: state.currentApp, rows, dry_run: true }) });
    if (!confirm(`${dry.valid}件（新規${dry.created}・更新${dry.updated}）を取り込みますか？`)) return;
    await request("activity-import.php", { method: "POST", body: JSON.stringify({ app: state.currentApp, rows, dry_run: false }) });
    await loadActivities(true); setStatus(`${dry.valid}件を取り込みました。`);
  } catch (error) { setStatus(`Importに失敗しました: ${error.message}`, true); }
  finally { els.importFile.value = ""; }
}

async function previewCsv(csv) {
  try {
    const preview = await request("activity-import-csv.php", { method: "POST", body: JSON.stringify({ app: state.currentApp, csv, dry_run: true }) });
    state.pendingCsv = { csv, preview }; els.csvSummary.textContent = `${preview.valid}件（新規${preview.created}・更新${preview.updated}）`;
    const updateFields = preview.schema_update_fields || preview.missing_fields || [];
    const details = [];
    if (preview.missing_fields?.length) details.push(`未定義カラム: ${preview.missing_fields.join(", ")}`);
    const typeChanges = Object.entries(preview.schema_changes || {}).filter(([, change]) => change.type)
      .map(([field, change]) => `${field}: ${change.type.from} → ${change.type.to}`);
    if (typeChanges.length) details.push(`型変更: ${typeChanges.join(", ")}`);
    const optionChanges = Object.entries(preview.schema_changes || {}).filter(([, change]) => change.added_options?.length)
      .map(([field, change]) => `${field}: +${change.added_options.join(", +")}`);
    if (optionChanges.length) details.push(`選択肢追加: ${optionChanges.join(" / ")}`);
    const normalized = Object.entries(preview.normalizations || {}).map(([field, count]) => `${field} ${count}件`);
    if (normalized.length) details.push(`値の正規化: ${normalized.join(", ")}`);
    els.csvMissing.textContent = details.length ? details.join("\n") : "Schema変更や値の正規化はありません。";
    els.addMissingLabel.hidden = updateFields.length === 0; els.addMissingColumns.checked = true; els.csvDialog.showModal();
  } catch (error) { setStatus(`CSVの確認に失敗しました: ${error.message}`, true); els.importFile.value = ""; }
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
    setStatus(`${pending.preview.valid}件のCSVを取り込みました。`);
  } catch (error) { setStatus(`CSV importに失敗しました: ${error.message}`, true); }
}

async function loadUsers(resetPage = false) {
  if (resetPage) state.userPage = 1;
  const params = new URLSearchParams({ page: String(state.userPage), per_page: "25" });
  if (els.usersSearch.value.trim()) params.set("search", els.usersSearch.value.trim());
  if (els.userStatusFilter.value) params.set("status", els.userStatusFilter.value);
  if (els.userVerifiedFilter.value) params.set("verified", els.userVerifiedFilter.value);
  if (els.userRoleFilter.value) params.set("role", els.userRoleFilter.value);
  if (els.userProjectFilter.value) params.set("project_id", els.userProjectFilter.value);
  setStatus("ユーザー一覧を読み込み中…");
  try {
    const result = await request(`admin-users.php?${params}`);
    state.users = result.items; state.userPagination = result.pagination; renderUsers();
    setStatus(`${result.pagination.total}件のユーザーを読み込みました。`);
  } catch (error) { setStatus(`ユーザー一覧の取得に失敗しました: ${error.message}`, true); }
}

function renderUsers() {
  els.usersBody.replaceChildren(...state.users.map(user => {
    const tr = document.createElement("tr");
    tr.innerHTML = `<td>${escapeHtml(user.userid)}</td><td>${escapeHtml(user.email || "—")}</td>
      <td><span class="status-badge ${escapeAttribute(user.status)}">${escapeHtml(user.status)}</span></td>
      <td>${escapeHtml(user.role)}</td><td>${user.email ? (user.email_verified_at ? "確認済み" : "未確認") : "対象外"}</td>
      <td>${escapeHtml(user.last_login_at || "-")}</td><td>${user.activity_count}</td><td>${user.project_count}</td>
      <td>${escapeHtml(user.updated_at)}</td><td></td>`;
    tr.lastElementChild.append(actionButton("詳細", () => openUser(user.id)));
    return tr;
  }));
  if (!state.users.length) {
    const tr = document.createElement("tr"); const td = document.createElement("td"); td.colSpan = 10; td.textContent = "該当するユーザーはいません。"; tr.append(td); els.usersBody.append(tr);
  }
  const pagination = state.userPagination || { page: 1, total_pages: 1, total: 0 };
  els.usersPageInfo.textContent = `${pagination.page} / ${pagination.total_pages}ページ（${pagination.total}件）`;
  els.usersPrevButton.disabled = pagination.page <= 1;
  els.usersNextButton.disabled = pagination.page >= pagination.total_pages;
}

function renderProjectAssignments(container, assignments = []) {
  const selected = new Map(assignments.map(item => [Number(item.project_id), item.role || "editor"]));
  const projects = state.projects.filter(project => project.id !== null);
  container.replaceChildren(...projects.map(project => {
    const row = document.createElement("div"); row.className = "project-assignment"; row.dataset.projectId = project.id;
    const checkbox = document.createElement("input"); checkbox.type = "checkbox"; checkbox.checked = selected.has(Number(project.id));
    const label = document.createElement("label"); label.append(checkbox, document.createTextNode(`${project.project_name} (${project.app_key})`));
    const role = document.createElement("select");
    role.append(new Option("viewer", "viewer"), new Option("editor", "editor"), new Option("project_admin", "project_admin"));
    role.value = selected.get(Number(project.id)) || "editor"; role.disabled = !checkbox.checked;
    checkbox.addEventListener("change", () => { role.disabled = !checkbox.checked; });
    row.append(label, role); return row;
  }));
  if (!projects.length) container.textContent = "DB管理のProjectはありません。";
}

function collectProjectAssignments(container) {
  return [...container.querySelectorAll(".project-assignment")].filter(row => row.querySelector('input[type="checkbox"]').checked).map(row => ({
    project_id: Number(row.dataset.projectId), role: row.querySelector("select").value
  }));
}

async function openUser(id) {
  try {
    const user = await request(`admin-users.php?id=${encodeURIComponent(id)}`); state.currentUser = user;
    els.userDialogTitle.textContent = `ユーザー: ${user.userid}`;
    const metadata = [
      ["ユーザーID", user.userid], ["メール", user.email || "未登録"], ["メール確認", user.email ? (user.email_verified_at || "未確認") : "対象外"],
      ["登録日時", user.created_at], ["更新日時", user.updated_at], ["最終ログイン", user.last_login_at || "-"],
      ["投稿Activity", `${user.activity_count}件`], ["利用Project", `${user.project_count}件`]
    ];
    els.userMetadata.replaceChildren(...metadata.flatMap(([label, value]) => {
      const dt = document.createElement("dt"); dt.textContent = label; const dd = document.createElement("dd"); dd.textContent = value; return [dt, dd];
    }));
    els.editUserStatus.value = user.status; els.editUserRole.value = user.role;
    renderProjectAssignments(els.editUserProjects, user.projects);
    els.resendVerificationButton.disabled = !user.email || user.status !== "pending";
    els.sendPasswordResetButton.disabled = !user.email || user.status === "disabled";
    els.editUserPassword.value = ""; els.editUserPassword.setCustomValidity("");
    els.editUserPasswordConfirmation.value = ""; els.editUserPasswordConfirmation.setCustomValidity("");
    els.userPasswordStatus.textContent = "";
    els.userRecentActivities.replaceChildren(...user.recent_activities.map(activity => {
      const li = document.createElement("li"); li.textContent = `${activity.updated_at}  ${activity.app_key} / ${activity.activity_key}`; return li;
    }));
    if (!user.recent_activities.length) { const li = document.createElement("li"); li.textContent = "Activityはありません。"; els.userRecentActivities.append(li); }
    els.userDialog.showModal();
  } catch (error) { setStatus(`ユーザー詳細の取得に失敗しました: ${error.message}`, true); }
}

async function saveUser() {
  const user = state.currentUser; if (!user) return;
  try {
    const updated = await request(`admin-users.php?id=${encodeURIComponent(user.id)}`, { method: "PUT", body: JSON.stringify({
      status: els.editUserStatus.value, role: els.editUserRole.value, projects: collectProjectAssignments(els.editUserProjects)
    }) });
    state.currentUser = updated; els.userDialog.close(); await loadUsers(); setStatus(`${updated.userid}を更新しました。`);
  } catch (error) { setStatus(`ユーザー更新に失敗しました: ${error.message}`, true); }
}

async function createUser() {
  updateNewUserMode();
  const direct = els.newUserEmail.value.trim() === "";
  els.newUserPasswordConfirmation.setCustomValidity(direct && els.newUserPassword.value !== els.newUserPasswordConfirmation.value ? "初期パスワードが一致しません。" : "");
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
    if (user.creation_mode === "direct") setStatus(`${user.userid}を追加しました。初期パスワードでログインできます。`);
    else setStatus(`${user.userid}を招待しました。パスワード設定メール: ${user.password_setup_email_sent ? "送信済み" : "送信失敗"}`);
  } catch (error) { setStatus(`ユーザー作成に失敗しました: ${error.message}`, true); }
}

function updateNewUserMode() {
  const direct = els.newUserEmail.value.trim() === "";
  els.newUserPasswordFields.hidden = !direct;
  els.newUserPassword.required = direct; els.newUserPassword.disabled = !direct;
  els.newUserPasswordConfirmation.required = direct; els.newUserPasswordConfirmation.disabled = !direct;
  els.newUserSubmitButton.textContent = direct ? "追加" : "招待メールを送信";
  els.newUserModeHint.textContent = direct
    ? "メールなしの場合は、管理者が設定した初期パスワードですぐに利用できます。"
    : "メールへパスワード設定リンクを送信し、設定完了後に利用できます。";
  if (!direct) els.newUserPasswordConfirmation.setCustomValidity("");
}

async function userMailAction(action) {
  const user = state.currentUser; if (!user) return;
  const label = action === "resend_verification" ? "確認メール" : "パスワード再設定メール";
  if (!confirm(`${user.userid}へ${label}を送信しますか？`)) return;
  try {
    const result = await request("admin-users.php", { method: "POST", body: JSON.stringify({ action, id: user.id }) });
    const sent = action === "resend_verification" ? result.verification_email_sent : result.reset_email_sent;
    setStatus(`${label}を${sent ? "送信しました" : "送信できませんでした"}。`, !sent);
  } catch (error) { setStatus(`${label}の送信に失敗しました: ${error.message}`, true); }
}

async function setUserPassword() {
  const user = state.currentUser; if (!user) return;
  const password = els.editUserPassword.value;
  const confirmation = els.editUserPasswordConfirmation.value;
  els.editUserPassword.setCustomValidity(password ? "" : "新しいパスワードを入力してください。");
  els.editUserPasswordConfirmation.setCustomValidity(!confirmation
    ? "新しいパスワード（確認）を入力してください。"
    : (password !== confirmation ? "新しいパスワードが一致しません。" : ""));
  if (!els.editUserPassword.reportValidity() || !els.editUserPasswordConfirmation.reportValidity()) return;
  els.setUserPasswordButton.disabled = true; els.userPasswordStatus.textContent = "設定中…";
  try {
    const result = await request("admin-users.php", { method: "POST", body: JSON.stringify({
      action: "set_password", id: user.id, password, password_confirmation: confirmation
    }) });
    state.currentUser = result.user;
    if (user.id === state.sessionUser?.id) {
      els.userid.value = user.userid; els.password.value = password;
      await request('console-session.php', { method: 'POST', basic: true });
      els.password.value = '';
    }
    els.editUserPassword.value = ""; els.editUserPasswordConfirmation.value = "";
    els.userPasswordStatus.textContent = "新しいパスワードを設定しました。";
    setStatus(`${user.userid}のパスワードを再設定しました。`);
  } catch (error) {
    els.userPasswordStatus.textContent = `設定できません: ${error.message}`;
  } finally { els.setUserPasswordButton.disabled = false; }
}

function escapeHtml(value) { const span = document.createElement("span"); span.textContent = String(value ?? ""); return span.innerHTML; }
function escapeAttribute(value) { return escapeHtml(value).replace(/"/g, "&quot;"); }

els.loginForm.addEventListener("submit", event => { event.preventDefault(); connect(); });
els.logoutButton.addEventListener("click", logout);
document.querySelectorAll("[data-view]").forEach(button => button.addEventListener("click", () => showView(button.dataset.view)));
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
els.saveColumnsButton.addEventListener("click", saveColumns);
els.projectName.addEventListener("input", markSchemaDirty); els.projectEnabled.addEventListener("change", markSchemaDirty);
els.loadButton.addEventListener("click", () => loadActivities()); els.addButton.addEventListener("click", addActivityRow);
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
els.resendVerificationButton.addEventListener("click", () => userMailAction("resend_verification"));
els.sendPasswordResetButton.addEventListener("click", () => userMailAction("send_password_reset"));
els.setUserPasswordButton.addEventListener("click", setUserPassword);
els.editUserPassword.addEventListener("input", () => els.editUserPassword.setCustomValidity(""));
els.editUserPasswordConfirmation.addEventListener("input", () => els.editUserPasswordConfirmation.setCustomValidity(""));
window.addEventListener("beforeunload", event => { if (hasUnsavedChanges()) { event.preventDefault(); event.returnValue = ""; } });
