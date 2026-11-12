"use strict";

// Versioned column definitions only: no environment-specific project settings.
// Stable, language-independent definitions for the two system ID columns.
const systemIdFields = Object.freeze({
  id: Object.freeze({ label: "ID", type: "text", required: false, admin: Object.freeze({ width: 210 }) }),
  osmid: Object.freeze({ label: "OSM ID", type: "text", required: true, admin: Object.freeze({ width: 150 }) })
});

function sameColumnDefinition(left, right) {
  if (left === right) return true;
  if (!left || !right || typeof left !== "object" || typeof right !== "object" || Array.isArray(left) !== Array.isArray(right)) return false;
  const keys = Object.keys(left);
  return keys.length === Object.keys(right).length && keys.every(key => Object.hasOwn(right, key) && sameColumnDefinition(left[key], right[key]));
}

window.ColumnTransfer = Object.freeze({
  parse(text) {
    let data;
    try { data = JSON.parse(text.replace(/^\uFEFF/, "")); }
    catch { throw new Error("columns.invalid_json"); }
    const object = value => value !== null && typeof value === "object" && !Array.isArray(value);
    if (!object(data)) throw new Error("columns.invalid_format");
    let schema = data;
    if ("format" in data || "version" in data || "schema" in data) {
      if (data.format !== "community-mapmaker-columns" || data.version !== 1) throw new Error("columns.invalid_format");
      schema = data.schema;
    }
    if (!object(schema) || !object(schema.fields) || Object.values(schema.fields).some(field => !object(field))) {
      throw new Error("columns.invalid_format");
    }
    const fields = Object.create(null);
    for (const [field, definition] of Object.entries(schema.fields)) {
      if (Object.hasOwn(systemIdFields, field)) {
        if (!sameColumnDefinition(definition, systemIdFields[field])) throw new Error(`columns.system_schema_mismatch.${field}`);
        continue;
      }
      fields[field] = definition;
    }
    return { fields };
  },
  stringify(schema) {
    // System IDs accompany custom fields in files, but never enter saved schemas.
    const fields = { ...systemIdFields, ...schema.fields };
    return JSON.stringify({ format: "community-mapmaker-columns", version: 1, schema: { fields } }, null, 2) + "\n";
  },
  diff(before, after) {
    const oldKeys = Object.keys(before.fields), newKeys = Object.keys(after.fields);
    return {
      total: newKeys.length,
      added: newKeys.filter(key => !Object.hasOwn(before.fields, key)),
      removed: oldKeys.filter(key => !Object.hasOwn(after.fields, key))
    };
  }
});
