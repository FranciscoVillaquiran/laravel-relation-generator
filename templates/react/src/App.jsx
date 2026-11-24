import { useCallback, useEffect, useMemo, useState } from "react";
import { validateMetadata, sensitiveField } from "./contract.js";
import { addAssociationPayload, associationFieldsFor, fieldsFor, initialValues, label, makePayload, requiredOnCreate, validAssociationOptions } from "./form.js";
import { describeError } from "./data/errors.js";
import { metadata as demoMetadata, presentation as demoPresentation, source as demoSource } from "./demo.js";

const unavailable = async () => { throw new Error("No hay una fuente de datos conectada."); };
const emptySource = { mode: "Sin conexión", list: unavailable, show: unavailable, create: unavailable, update: unavailable, delete: unavailable };

function display(value) {
  if (value === null || value === undefined || value === "") return "—";
  if (typeof value === "boolean") return value ? "Sí" : "No";
  return typeof value === "object" ? JSON.stringify(value) : String(value);
}

function recordLabel(entity, record) {
  if ("display_field" in entity) return display((entity.display_field && record[entity.display_field]) ?? record[entity.primary_key]);
  const field = entity.fields.find((item) => item.name !== entity.primary_key && item.type === "string" && !sensitiveField(item.name));
  return display((field && record[field.name]) ?? record[entity.primary_key]);
}

function Modal({ title, children, onClose, busy }) {
  return <div className="modal-backdrop" onMouseDown={(event) => { if (event.target === event.currentTarget && !busy) onClose(); }}>
    <section className="modal" role="dialog" aria-modal="true" aria-labelledby="modal-title">
      <div className="modal-heading"><div><div className="eyebrow">REGISTRO</div><h2 id="modal-title">{title}</h2></div>
        <button className="icon-button modal-close" onClick={onClose} aria-label="Cerrar" disabled={busy}>×</button></div>
      {children}
    </section>
  </div>;
}

