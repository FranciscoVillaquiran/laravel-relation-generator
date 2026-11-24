import { foreignSelector, sensitiveField } from "./contract.js";

export function label(name) {
  return name.replace(/[_-]+/g, " ").replace(/^\w/, (letter) => letter.toLocaleUpperCase());
}

export function editableFields(entity) {
  return entity.fields.filter((field) => field.access === "editable" && !sensitiveField(field.name));
}

export function widgetFor(field, hint, foreign) {
  if (foreign || field.constraints.enum) return "select";
  if (hint === "text" && field.type === "string") return "text";
  if (hint === "email" && field.type === "string") return "email";
  return {
    string: "string", uuid: "string", ulid: "string", integer: "integer",
    number: "decimal", boolean: "boolean", date: "date", datetime: "datetime",
  }[field.type] || null;
}

export function fieldsFor(metadata, entity, presentation = {}) {
  return editableFields(entity).map((field) => {
    const related = foreignSelector(metadata, entity, field);
    const hint = presentation.fields?.[field.name];
    const relationField = metadata.relations.some((relation) => relation.cardinality !== "N:M" && (
      (relation.target === entity.id && relation.target_fields.includes(field.name))
      || (metadata.version !== "2.0" && relation.source === entity.id && relation.source_fields.includes(field.name))
    ));
    const widget = relationField && !related ? null : widgetFor(field, hint?.widget, related);
    return { ...field, label: hint?.label || label(field.name), widget, related };
  });
}

export function initialValues(fields, record) {
  return Object.fromEntries(fields.filter((field) => field.widget).map((field) => {
    const value = record ? record[field.name] : field.default;
    if (field.type === "boolean") {
      if ([true, 1, "1", "true"].includes(value)) return [field.name, "true"];
      if ([false, 0, "0", "false"].includes(value)) return [field.name, "false"];
      return [field.name, ""];
    }
    return [field.name, value == null ? "" : String(value)];
  }));
}

export function requiredOnCreate(field) {
  return field.constraints.required_on_create === true || field.constraints.database_required === true;
}

export function associationFieldsFor(metadata, entity, creating) {
  if (metadata.version !== "2.0") return [];
  const operation = creating ? "create" : "update";
  return metadata.relations
    .filter((relation) => relation.source === entity.id && relation.cardinality === "N:M" && relation.association_operations[operation])
    .map((relation) => ({
      relation,
      target: metadata.entities.find((item) => item.id === relation.target),
      operation: relation.association_operations[operation],
    }));
}

export function validAssociationOptions(target, rows) {
  if (!Array.isArray(rows)) return false;
  const keys = rows.map((row) => row?.[target.primary_key]);
  return keys.every((key) => (typeof key === "string" && key.length > 0) || (typeof key === "number" && Number.isSafeInteger(key)))
    && new Set(keys.map(String)).size === keys.length;
}

export function addAssociationPayload(payload, groups, state, records) {
  for (const { relation, target, operation } of groups) {
    const selection = state[relation.id];
    if (!selection?.touched) continue;
    if (!selection.loaded || !target.operations.list || !validAssociationOptions(target, records[target.id])) throw new Error(`No se puede verificar la asociación ${relation.id}.`);
    const rows = records[target.id];
    const ids = selection.values.map((value) => {
      const match = rows.find((row) => String(row[target.primary_key]) === value);
      if (!match) throw new Error(`Una asociación de ${relation.id} ya no está disponible.`);
      return match[target.primary_key];
    });
    payload[operation.body_field] = ids;
  }
  return payload;
}

export function makePayload(fields, values, creating, original) {
  const payload = {};
  for (const field of fields) {
    if (!field.widget) continue;
    const raw = values[field.name];
    if (!creating && String(initialValues([field], original)[field.name]) === raw) continue;
    if (raw === "") {
      if (creating) continue;
      if (field.constraints.nullable === true) payload[field.name] = null;
      else if (!creating && original?.[field.name] !== undefined) throw new Error(`${field.label}: no se puede enviar un valor vacío sin confirmar que admite null.`);
      continue;
    }
    if (field.type === "boolean") payload[field.name] = raw === "true";
    else if (field.type === "integer") {
      const number = Number(raw);
      if (!Number.isSafeInteger(number)) throw new Error(`${field.label}: introduce un entero seguro.`);
      if (field.constraints.unsigned && number < 0) throw new Error(`${field.label}: no admite valores negativos.`);
      payload[field.name] = number;
    } else if (field.type === "number") {
      if (!/^-?(?:\d+(?:\.\d*)?|\.\d+)$/.test(raw) || !Number.isFinite(Number(raw))) throw new Error(`${field.label}: introduce un número decimal válido.`);
      if (field.constraints.unsigned && Number(raw) < 0) throw new Error(`${field.label}: no admite valores negativos.`);
      const [whole, fraction = ""] = raw.replace(/^-/, "").split(".");
      const integerDigits = whole.replace(/^0+/, "").length;
      const { precision, scale } = field.constraints;
      if (scale !== undefined && fraction.length > scale) throw new Error(`${field.label}: admite como máximo ${scale} decimales.`);
      if (precision !== undefined && (scale !== undefined ? integerDigits > precision - scale : integerDigits + fraction.length > precision)) throw new Error(`${field.label}: supera la precisión permitida.`);
      payload[field.name] = raw;
    } else payload[field.name] = raw;
  }
  return payload;
}
