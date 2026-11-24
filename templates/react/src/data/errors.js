export function describeError(error) {
  const status = error?.status;
  const defaults = {
    404: "El registro ya no existe.",
    409: "La operación entra en conflicto con el estado actual.",
    422: "Revisa los datos del formulario.",
  };
  const message = error?.message || (status >= 500 ? "Error del servidor." : defaults[status]) || "No se pudo completar la operación.";
  return { message, fields: status === 422 && error?.fields && typeof error.fields === "object" ? error.fields : {} };
}