function Field({ field, value, onChange, records, creating, errors }) {
  const options = field.related
    ? (records[field.related.id] || []).map((item) => ({ value: String(item[field.related.primary_key]), label: recordLabel(field.related, item) }))
    : (field.constraints.enum || []).map((item) => ({ value: item, label: item }));
  const required = creating && requiredOnCreate(field);
  const disabled = Boolean(field.related && (!field.related.operations.list || !Array.isArray(records[field.related.id])));
  const common = { name: field.name, value, onChange: (event) => onChange(event.target.value), required, disabled };
  const inputType = { string: "text", integer: "number", decimal: "number", date: "date", datetime: "datetime-local", email: "email" }[field.widget];
  return <label className="form-field">
    <span className="field-label">{field.label}{required && <i> *</i>}</span>
    {!field.widget ? <span className="field-hint">Tipo no editable automáticamente.</span>
      : field.widget === "select" ? <select {...common}>
        <option value="">Selecciona una opción</option>
        {options.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
      </select>
        : field.widget === "boolean" ? <select {...common}><option value="">Sin especificar</option><option value="true">Sí</option><option value="false">No</option></select>
          : field.widget === "text" ? <textarea {...common} maxLength={field.constraints.max_length} />
            : <input {...common} type={inputType} step={field.widget === "integer" ? "1" : field.widget === "decimal" ? "any" : undefined} min={field.constraints.unsigned ? "0" : undefined} maxLength={field.constraints.max_length} autoComplete="off" />}
    {disabled && <span className="field-hint">No hay opciones verificadas disponibles.</span>}
    {errors && <span className="field-error" role="alert">{Array.isArray(errors) ? errors.join(" ") : String(errors)}</span>}
  </label>;
}

function RecordForm({ metadata, entity, presentation, record, records, source, onClose, onSave, busy, error }) {
  const fields = useMemo(() => fieldsFor(metadata, entity, presentation), [metadata, entity, presentation]);
  const associations = useMemo(() => associationFieldsFor(metadata, entity, !record), [metadata, entity, record]);
  const [values, setValues] = useState(() => initialValues(fields, record));
  const [links, setLinks] = useState(() => Object.fromEntries(associations.map(({ relation }) => [relation.id, { values: [], loaded: !record, touched: false }])));
  const [localError, setLocalError] = useState("");
  useEffect(() => {
    if (!record || typeof source.getLinks !== "function") return;
    let active = true;
    for (const { relation } of associations) {
      Promise.resolve().then(() => source.getLinks(relation, record[entity.primary_key])).then((ids) => {
        if (!Array.isArray(ids) || ids.some((id) => !((typeof id === "string" && id.length > 0) || (typeof id === "number" && Number.isSafeInteger(id)))) || new Set(ids.map(String)).size !== ids.length) {
          throw new Error("La fuente no devolvió identificadores de asociación válidos.");
        }
        if (active) setLinks((current) => ({ ...current, [relation.id]: { values: ids.map(String), loaded: true, touched: false } }));
      }).catch((issue) => {
        if (active) setLinks((current) => ({ ...current, [relation.id]: { values: [], loaded: false, touched: false, error: describeError(issue).message } }));
      });
    }
    return () => { active = false; };
  }, [associations, entity, record, source]);
  function submit(event) {
    event.preventDefault();
    try {
      const available = fields.filter((field) => !field.related || (field.related.operations.list && Array.isArray(records[field.related.id])));
      if (!record && fields.some((field) => (!available.includes(field) || !field.widget) && requiredOnCreate(field))) {
        throw new Error("No se pueden cargar las opciones de un campo obligatorio.");
      }
      const payload = makePayload(available, values, !record, record);
      addAssociationPayload(payload, associations, links, records);
      setLocalError("");
      onSave(payload);
    } catch (issue) { setLocalError(issue.message); }
  }
  return <Modal title={`${record ? "Editar" : "Crear"} ${presentation.label || entity.label || label(entity.id)}`} onClose={onClose} busy={busy}>
    <form onSubmit={submit}>
      {(localError || error?.message) && <p className="form-error" role="alert">{localError || error.message}</p>}
      <div className="form-fields">{fields.map((field) => <Field key={field.name} field={field} value={values[field.name] ?? ""} onChange={(value) => setValues((current) => ({ ...current, [field.name]: value }))} records={records} creating={!record} errors={error?.fields?.[field.name]} />)}</div>
      {associations.map(({ relation, target }) => {
        const selection = links[relation.id];
        const options = records[target.id] || [];
        const available = Boolean(selection?.loaded && target.operations.list && validAssociationOptions(target, records[target.id]));
        const missing = selection?.values.some((value) => !options.some((item) => String(item[target.primary_key]) === value));
        return <div className="form-field association-group" key={relation.id}>
          <span className="field-label">{target.label || label(target.id)}</span>
          {available && <div className="checkbox-options">{options.map((item) => {
            const value = String(item[target.primary_key]);
            return <label className="checkbox-option" key={value}><input type="checkbox" checked={selection.values.includes(value)} disabled={busy || missing} onChange={(event) => setLinks((current) => ({
              ...current, [relation.id]: { ...current[relation.id], touched: true, values: event.target.checked ? [...selection.values, value] : selection.values.filter((entry) => entry !== value) },
            }))} />{recordLabel(target, item)}</label>;
          })}</div>}
          {!available && <span className="field-hint">{selection?.error || "Asociación en solo lectura: faltan datos verificables."}</span>}
          {missing && <span className="field-hint">Hay asociaciones sin datos disponibles; no se puede reemplazar la lista.</span>}
          {available && !options.length && <span className="field-hint">No hay opciones disponibles.</span>}
        </div>;
      })}
      <div className="modal-actions"><button className="secondary-button" type="button" onClick={onClose} disabled={busy}>Cancelar</button><button className="primary-button" disabled={busy}>{busy ? "Guardando..." : "Guardar"}</button></div>
    </form>
  </Modal>;
}

function Associations({ metadata, entity, record, records, source, onClose }) {
  const relations = metadata.relations.filter((relation) => relation.source === entity.id && (relation.cardinality === "N:M" || relation.association_operations.replace));
  const [selected, setSelected] = useState({});
  const [loaded, setLoaded] = useState({});
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(true);
  const id = record[entity.primary_key];
  useEffect(() => {
    let active = true;
    async function load() {
      try {
        const values = {};
        for (const relation of relations) {
          if (typeof source.getLinks === "function") {
            const ids = await source.getLinks(relation, id);
            if (!Array.isArray(ids) || ids.some((value) => !((typeof value === "string" && value.length > 0) || (typeof value === "number" && Number.isSafeInteger(value)))) || new Set(ids.map(String)).size !== ids.length) throw new Error("La fuente no devolvió identificadores de asociación válidos.");
            values[relation.id] = ids.map(String);
          }
        }
        if (active) { setSelected(values); setLoaded(Object.fromEntries(Object.keys(values).map((key) => [key, true]))); }
      } catch (issue) { if (active) setError(describeError(issue).message); }
      finally { if (active) setBusy(false); }
    }
    load();
    return () => { active = false; };
  }, [metadata, entity, id, source]);
  async function save(relation, target) {
    setBusy(true); setError("");
    try {
      const ids = selected[relation.id].map((idValue) => {
        const found = (records[target.id] || []).find((item) => String(item[target.primary_key]) === idValue);
        if (!found) throw new Error("Una asociación seleccionada ya no está disponible; no se guardaron cambios.");
        return found[target.primary_key];
      });
      await source.replaceLinks(relation, id, ids, relation.association_operations.replace);
      onClose();
    } catch (issue) { setError(describeError(issue).message); }
    finally { setBusy(false); }
  }
  return <Modal title="Asociaciones" onClose={onClose} busy={busy}>
    {error && <p className="form-error" role="alert">{error}</p>}
    {relations.map((relation) => {
      const target = metadata.entities.find((item) => item.id === relation.target);
      const options = records[target.id] || [];
      const values = selected[relation.id] || [];
      const canEdit = Boolean(relation.association_operations.replace && loaded[relation.id] && target.operations.list && validAssociationOptions(target, records[target.id]) && typeof source.replaceLinks === "function");
      const missing = values.some((value) => !options.some((item) => String(item[target.primary_key]) === value)) || (relation.cardinality === "1:1" && values.length > 1);
      return <div className="form-field association-group" key={relation.id}>
        <span className="field-label">{label(target.id)}</span>
        {loaded[relation.id] && <div className="checkbox-options">{options.length ? options.map((item) => {
          const value = String(item[target.primary_key]);
          return <label className="checkbox-option" key={value}><input type="checkbox" checked={values.includes(value)} disabled={!canEdit || missing || busy} onChange={(event) => setSelected((current) => ({ ...current, [relation.id]: event.target.checked ? relation.cardinality === "1:1" ? [value] : [...values, value] : values.filter((entry) => entry !== value) }))} />{recordLabel(target, item)}</label>;
        }) : <span className="field-hint">No hay opciones disponibles.</span>}</div>}
        {!canEdit && <span className="field-hint">Asociación en solo lectura: falta una operación o lectura verificable.</span>}
        {missing && <span className="field-hint">Hay asociaciones sin datos disponibles; no se puede reemplazar la lista.</span>}
        <button className="secondary-button" disabled={!canEdit || missing || busy} onClick={() => save(relation, target)}>Guardar asociaciones</button>
      </div>;
    })}
    {!relations.length && <p className="field-hint">No hay asociaciones editables desde esta entidad.</p>}
  </Modal>;
}

function Dashboard({ metadata, presentation, source }) {
  const [records, setRecords] = useState({});
  const [active, setActive] = useState(metadata.entities[0]?.id || "");
  const [search, setSearch] = useState("");
  const [dialog, setDialog] = useState(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const [notice, setNotice] = useState("");
  const entity = metadata.entities.find((item) => item.id === active);
  const displayName = (item) => presentation[item.id]?.label || item.label || label(item.id);
  const refresh = useCallback(async () => {
    const result = {};
    for (const item of metadata.entities) {
      if (!item.operations.list) continue;
      const rows = await source.list(item, item.operations.list);
      if (!Array.isArray(rows)) throw new Error(`La fuente debe devolver una lista normalizada para ${item.id}.`);
      const ids = new Set();
      for (const record of rows) {
        const id = record?.[item.primary_key];
        if (!record || typeof record !== "object" || Array.isArray(record) || id === null || id === undefined || ids.has(String(id))) throw new Error(`La fuente devolvió identificadores inválidos o duplicados para ${item.id}.`);
        ids.add(String(id));
      }
      result[item.id] = rows;
    }
    setRecords(result);
  }, [metadata, source]);
  useEffect(() => {
    let activeRequest = true;
    refresh().catch((issue) => { if (activeRequest) setError(describeError(issue)); }).finally(() => { if (activeRequest) setLoading(false); });
    return () => { activeRequest = false; };
  }, [refresh]);
  const current = records[active] || [];
  const visibleFields = entity?.fields.filter((field) => !sensitiveField(field.name)) || [];
  const filtered = current.filter((record) => visibleFields.some((field) => display(record[field.name]).toLocaleLowerCase().includes(search.trim().toLocaleLowerCase())));
  async function perform(action, success) {
    setBusy(true); setError(null);
    try {
      await action();
      setDialog(null);
      setNotice(success);
      try { await refresh(); }
      catch (issue) { setError({ message: `La operación se realizó, pero no se pudo actualizar la lista: ${describeError(issue).message}`, fields: {} }); }
    }
    catch (issue) { setError(describeError(issue)); }
    finally { setBusy(false); }
  }
  async function show(record) {
    setBusy(true); setError(null);
    try { setDialog({ kind: "show", record: await source.show(entity, record[entity.primary_key], entity.operations.show) }); }
    catch (issue) { setError(describeError(issue)); }
    finally { setBusy(false); }
  }
  const navigate = (id) => { setActive(id); setSearch(""); setError(null); setNotice(""); };
  const associationAvailable = entity && metadata.relations.some((relation) => relation.source === entity.id && (relation.cardinality === "N:M" || relation.association_operations.replace));
  return <div className="app-shell">
    <aside className="sidebar">
      <a className="brand" href="#" aria-label="Repaso Examen, inicio"><span className="brand-mark">R</span><span className="brand-copy"><strong>repaso<span>examen</span></strong><small>GESTIÓN ACADÉMICA</small></span></a>
      <div className="sidebar-label">ESPACIO DE TRABAJO</div>
      <nav className="navigation" aria-label="Secciones">{metadata.entities.map((item) => <button className={`nav-item ${active === item.id ? "active" : ""}`} key={item.id} aria-label={displayName(item)} title={displayName(item)} onClick={() => navigate(item.id)}><span className="nav-icon">{displayName(item).slice(0, 2).toLocaleUpperCase()}</span><span>{displayName(item)}</span><span className="nav-count">{records[item.id]?.length ?? "—"}</span></button>)}</nav>
      <div className="sidebar-bottom"><div className="connection-indicator"><span /> {source.mode}</div><p>Administra tu información desde un solo lugar.</p></div>
    </aside>
    <main className="main-content"><header className="topbar"><div className="breadcrumb">Panel de control <span>/</span> {entity ? displayName(entity) : "Sin entidades"}</div><div className="topbar-right"><span className="today-label">{new Intl.DateTimeFormat("es", { weekday: "long", day: "numeric", month: "long" }).format(new Date())}</span><span className="avatar">RE</span></div></header>
      <div className="page-content">
        <section className="welcome-row"><div><div className="eyebrow">ADMINISTRACIÓN · {source.mode}</div><h1>Tu espacio académico<span>.</span></h1><p>Organiza y mantén al día la información de tu institución.</p></div><div className="welcome-art" aria-hidden="true"><span className="art-circle art-circle-one" /><span className="art-circle art-circle-two" /><span className="art-spark">✳</span><span className="art-book">Aa</span></div></section>
        <section className="stats-grid" aria-label="Resumen">{metadata.entities.map((item, index) => <button className={`stat-card ${active === item.id ? "selected" : ""}`} key={item.id} onClick={() => navigate(item.id)}><span className={`stat-icon stat-icon-${index % 3}`}>{displayName(item).slice(0, 2).toLocaleUpperCase()}</span><span className="stat-copy"><span>{displayName(item)}</span><strong>{records[item.id]?.length ?? "—"}</strong></span><span className="stat-arrow" aria-hidden="true">↗</span></button>)}</section>
        <section className="data-panel">
          <div className="panel-heading"><div><div className="eyebrow">DIRECTORIO</div><h2>{entity ? displayName(entity) : "Sin entidades"}<span className="result-count">{current.length}</span></h2><p>Consulta, busca y administra los registros.</p></div>{entity?.operations.create && <button className="primary-button" onClick={() => { setError(null); setDialog({ kind: "create" }); }} disabled={loading || busy}><span className="plus">+</span> Nuevo registro</button>}</div>
          {error && !dialog && <div className="alert" role="alert"><span>!</span>{error.message}<button onClick={() => setError(null)} aria-label="Cerrar">×</button></div>}
          <div className="table-toolbar"><label className="search-box"><span className="search-icon" aria-hidden="true">⌕</span><input type="search" placeholder="Buscar registros..." value={search} onChange={(event) => setSearch(event.target.value)} /><kbd>⌘ K</kbd></label><div className="table-meta"><span className="live-dot" /> {source.mode}</div></div>
          <div className="table-wrap"><table><thead><tr>{visibleFields.map((field) => <th key={field.name}>{(presentation[active]?.fields?.[field.name]?.label || label(field.name)).toLocaleUpperCase()}</th>)}<th className="actions-heading">ACCIONES</th></tr></thead><tbody>
            {loading ? <tr><td className="table-message" colSpan={visibleFields.length + 1}>Cargando registros...</td></tr>
              : !entity?.operations.list ? <tr><td className="table-message" colSpan={visibleFields.length + 1}>No hay una operación de listado disponible.</td></tr>
                : !filtered.length ? <tr><td className="table-message" colSpan={visibleFields.length + 1}><span className="empty-icon">⌕</span><strong>{search ? "No encontramos resultados" : "Aún no hay registros"}</strong><span>Los datos aparecerán aquí.</span></td></tr>
                  : filtered.map((record, index) => <tr key={String(record[entity.primary_key])}>{visibleFields.map((field, fieldIndex) => <td key={field.name}>{fieldIndex === 0 ? <div className="identity-cell"><span className={`row-avatar avatar-color-${index % 4}`}>{recordLabel(entity, record).slice(0, 2).toLocaleUpperCase()}</span><span><strong>{display(record[field.name])}</strong></span></div> : <span className="plain-value">{display(record[field.name])}</span>}</td>)}<td><div className="row-actions">
                    {entity.operations.show && <button className="icon-button" title="Consultar" aria-label={`Consultar ${recordLabel(entity, record)}`} disabled={busy} onClick={() => show(record)}>◉</button>}
                    {entity.operations.update && <button className="icon-button" title="Editar" aria-label={`Editar ${recordLabel(entity, record)}`} disabled={busy} onClick={() => { setError(null); setDialog({ kind: "edit", record }); }}>↗</button>}
                    {associationAvailable && <button className="icon-button" title="Asociaciones" aria-label={`Asociaciones ${recordLabel(entity, record)}`} disabled={busy} onClick={() => setDialog({ kind: "associations", record })}>≡</button>}
                    {entity.operations.delete && <button className="icon-button delete-button" title="Eliminar" aria-label={`Eliminar ${recordLabel(entity, record)}`} disabled={busy} onClick={() => { if (window.confirm(`¿Eliminar "${recordLabel(entity, record)}"? Esta acción no se puede deshacer.`)) perform(() => source.delete(entity, record[entity.primary_key], entity.operations.delete), "Registro eliminado."); }}>×</button>}
                  </div></td></tr>)}
          </tbody></table></div><div className="panel-footer"><span>Mostrando <strong>{filtered.length}</strong> de <strong>{current.length}</strong> registros</span><span>Repaso Examen <i>·</i> Panel académico</span></div>
        </section><footer className="page-footer"><span>Hecho para aprender mejor.</span><span>REPASO EXAMEN <b>©</b> {new Date().getFullYear()}</span></footer>
      </div>
    </main>
    {notice && <div className="toast" role="status"><span>✓</span>{notice}<button className="toast-close" onClick={() => setNotice("")} aria-label="Cerrar aviso">×</button></div>}
    {dialog && entity && (dialog.kind === "create" || dialog.kind === "edit") && <RecordForm key={`${entity.id}-${dialog.kind}`} metadata={metadata} entity={entity} presentation={presentation[entity.id] || {}} record={dialog.record} records={records} source={source} busy={busy} error={error} onClose={() => setDialog(null)} onSave={(payload) => perform(() => dialog.record ? source.update(entity, dialog.record[entity.primary_key], payload, entity.operations.update) : source.create(entity, payload, entity.operations.create), "Registro guardado.")} />}
    {dialog?.kind === "show" && <Modal title="Detalle del registro" onClose={() => setDialog(null)}><div className="form-fields">{visibleFields.map((field) => <div className="form-field" key={field.name}><span className="field-label">{label(field.name)}</span><span>{display(dialog.record[field.name])}</span></div>)}</div></Modal>}
    {dialog?.kind === "associations" && <Associations metadata={metadata} entity={entity} record={dialog.record} records={records} source={source} onClose={() => setDialog(null)} />}
  </div>;
}

export default function App({ metadata = demoMetadata, presentation, source }) {
  let issue = null;
  try { validateMetadata(metadata); } catch (error) { issue = error; }
  if (issue) return <div className="page-content"><div className="alert" role="alert"><span>!</span>Contrato inválido: {issue.message}</div></div>;
  const isDemo = metadata === demoMetadata;
  return <Dashboard key={JSON.stringify(metadata)} metadata={metadata} presentation={presentation ?? (isDemo ? demoPresentation : {})} source={source ?? (isDemo ? demoSource : emptySource)} />;
}
