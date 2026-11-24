import test from "node:test";
import assert from "node:assert/strict";
import { createHttpSource } from "../connect/http.js";

const entity = { id: "things" };
const endpoint = (method, path, parameters = []) => ({ method, path, parameters });
const one = [{ name: "key", source: "entity_id" }];

test("uses relative prefixed routes, JSON bodies, and empty 204 responses", async () => {
  const calls = [];
  const responses = [
    new Response('[{"serial":"A"}]', { status: 200 }),
    new Response('{"serial":"A"}', { status: 201 }),
    new Response('{"serial":"A"}', { status: 200 }),
    new Response(null, { status: 204 }),
  ];
  const source = createHttpSource({ api_prefix: "/v1/api" }, async (url, options) => {
    calls.push({ url, ...options });
    return responses.shift();
  });
  assert.deepEqual(await source.list(entity, endpoint("GET", "/things")), [{ serial: "A" }]);
  await source.create(entity, { name: "x" }, endpoint("POST", "/things"));
  await source.update(entity, "a b", { name: "y" }, [endpoint("PUT", "/things/{key}", one), endpoint("PATCH", "/things/{key}", one)]);
  await source.delete(entity, "A", endpoint("DELETE", "/things/{key}", one));
  assert.equal(calls[0].url, "/v1/api/things");
  assert.equal(calls[1].body, '{"name":"x"}');
  assert.equal(calls[2].url, "/v1/api/things/a%20b");
  assert.equal(calls[2].method, "PATCH");
  assert.equal(calls[3].method, "DELETE");
  assert.equal(source.getLinks, undefined);
});

test("surfaces validation and safe HTTP or connection errors", async () => {
  const responses = [
    new Response('{"message":"internal detail","errors":{"name":["Required"]}}', { status: 422 }),
    new Response('{"message":"private stack trace"}', { status: 500 }),
    new Response(null, { status: 404 }),
    new Response(null, { status: 409 }),
    new Response(null, { status: 200 }),
  ];
  const source = createHttpSource({ api_prefix: "/api" }, async () => responses.shift());
  const list = () => source.list(entity, endpoint("GET", "/things"));
  await assert.rejects(list, (error) => error.status === 422 && error.fields.name[0] === "Required");
  await assert.rejects(list, (error) => error.status === 500 && !error.message.includes("private"));
  await assert.rejects(list, { status: 404 });
  await assert.rejects(list, { status: 409 });
  await assert.rejects(list, /respuesta vacía/);
  const offline = createHttpSource({ api_prefix: "/api" }, async () => { throw new TypeError("fetch failed"); });
  await assert.rejects(() => offline.list(entity, endpoint("GET", "/things")), /conectar/);
  await assert.rejects(() => offline.show(entity, "..", endpoint("GET", "/things/{key}", one)), /Identificador/);
});

test("sends an association only to its declared endpoint", async () => {
  let call;
  const source = createHttpSource({ api_prefix: "/api" }, async (url, options) => {
    call = { url, ...options };
    return new Response("{}", { status: 200 });
  });
  await source.replaceLinks({}, 1, [7, 9], { ...endpoint("PUT", "/things/{key}/tags", one), body_field: "related_ids" });
  assert.equal(call.url, "/api/things/1/tags");
  assert.equal(call.body, '{"related_ids":[7,9]}');
});
