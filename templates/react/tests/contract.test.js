import test from "node:test";
import assert from "node:assert/strict";
import { validateMetadata, foreignSelector } from "../src/contract.js";
import { metadata, presentation, scenarios } from "../src/demo.js";
import { addAssociationPayload, associationFieldsFor, fieldsFor, initialValues, makePayload, requiredOnCreate } from "../src/form.js";
import { createMemorySource } from "../src/data/memory.js";
import { describeError } from "../src/data/errors.js";

test("the four demonstration scenarios use the same validated contract", () => {
  assert.equal(validateMetadata(metadata), metadata);
  assert.deepEqual(metadata.relations.map((relation) => relation.cardinality), ["1:N", "1:1", "N:M"]);
  assert.equal(metadata.relations.some((relation) => relation.source === "notes" || relation.target === "notes"), false);
  assert.equal(metadata.entities.every((entity) => entity.primary_key !== "id"), true);
  for (const [name, scenario] of Object.entries(scenarios)) {
    assert.equal(validateMetadata(scenario), scenario, name);
    assert.equal(scenario.entities.length, name === "simple" ? 1 : 2);
  }
});

test("rejects unknown versions, extensions and invalid endpoint parameters", () => {
  const invalid = structuredClone(metadata);
  invalid.version = "3.0";
  assert.throws(() => validateMetadata(invalid), /no soportado/);
  invalid.version = "1.0";
  invalid.entities[0].fields[0].label = "not in contract";
  assert.throws(() => validateMetadata(invalid), /desconocida/);
  delete invalid.entities[0].fields[0].label;
  invalid.entities[0].operations.show.parameters = [];
  assert.throws(() => validateMetadata(invalid), /no coinciden/);
});

function v2Fixture() {
  const result = structuredClone(metadata);
  result.version = "2.0";
  for (const entity of result.entities) {
    entity.label = `Label ${entity.id}`;
    entity.table = entity.id;
    entity.display_field = null;
    entity.response_convention = Object.fromEntries(Object.keys(entity.operations).map((name) => [name, name === "delete" ? "no_content" : "json"]));
  }
  for (const relation of result.relations) {
    relation.name = relation.id;
    relation.inverse_name = `${relation.id}.inverse`;
    if (relation.cardinality === "N:M") {
      relation.foreign_key = null;
      relation.pivot_table = "custom_pivot";
      relation.pivot_foreign_keys = {
        source: { columns: ["source_key"], referenced_columns: relation.source_fields },
        target: { columns: ["target_key"], referenced_columns: relation.target_fields },
      };
      const source = result.entities.find((entity) => entity.id === relation.source);
      relation.association_operations.create = { endpoints: [source.operations.create], body_field: "topic_codes", semantics: "replace" };
      relation.association_operations.update = { endpoints: source.operations.update, body_field: "topic_codes", semantics: "replace" };
    } else {
      relation.foreign_key = { table: relation.target, columns: relation.target_fields, referenced_table: relation.source, referenced_columns: relation.source_fields };
      relation.pivot_table = null;
      relation.pivot_foreign_keys = null;
    }
  }
  return result;
}

test("accepts version 2 with arbitrary keys and verified relations", () => {
  const fixture = v2Fixture();
  assert.equal(validateMetadata(fixture), fixture);
  const projects = fixture.entities.find((entity) => entity.id === "projects");
  assert.equal(fieldsFor(fixture, projects).find((field) => field.name === "department_code").related.id, "departments");
  const badges = fixture.entities.find((entity) => entity.id === "badges");
  assert.equal(fieldsFor(fixture, badges).find((field) => field.name === "person_code").related.id, "people");
});

test("rejects malformed or unverified version 2 associations", () => {
  const fixture = v2Fixture();
  const relation = fixture.relations.find((item) => item.cardinality === "N:M");
  relation.association_operations.create.endpoints[0] = { ...relation.association_operations.create.endpoints[0], path: "/wrong" };
  assert.throws(() => validateMetadata(fixture), /no coincide/);
  const invalid = v2Fixture();
  invalid.relations[2].association_operations.update = null;
  assert.throws(() => validateMetadata(invalid), /objeto/);
  const badForeignKey = v2Fixture();
  badForeignKey.relations[0].foreign_key.columns = ["wrong"];
  assert.throws(() => validateMetadata(badForeignKey), /foránea inconsistente/);
});

test("respects defaults, structural requiredness and boolean values", () => {
  const fields = [
    { name: "enabled", type: "boolean", widget: "boolean", default: false, constraints: {} },
    { name: "count", type: "integer", widget: "integer", default: 0, constraints: { database_required: true } },
  ];
  assert.deepEqual(initialValues(fields), { enabled: "false", count: "0" });
  assert.deepEqual(initialValues(fields, { enabled: "false", count: 0 }), { enabled: "false", count: "0" });
  assert.deepEqual(makePayload(fields, initialValues(fields), true), { enabled: false, count: 0 });
  assert.equal(requiredOnCreate(fields[1]), true);
});

