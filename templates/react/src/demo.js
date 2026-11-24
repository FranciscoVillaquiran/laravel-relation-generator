import { createMemorySource } from "./data/memory.js";

const field = (name, type, access = "editable", constraints = {}) => ({ name, type, access, constraints });
const endpoint = (method, path, parameter) => ({
  method,
  path,
  parameters: parameter ? [{ name: parameter, source: "entity_id" }] : [],
});
const entity = (id, primaryKey, fields) => ({
  id,
  primary_key: primaryKey,
  fields: [field(primaryKey, "string", "read_only"), ...fields],
  operations: {
    list: endpoint("GET", `/${id}`),
    create: endpoint("POST", `/${id}`),
    show: endpoint("GET", `/${id}/{key}`, "key"),
    update: [endpoint("PATCH", `/${id}/{key}`, "key")],
    delete: endpoint("DELETE", `/${id}/{key}`, "key"),
  },
});

export const metadata = {
  contract: "lrg.frontend.metadata",
  version: "1.0",
  api_prefix: "/api",
  entities: [
    entity("notes", "code", [field("title", "string", "editable", { required_on_create: true }), field("content", "string"), field("published", "boolean")]),
    entity("departments", "code", [field("title", "string", "editable", { required_on_create: true })]),
    entity("projects", "code", [field("title", "string"), field("department_code", "string", "editable", { nullable: true }), field("budget", "number")]),
    entity("people", "code", [field("full_name", "string"), field("contact", "string")]),
    entity("badges", "code", [field("label", "string"), field("person_code", "string", "editable", { nullable: true })]),
    entity("articles", "code", [field("title", "string"), field("date", "date"), field("published_at", "datetime")]),
    entity("topics", "code", [field("title", "string"), field("priority", "integer"), field("state", "string", "editable", { enum: ["draft", "active"] })]),
  ],
  relations: [
    { id: "departments.projects", cardinality: "1:N", source: "departments", target: "projects", source_fields: ["code"], target_fields: ["department_code"], association_operations: {} },
    { id: "people.badges", cardinality: "1:1", source: "people", target: "badges", source_fields: ["code"], target_fields: ["person_code"], association_operations: {} },
    { id: "articles.topics", cardinality: "N:M", source: "articles", target: "topics", source_fields: ["code"], target_fields: ["code"], association_operations: {
      replace: { ...endpoint("PUT", "/articles/{key}/topics", "key"), body_field: "related_ids" },
    } },
  ],
};

function scenario(entityIds) {
  return {
    ...metadata,
    entities: metadata.entities.filter((entity) => entityIds.includes(entity.id)),
    relations: metadata.relations.filter((relation) => entityIds.includes(relation.source) && entityIds.includes(relation.target)),
  };
}

export const scenarios = {
  simple: scenario(["notes"]),
  oneToMany: scenario(["departments", "projects"]),
  oneToOne: scenario(["people", "badges"]),
  manyToMany: scenario(["articles", "topics"]),
};

export const presentation = {
  notes: { label: "Notas", fields: { content: { widget: "text" } } },
  departments: { label: "Departamentos" },
  projects: { label: "Proyectos" },
  people: { label: "Personas", fields: { contact: { widget: "email" } } },
  badges: { label: "Credenciales" },
  articles: { label: "Artículos" },
  topics: { label: "Temas" },
};

export const source = createMemorySource({
  notes: [{ code: "N-1", title: "Bienvenida", content: "Datos de demostración", published: true }],
  departments: [{ code: "D-1", title: "Diseño" }, { code: "D-2", title: "Investigación" }],
  projects: [{ code: "P-1", title: "Proyecto inicial", department_code: "D-1", budget: 120.5 }],
  people: [{ code: "PE-1", full_name: "Ana Ejemplo", contact: "ana@example.test" }],
  badges: [{ code: "B-1", label: "Principal", person_code: "PE-1" }],
  articles: [{ code: "A-1", title: "Artículo inicial", date: "2026-01-01", published_at: "2026-01-01T10:00" }],
  topics: [{ code: "T-1", title: "Diseño", priority: 1, state: "active" }, { code: "T-2", title: "Planificación", priority: 2, state: "draft" }],
}, { "articles.topics": { "A-1": ["T-1"] } });
