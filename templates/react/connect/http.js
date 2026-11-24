function requestPath(prefix, endpoint, id) {
  let path = endpoint.path;
  for (const parameter of endpoint.parameters) {
    if (parameter.source !== "entity_id" || id === null || id === undefined || !["string", "number"].includes(typeof id)
      || (typeof id === "number" && !Number.isSafeInteger(id)) || String(id) === "" || [".", ".."].includes(String(id))) {
      throw new Error("Identificador de ruta inválido.");
    }
    path = path.replace(`{${parameter.name}}`, encodeURIComponent(String(id)));
  }
  return prefix + path;
}

function failure(status, body) {
  const messages = {
    404: "El registro ya no existe.",
    409: "La operación entra en conflicto con el estado actual.",
    422: "Revisa los datos del formulario.",
  };
  const error = Object.assign(new Error(messages[status] || (status >= 500 ? "Error del servidor." : "No se pudo completar la operación.")), { status });
  if (status === 422 && body?.errors && typeof body.errors === "object" && !Array.isArray(body.errors)) {
    error.fields = Object.fromEntries(Object.entries(body.errors).filter(([, values]) => Array.isArray(values) && values.every((value) => typeof value === "string")));
  }
  return error;
}

export function createHttpSource(metadata, fetcher = fetch) {
  async function request(endpoint, id, payload, responseExpected = true) {
    let response;
    try {
      response = await fetcher(requestPath(metadata.api_prefix, endpoint, id), {
        method: endpoint.method,
        credentials: "omit",
        headers: { Accept: "application/json", ...(payload === undefined ? {} : { "Content-Type": "application/json" }) },
        ...(payload === undefined ? {} : { body: JSON.stringify(payload) }),
      });
    } catch (error) {
      if (error instanceof TypeError) throw new Error("No se pudo conectar con el servidor.");
      throw error;
    }
    let text;
    try { text = await response.text(); }
    catch { throw new Error("No se pudo leer la respuesta del servidor."); }
    let body;
    if (text) {
      try { body = JSON.parse(text); }
      catch {
        if (response.ok && responseExpected) throw new Error("El servidor devolvió una respuesta JSON inválida.");
      }
    }
    if (!response.ok) throw failure(response.status, body);
    if (responseExpected && body === undefined) throw new Error("El servidor devolvió una respuesta vacía.");
    return body;
  }
  const record = (value) => {
    if (!value || typeof value !== "object" || Array.isArray(value)) throw new Error("El servidor no devolvió un registro JSON.");
    return value;
  };
  return {
    mode: "API mediante proxy de desarrollo",
    async list(entity, endpoint) {
      const value = await request(endpoint);
      if (!Array.isArray(value)) throw new Error(`La API no devolvió una lista JSON para ${entity.id}.`);
      return value;
    },
    async show(entity, id, endpoint) { return record(await request(endpoint, id)); },
    async create(entity, payload, endpoint) { return record(await request(endpoint, undefined, payload)); },
    async update(entity, id, payload, endpoints) {
      const endpoint = endpoints.find((item) => item.method === "PATCH") || endpoints[0];
      return record(await request(endpoint, id, payload));
    },
    async delete(entity, id, endpoint) { await request(endpoint, id, undefined, false); },
    async replaceLinks(relation, id, ids, endpoint) {
      await request(endpoint, id, { [endpoint.body_field]: ids });
    },
  };
}
