"use strict";

let adminMessages = null;

async function loadAdminMessages() {
  const locale = document.documentElement.lang === "en" ? "en" : "ja";
  const response = await fetch(`./${locale}.json?v=20261003-activity-count`, { cache: "no-cache" });
  if (!response.ok) throw new Error(`HTTP ${response.status}`);
  adminMessages = await response.json();
}

function t(key, params = {}) {
  const message = adminMessages?.[key] ?? key;
  return message.replace(/\{([a-zA-Z_]+)\}/g, (match, name) =>
    Object.prototype.hasOwnProperty.call(params, name) ? String(params[name]) : match);
}
