/**
 * Detección de bots (BotD) — cargado por pa-sec555view.js
 * Emite el evento pa-botd-result con { bot, botKind? }
 */
import { load } from './botd.esm.js';

load({ monitoring: false })
    .then(function (botd) {
        return botd.detect();
    })
    .then(function (result) {
        window.dispatchEvent(new CustomEvent('pa-botd-result', { detail: result }));
    })
    .catch(function () {
        window.dispatchEvent(new CustomEvent('pa-botd-result', { detail: { bot: false } }));
    });
