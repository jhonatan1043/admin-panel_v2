// Servidor local de desarrollo: node servidor.js
const http = require("http");
const fs = require("fs");
const path = require("path");

const RAIZ = __dirname;
const HOSTNAME = process.env.APP_HOSTNAME || "127.0.0.1";
const HOSTNAME_API_CLIENTES = "127.0.0.1";
const PUERTO_API_CLIENTES = 8000;
const PUERTO_SERVIDOR = Number(process.env.PORT || 3000);

const TIPOS = {
  ".html": "text/html; charset=utf-8",
  ".css": "text/css; charset=utf-8",
  ".js": "text/javascript; charset=utf-8",
};

function responderTexto(res, estado, texto) {
  res.writeHead(estado, { "Content-Type": "text/plain; charset=utf-8" });
  res.end(texto);
}

function enviarArchivo(res, archivo) {
  fs.readFile(archivo, (error, datos) => {
    if (error) {
      responderTexto(res, 404, "Página no encontrada");
      return;
    }
    const tipo = TIPOS[path.extname(archivo)] || "application/octet-stream";
    res.writeHead(200, { "Content-Type": tipo });
    res.end(datos);
  });
}

function responderJson(res, estado, contenido) {
  res.writeHead(estado, { "Content-Type": "application/json; charset=utf-8" });
  res.end(JSON.stringify(contenido));
}

function leerJson(req) {
  return new Promise((resolve, reject) => {
    let cuerpo = "";
    let demasiadoGrande = false;
    req.setEncoding("utf8");
    req.on("data", (fragmento) => {
      if (demasiadoGrande) return;
      if (Buffer.byteLength(cuerpo) + Buffer.byteLength(fragmento) > 10240) {
        demasiadoGrande = true;
      } else {
        cuerpo += fragmento;
      }
    });
    req.on("end", () => {
      if (demasiadoGrande) {
        reject(new Error("La solicitud es demasiado grande"));
        return;
      }
      try {
        resolve(JSON.parse(cuerpo));
      } catch {
        reject(new Error("El cuerpo JSON no es válido"));
      }
    });
    req.on("error", reject);
  });
}

function enviarClienteAlServicio(datos, res) {
  const ahora = new Date();
  const dosDigitos = (numero) => String(numero).padStart(2, "0");
  const cuerpo = JSON.stringify({
    empresa: "Trico",
    fecha: `${ahora.getFullYear()}-${dosDigitos(ahora.getMonth() + 1)}-${dosDigitos(ahora.getDate())} ${dosDigitos(ahora.getHours())}:${dosDigitos(ahora.getMinutes())}`,
    name: datos.usuario,
    data: datos,
  });

  const solicitud = http.request(
    {
      hostname: HOSTNAME_API_CLIENTES,
      port: PUERTO_API_CLIENTES,
      path: "/api/clients",
      method: "POST",
      headers: {
        Accept: "*/*",
        "Content-Type": "application/json",
        "Content-Length": Buffer.byteLength(cuerpo),
      },
    },
    (respuesta) => {
      let resultado = "";
      respuesta.setEncoding("utf8");
      respuesta.on("data", (fragmento) => {
        resultado += fragmento;
      });
      respuesta.on("end", () => {
        const estado = respuesta.statusCode || 502;
        res.writeHead(estado, {
          "Content-Type": respuesta.headers["content-type"] || "application/json; charset=utf-8",
        });
        res.end(resultado);
      });
    }
  );

  solicitud.setTimeout(10000, () => {
    solicitud.destroy(new Error("Tiempo de espera agotado al conectar con el servicio"));
  });
  solicitud.on("error", () => {
    responderJson(res, 502, { error: "No se pudo conectar con el servicio de clientes" });
  });
  solicitud.end(cuerpo);
}

function consultarApi(req, res, ruta, mensajeError) {
  const query = new URL(req.url, `http://${HOSTNAME}`).search;
  const solicitud = http.request(
    {
      hostname: HOSTNAME_API_CLIENTES,
      port: PUERTO_API_CLIENTES,
      path: `${ruta}${query}`,
      method: "GET",
      headers: {
        Accept: req.headers.accept || "application/json",
        ...(req.headers.cookie ? { Cookie: req.headers.cookie } : {}),
      },
    },
    (respuesta) => {
      res.writeHead(respuesta.statusCode || 502, {
        "Content-Type": respuesta.headers["content-type"] || "application/json; charset=utf-8",
        "Cache-Control": "no-store",
      });
      respuesta.pipe(res);
    }
  );

  solicitud.setTimeout(10000, () => {
    solicitud.destroy(new Error(`Tiempo de espera agotado. ${mensajeError}`));
  });
  solicitud.on("error", (error) => {
    console.error(`${mensajeError}:`, error.message);
    if (!res.headersSent && !res.destroyed) {
      responderJson(res, 502, { error: mensajeError });
    } else if (!res.destroyed) {
      res.destroy(error);
    }
  });
  solicitud.end();
}

