const fieldTypes = new Set(["string", "integer", "number", "boolean", "date", "datetime", "time", "json", "uuid", "ulid", "binary", "unknown"]);
const constraintKeys = ["nullable", "required_on_create", "max_length", "precision", "scale", "unsigned", "enum"];
const operationMethods = { list: ["GET"], create: ["POST"], show: ["GET"], update: ["PUT", "PATCH"], delete: ["DELETE"], replace: ["POST", "PUT", "PATCH"] };

function object(value, keys, required, label) {
  if (!value || typeof value !== "object" || Array.isArray(value)) throw new Error(`${label}: se esperaba un objeto.`);
  for (const key of Object.keys(value)) if (!keys.includes(key)) throw new Error(`${label}: propiedad desconocida ${key}.`);
  for (const key of required) if (!(key in value)) throw new Error(`${label}: falta ${key}.`);
}

function text(value, label) {
  if (typeof value !== "string" || !value.trim()) throw new Error(`${label}: se esperaba texto no vacío.`);
}

function unique(values, label) {
  if (new Set(values).size !== values.length) throw new Error(`${label}: valores duplicados.`);
}

function names(value, label) {
  if (!Array.isArray(value) || !value.length) throw new Error(`${label}: se esperaba una lista no vacía.`);
  value.forEach((name) => text(name, label));
  unique(value, label);
}

function sameNames(left, right) {
  return left.length === right.length && left.every((name, index) => name === right[index]);
}

function sameEndpoint(left, right) {
  return left.method === right.method && left.path === right.path
    && left.parameters.length === right.parameters.length
    && left.parameters.every((parameter, index) => parameter.name === right.parameters[index].name && parameter.source === right.parameters[index].source);
}