test("sends association IDs only for explicit capabilities and user changes", () => {
  const fixture = v2Fixture();
  const articles = fixture.entities.find((entity) => entity.id === "articles");
  const groups = associationFieldsFor(fixture, articles, true);
  const relation = groups[0].relation;
  const records = { topics: [{ code: 7 }, { code: "T-2" }] };
  assert.deepEqual(addAssociationPayload({}, groups, { [relation.id]: { loaded: true, touched: false, values: [] } }, records), {});
  assert.deepEqual(addAssociationPayload({}, groups, { [relation.id]: { loaded: true, touched: true, values: ["7", "T-2"] } }, records), { topic_codes: [7, "T-2"] });
  assert.throws(() => addAssociationPayload({}, groups, { [relation.id]: { loaded: false, touched: true, values: [] } }, records), /verificar/);
  assert.throws(() => addAssociationPayload({}, groups, { [relation.id]: { loaded: true, touched: true, values: ["missing"] } }, records), /disponible/);
  assert.throws(() => addAssociationPayload({}, groups, { [relation.id]: { loaded: true, touched: true, values: ["7"] } }, { topics: [{ code: 7 }, { code: "7" }] }), /verificar/);
  delete relation.association_operations.create;
  assert.deepEqual(associationFieldsFor(fixture, articles, true), []);
  assert.equal(associationFieldsFor(fixture, articles, false).length, 1);
  assert.deepEqual(associationFieldsFor(metadata, metadata.entities.find((entity) => entity.id === "articles"), false), []);
});

test("accepts a verified 1:1 replace operation without extending the contract", () => {
  const example = structuredClone(scenarios.oneToOne);
  example.relations[0].association_operations.replace = {
    method: "PATCH", path: "/people/{key}/badge",
    parameters: [{ name: "key", source: "entity_id" }], body_field: "related_ids",
  };
  assert.equal(validateMetadata(example), example);
});

test("uses relation keys for parent selectors, not entity names or free numeric fields", () => {
  const projects = metadata.entities.find((entity) => entity.id === "projects");
  const projectFields = fieldsFor(metadata, projects, presentation.projects);
  assert.equal(projectFields.find((field) => field.name === "department_code").related.id, "departments");
  const badges = metadata.entities.find((entity) => entity.id === "badges");
  assert.equal(foreignSelector(metadata, badges, badges.fields.find((field) => field.name === "person_code")).id, "people");
  const ambiguous = structuredClone(metadata);
  ambiguous.relations.push({ ...ambiguous.relations[0], id: "another-parent" });
  const blocked = fieldsFor(ambiguous, ambiguous.entities.find((entity) => entity.id === "projects"));
  assert.equal(blocked.find((field) => field.name === "department_code").widget, null);
});

test("preserves false, zero, optional fields and read-only keys in payloads", () => {
  const fields = [
    { name: "flag", type: "boolean", widget: "boolean", constraints: {} },
    { name: "count", type: "integer", widget: "integer", constraints: {} },
    { name: "optional", type: "string", widget: "string", constraints: { nullable: true } },
  ];
  assert.deepEqual(makePayload(fields, { flag: "false", count: "0", optional: "" }, true), { flag: false, count: 0 });
  const record = { flag: true, count: 2, optional: "value" };
  assert.deepEqual(makePayload(fields, { ...initialValues(fields, record), optional: "" }, false, record), { optional: null });
  assert.throws(() => makePayload(fields, { flag: "true", count: "9007199254740992", optional: "" }, true), /entero seguro/);
});

test("enforces known decimal precision, scale and unsigned constraints", () => {
  const fields = [{ name: "amount", label: "Amount", type: "number", widget: "decimal", constraints: { precision: 4, scale: 2, unsigned: true } }];
  assert.deepEqual(makePayload(fields, { amount: "12.34" }, true), { amount: "12.34" });
  assert.throws(() => makePayload(fields, { amount: "123.4" }, true), /precisión/);
  assert.throws(() => makePayload(fields, { amount: "1.234" }, true), /decimales/);
  assert.throws(() => makePayload(fields, { amount: "-1" }, true), /negativos/);
});

test("does not render sensitive or unsupported fields automatically", () => {
  const entity = { id: "example", primary_key: "key", fields: [
    { name: "key", type: "string", access: "read_only", constraints: {} },
    { name: "api_token", type: "string", access: "editable", constraints: {} },
    { name: "data", type: "unknown", access: "editable", constraints: {} },
  ] };
  const fields = fieldsFor({ relations: [], entities: [entity] }, entity);
  assert.deepEqual(fields.map((field) => field.name), ["data"]);
  assert.equal(fields[0].widget, null);
});

test("demo CRUD and replace associations use configured primary keys and preserve copies", async () => {
  const articles = metadata.entities.find((entity) => entity.id === "articles");
  const relation = metadata.relations.find((item) => item.id === "articles.topics");
  const source = createMemorySource({ articles: [{ code: "X", title: "Original" }] }, { "articles.topics": { X: ["A"] } });
  const list = await source.list(articles);
  list[0].title = "Mutated externally";
  assert.equal((await source.show(articles, "X")).title, "Original");
  await source.update(articles, "X", { title: "Updated" });
  assert.equal((await source.show(articles, "X")).title, "Updated");
  assert.deepEqual(await source.getLinks(relation, "X"), ["A"]);
  await source.replaceLinks(relation, "X", []);
  assert.deepEqual(await source.getLinks(relation, "X"), []);
  const created = await source.create(articles, { title: "New" });
  assert.ok(created.code);
  await source.delete(articles, created.code);
  await assert.rejects(() => source.show(articles, created.code), { status: 404 });
});

test("normalizes validation, conflict, missing, server and connection errors", () => {
  assert.deepEqual(describeError({ status: 422, fields: { title: ["Requerido"] } }).fields, { title: ["Requerido"] });
  for (const status of [404, 409, 422, 500]) assert.ok(describeError({ status }).message);
  assert.ok(describeError(new Error("Sin conexión")).message.includes("Sin conexión"));
});