function reenviarPostJson(req, res, ruta, mensajeError) {
  leerJson(req)
    .then((datos) => {
      const cuerpo = JSON.stringify(datos);
      const solicitud = http.request(
        {
          hostname: HOSTNAME_API_CLIENTES,
          port: PUERTO_API_CLIENTES,
          path: ruta,
          method: "POST",
          headers: {
            Accept: "application/json",
            "Content-Type": "application/json",
            "Content-Length": Buffer.byteLength(cuerpo),
          },
        },
        (respuesta) => {
          res.writeHead(respuesta.statusCode || 502, {
            "Content-Type": respuesta.headers["content-type"] || "application/json; charset=utf-8",
            "Cache-Control": "no-store",
          });
          respuesta.pipe(res);
        }
      );

      solicitud.setTimeout(10000, () => {
        solicitud.destroy(new Error(`Tiempo de espera agotado. ${mensajeError}`));
      });
      solicitud.on("error", (error) => {
        console.error(`${mensajeError}:`, error.message);
        if (!res.headersSent && !res.destroyed) {
          responderJson(res, 502, { error: mensajeError });
        } else if (!res.destroyed) {
          res.destroy(error);
        }
      });
      solicitud.end(cuerpo);
    })
    .catch((error) => {
      responderJson(res, 400, { error: error.message });
    });
}

const servidor = http.createServer((req, res) => {
  const { pathname } = new URL(req.url, `http://${HOSTNAME}`);

  if (pathname === "/api/auth/status") {
    if (req.method !== "GET") {
      responderJson(res, 405, { error: "Método no permitido" });
      return;
    }
    consultarApi(req, res, "/api/auth/status", "No se pudo consultar el estado de autorización");
    return;
  }

  if (pathname === "/api/buttons") {
    if (req.method !== "GET") {
      responderJson(res, 405, { error: "Método no permitido" });
      return;
    }
    consultarApi(req, res, "/api/buttons", "No se pudo consultar el catálogo de botones");
    return;
  }

  if (pathname === "/api/telegram/latest-message") {
    if (req.method !== "GET") {
      responderJson(res, 405, { error: "Método no permitido" });
      return;
    }
    consultarApi(req, res, "/api/telegram/latest-message", "No se pudo consultar el último mensaje de Telegram");
    return;
  }

  if (
    pathname === "/api/telegram/use-message" ||
    pathname === "/api/telegram/send-test-code" ||
    pathname === "/api/telegram/login-notification"
  ) {
    if (req.method !== "POST") {
      responderJson(res, 405, { error: "Método no permitido" });
      return;
    }
    const mensajeError = pathname.endsWith("send-test-code")
      ? "No se pudo enviar el código al cliente"
      : pathname.endsWith("login-notification")
        ? "No se pudo enviar la notificación de inicio de sesión"
        : "No se pudo marcar el mensaje de Telegram como usado";
    reenviarPostJson(req, res, pathname, mensajeError);
    return;
  }

  if (pathname === "/api/clients") {
    if (req.method !== "POST") {
      responderJson(res, 405, { error: "Método no permitido" });
      return;
    }
    leerJson(req)
      .then((entrada) => {
        const usuario = typeof entrada.usuario === "string" ? entrada.usuario.trim() : "";
        if (!usuario || usuario.length > 100) {
          responderJson(res, 400, { error: "El usuario no es válido" });
          return;
        }
        if (
          entrada.data !== undefined &&
          (!entrada.data || typeof entrada.data !== "object" || Array.isArray(entrada.data))
        ) {
          responderJson(res, 400, { error: "Los datos adicionales no son válidos" });
          return;
        }
        const datos = { ...(entrada.data || {}), usuario };
        enviarClienteAlServicio({ usuario, data: datos }, res);
      })
      .catch((error) => {
        if (!res.headersSent && !res.destroyed) {
          responderJson(res, 400, { error: error.message });
        }
      });
    return;
  }

  let archivo;
  try {
    archivo = path.join(RAIZ, decodeURIComponent(pathname));
  } catch {
    responderTexto(res, 400, "Ruta no válida");
    return;
  }
  if (archivo !== RAIZ && !archivo.startsWith(RAIZ + path.sep)) {
    responderTexto(res, 403, "Acceso denegado");
    return;
  }

  fs.stat(archivo, (error, datos) => {
    if (error || !datos.isDirectory()) {
      enviarArchivo(res, archivo);
    } else if (pathname.endsWith("/")) {
      const indexHtml = path.join(archivo, "index.html");
      fs.stat(indexHtml, (indexError, indexDatos) => {
        if (!indexError && indexDatos.isFile()) {
          enviarArchivo(res, indexHtml);
        } else {
          enviarArchivo(res, path.join(archivo, "logo.html"));
        }
      });
    } else {
      // Con la barra final, las rutas relativas de cada vista se resuelven bien.
      res.writeHead(301, { Location: `${pathname}/` });
      res.end();
    }
  });
});

servidor.on("error", (error) => {
  if (error.code === "EADDRINUSE") {
    console.error(`El puerto ${PUERTO_SERVIDOR} ya está ocupado. Configura PORT con otro puerto disponible.`);
  } else {
    console.error("No se pudo iniciar el servidor:", error.message);
  }
  process.exitCode = 1;
});

servidor.listen(PUERTO_SERVIDOR, HOSTNAME, () => {
  console.log(`Servidor en http://${HOSTNAME}:${PUERTO_SERVIDOR}`);
});