function endpoint(value, operation) {
  const keys = operation === "replace" ? ["method", "path", "parameters", "body_field"] : ["method", "path", "parameters"];
  object(value, keys, keys, `Endpoint ${operation}`);
  if (!operationMethods[operation].includes(value.method)) throw new Error(`Método no válido para ${operation}.`);
  if (typeof value.path !== "string" || !/^\/(?!\/)[^?#\\]*$/.test(value.path) || value.path.includes("//")) throw new Error("Ruta de endpoint no válida.");
  const segments = value.path.slice(1).split("/");
  const names = [];
  for (const segment of segments) {
    if (/^\{[A-Za-z_][A-Za-z0-9_]*\}$/.test(segment)) names.push(segment.slice(1, -1));
    else if (!segment || /[{}]/.test(segment) || segment === "." || segment === "..") throw new Error("Segmento de endpoint no válido.");
  }
  if (!Array.isArray(value.parameters)) throw new Error("Los parámetros deben ser un array.");
  unique(names, "Placeholders");
  const parameters = value.parameters.map((parameter) => {
    object(parameter, ["name", "source"], ["name", "source"], "Parámetro");
    text(parameter.name, "Nombre de parámetro");
    if (parameter.source !== "entity_id") throw new Error("Origen de parámetro no soportado.");
    return parameter.name;
  });
  unique(parameters, "Parámetros");
  if (names.length !== parameters.length || names.some((name) => !parameters.includes(name))) throw new Error("Los parámetros no coinciden con la ruta.");
  if (operation === "replace") text(value.body_field, "Campo de asociación");
}

export function validateMetadata(metadata) {
  object(metadata, ["contract", "version", "api_prefix", "entities", "relations"], ["contract", "version", "api_prefix", "entities", "relations"], "Metadatos");
  if (metadata.contract !== "lrg.frontend.metadata" || !["1.0", "2.0"].includes(metadata.version)) throw new Error("Contrato frontend no soportado.");
  const v2 = metadata.version === "2.0";
  if (typeof metadata.api_prefix !== "string" || (metadata.api_prefix !== "" && !/^\/(?!\/)[^?#\\]+(?<!\/)$/.test(metadata.api_prefix))) throw new Error("Prefijo API no válido.");
  if (!Array.isArray(metadata.entities) || !Array.isArray(metadata.relations)) throw new Error("Entidades y relaciones deben ser arrays.");
  const entities = new Map();
  for (const entity of metadata.entities) {
    const entityKeys = ["id", "primary_key", "fields", "operations", ...(v2 ? ["label", "table", "display_field", "response_convention"] : [])];
    object(entity, entityKeys, entityKeys, "Entidad");
    text(entity.id, "ID de entidad");
    text(entity.primary_key, "Clave primaria");
    if (v2) {
      text(entity.label, "Etiqueta de entidad");
      text(entity.table, "Tabla");
      if (entity.display_field !== null) text(entity.display_field, "Campo de presentación");
    }
    if (entities.has(entity.id)) throw new Error(`Entidad duplicada: ${entity.id}.`);
    if (!Array.isArray(entity.fields)) throw new Error("Los campos deben ser un array.");
    const fields = new Map();
    for (const field of entity.fields) {
      object(field, ["name", "type", "access", "constraints", ...(v2 ? ["default"] : [])], ["name", "type", "access", "constraints"], "Campo");
      text(field.name, "Nombre de campo");
      if (fields.has(field.name) || !fieldTypes.has(field.type) || !["editable", "read_only"].includes(field.access)) throw new Error(`Campo inválido o duplicado: ${field.name}.`);
      object(field.constraints, [...constraintKeys, ...(v2 ? ["unique", "database_required"] : [])], [], "Restricciones");
      for (const key of ["nullable", "required_on_create", "unsigned", ...(v2 ? ["unique", "database_required"] : [])]) if (key in field.constraints && typeof field.constraints[key] !== "boolean") throw new Error(`Restricción inválida: ${key}.`);
      for (const key of ["max_length", "precision", "scale"]) if (key in field.constraints && (!Number.isInteger(field.constraints[key]) || field.constraints[key] < (key === "precision" ? 1 : 0))) throw new Error(`Restricción inválida: ${key}.`);
      if ("enum" in field.constraints) {
        const values = field.constraints.enum;
        if (!Array.isArray(values) || !values.length || values.some((value) => typeof value !== "string")) throw new Error("Enum inválido.");
        unique(values, "Enum");
      }
      if (v2 && "default" in field && field.default !== null && typeof field.default !== "boolean"
        && (typeof field.default !== "number" || !Number.isFinite(field.default))) throw new Error(`Valor predeterminado inválido: ${field.name}.`);
      fields.set(field.name, field);
    }
    if (fields.get(entity.primary_key)?.access !== "read_only") throw new Error(`Clave primaria no publicada o editable: ${entity.id}.`);
    if (v2 && entity.display_field !== null && (!fields.has(entity.display_field) || sensitiveField(entity.display_field))) throw new Error(`Campo de presentación inválido: ${entity.id}.`);
    object(entity.operations, ["list", "create", "show", "update", "delete"], [], "Operaciones");
    for (const [name, value] of Object.entries(entity.operations)) {
      if (name === "update") {
        if (!Array.isArray(value) || !value.length) throw new Error("Update debe contener endpoints.");
        value.forEach((item) => endpoint(item, name));
        unique(value.map((item) => item.method), "Métodos de actualización");
      } else endpoint(value, name);
    }
    if (v2) {
      object(entity.response_convention, ["list", "create", "show", "update", "delete"], Object.keys(entity.operations), "Convención de respuesta");
      for (const [operation, convention] of Object.entries(entity.response_convention)) {
        if (!(operation in entity.operations) || convention !== (operation === "delete" ? "no_content" : "json")) throw new Error(`Convención de respuesta inválida: ${entity.id}.${operation}.`);
      }
    }
    entities.set(entity.id, { entity, fields });
  }
  const relationIds = [];
  const associationInputs = new Set();
  for (const relation of metadata.relations) {
    const keys = ["id", "cardinality", "source", "target", "source_fields", "target_fields", "association_operations",
      ...(v2 ? ["name", "inverse_name", "foreign_key", "pivot_table", "pivot_foreign_keys"] : [])];
    object(relation, keys, keys, "Relación");
    text(relation.id, "ID de relación");
    relationIds.push(relation.id);
    if (!["1:1", "1:N", "N:M"].includes(relation.cardinality) || !entities.has(relation.source) || !entities.has(relation.target)) throw new Error(`Relación inválida: ${relation.id}.`);
    for (const [name, id] of [["source_fields", relation.source], ["target_fields", relation.target]]) {
      names(relation[name], "Campos de relación");
      if (relation[name].some((field) => !entities.get(id).fields.has(field))) throw new Error(`Campos inválidos en ${relation.id}.`);
    }
    const source = entities.get(relation.source).entity;
    const target = entities.get(relation.target).entity;
    if (v2) {
      text(relation.name, "Nombre de relación");
      text(relation.inverse_name, "Nombre de relación inversa");
      if (relation.cardinality === "N:M") {
        if (relation.foreign_key !== null) throw new Error(`FK incompatible en ${relation.id}.`);
        text(relation.pivot_table, "Tabla pivote");
        object(relation.pivot_foreign_keys, ["source", "target"], ["source", "target"], "Claves pivote");
        for (const side of ["source", "target"]) {
          const key = relation.pivot_foreign_keys[side];
          object(key, ["columns", "referenced_columns"], ["columns", "referenced_columns"], "Clave pivote");
          names(key.columns, "Columnas pivote");
          names(key.referenced_columns, "Columnas referenciadas");
          if (key.columns.length !== key.referenced_columns.length || !sameNames(key.referenced_columns, relation[`${side}_fields`])) throw new Error(`Clave pivote inconsistente: ${relation.id}.`);
        }
        if (!sameNames(relation.source_fields, [source.primary_key]) || !sameNames(relation.target_fields, [target.primary_key])) throw new Error(`Claves de asociación inválidas: ${relation.id}.`);
      } else {
        if (relation.pivot_table !== null || relation.pivot_foreign_keys !== null) throw new Error(`Pivote incompatible en ${relation.id}.`);
        const key = relation.foreign_key;
        object(key, ["table", "columns", "referenced_table", "referenced_columns"], ["table", "columns", "referenced_table", "referenced_columns"], "Clave foránea");
        if (key.table !== target.table || key.referenced_table !== source.table
          || !Array.isArray(key.columns) || !Array.isArray(key.referenced_columns)
          || !sameNames(key.columns, relation.target_fields) || !sameNames(key.referenced_columns, relation.source_fields)) throw new Error(`Clave foránea inconsistente: ${relation.id}.`);
      }
    }
    object(relation.association_operations, v2 ? ["replace", "create", "update"] : ["replace"], [], "Operaciones de asociación");
    if ("replace" in relation.association_operations) {
      endpoint(relation.association_operations.replace, "replace");
    }
    if (v2) for (const name of ["create", "update"]) {
      if (!(name in relation.association_operations)) continue;
      const association = relation.association_operations[name];
      if (relation.cardinality !== "N:M" || !source.operations[name]) throw new Error(`Operación de asociación inválida: ${relation.id}.${name}.`);
      object(association, ["endpoints", "body_field", "semantics"], ["endpoints", "body_field", "semantics"], "Operación de asociación");
      text(association.body_field, "Campo de asociación");
      if (association.semantics !== "replace" || !Array.isArray(association.endpoints) || !association.endpoints.length
        || entities.get(relation.source).fields.has(association.body_field)) throw new Error(`Operación de asociación inválida: ${relation.id}.${name}.`);
      const allowed = name === "create" ? [source.operations.create] : source.operations.update;
      if (association.endpoints.length !== allowed.length) throw new Error(`Endpoints de asociación incompletos: ${relation.id}.${name}.`);
      for (const item of association.endpoints) {
        endpoint(item, name);
        if (!allowed.some((candidate) => sameEndpoint(item, candidate))) throw new Error(`Endpoint de asociación no coincide con CRUD: ${relation.id}.${name}.`);
      }
      unique(association.endpoints.map((item) => item.method), "Métodos de asociación");
      const input = JSON.stringify([relation.source, name, association.body_field]);
      if (associationInputs.has(input)) throw new Error(`Campo de asociación ambiguo: ${association.body_field}.`);
      associationInputs.add(input);
    }
  }
  unique(relationIds, "Relaciones");
  return metadata;
}

export function foreignSelector(metadata, entity, field) {
  const matches = [];
  for (const relation of metadata.relations) {
    if (relation.cardinality === "N:M" || relation.source_fields.length !== 1 || relation.target_fields.length !== 1) continue;
    const source = metadata.entities.find((item) => item.id === relation.source);
    const target = metadata.entities.find((item) => item.id === relation.target);
    if (entity.id === target.id && relation.target_fields[0] === field.name && relation.source_fields[0] === source.primary_key) matches.push(source);
    if (metadata.version !== "2.0" && relation.cardinality === "1:1" && entity.id === source.id && relation.source_fields[0] === field.name && relation.target_fields[0] === target.primary_key) matches.push(target);
  }
  return matches.length === 1 ? matches[0] : null;
}

export function sensitiveField(name) {
  return /password|passwd|secret|token|credential|private.?key|api.?key|access.?key|two.?factor|recovery.?codes|(?:^|_)(?:security|auth|salt|hash|pin|otp|mfa)(?:_|$)/i.test(name);
}
