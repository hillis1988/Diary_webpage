/*
 * Progressive enhancement only. Every page works with JavaScript disabled, so nothing here
 * is required for the diary to function. The Content-Security-Policy forbids inline script,
 * which is why behaviour lives in this file rather than in the templates.
 */
(function () {
    'use strict';

    // Marks the document so CSS can opt into enhanced behaviour without assuming it.
    document.documentElement.classList.add('js');
})();
