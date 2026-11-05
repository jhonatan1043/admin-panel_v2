const MENSAJE_ERROR_CONEXION = "No se pudo conectar. Inténtalo de nuevo";

function mostrarMensaje(elemento, mensaje) {
  elemento.textContent = mensaje;
  elemento.hidden = !mensaje;
}

// Bloquea el formulario mientras dura la tarea y lo restaura aunque esta falle.
async function conCarga(formulario, boton, textoCarga, tarea) {
  const textoOriginal = boton.textContent;
  const cambiarEstado = (ocupado, texto) => {
    for (const elemento of formulario.elements) {
      elemento.disabled = ocupado;
    }
    boton.textContent = texto;
    boton.setAttribute("aria-busy", String(ocupado));
  };

  cambiarEstado(true, textoCarga);
  try {
    return await tarea();
  } finally {
    cambiarEstado(false, textoOriginal);
  }
}
