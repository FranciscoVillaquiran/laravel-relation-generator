export function createMemorySource(initialRecords, initialLinks = {}) {
  const records = structuredClone(initialRecords);
  const links = structuredClone(initialLinks);
  const copy = (value) => structuredClone(value);
  const rows = (entity) => {
    if (!Array.isArray(records[entity.id])) throw new Error(`No hay datos de demostración para ${entity.id}.`);
    return records[entity.id];
  };
  const find = (entity, id) => {
    const record = rows(entity).find((item) => String(item[entity.primary_key]) === String(id));
    if (!record) throw Object.assign(new Error("El registro ya no existe."), { status: 404 });
    return record;
  };
  return {
    mode: "Demostración sin backend",
    async list(entity) { return copy(rows(entity)); },
    async show(entity, id) { return copy(find(entity, id)); },
    async create(entity, payload) {
      const current = rows(entity);
      const key = entity.primary_key;
      const next = `demo-${Date.now()}-${Math.random().toString(36).slice(2)}`;
      const record = { ...payload, [key]: next };
      current.push(record);
      return copy(record);
    },
    async update(entity, id, payload) {
      const record = find(entity, id);
      Object.assign(record, payload);
      return copy(record);
    },
    async delete(entity, id) {
      find(entity, id);
      records[entity.id] = rows(entity).filter((record) => String(record[entity.primary_key]) !== String(id));
    },
    async getLinks(relation, id) { return copy(links[relation.id]?.[String(id)] || []); },
    async replaceLinks(relation, id, ids) {
      links[relation.id] ||= {};
      links[relation.id][String(id)] = copy(ids);
    },
  };
}
