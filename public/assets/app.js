/*
 * Progressive enhancement only. Every page works with JavaScript disabled, so nothing here
 * is required for the diary to function. The Content-Security-Policy forbids inline script,
 * which is why behaviour lives in this file rather than in the templates.
 */
(function () {
    'use strict';

    // Marks the document so CSS can opt into enhanced behaviour without assuming it.
    document.documentElement.classList.add('js');

    /*
     * Waiting state for the AI-backed forms (see Diary\Http\PendingButton). Each of those
     * submissions is a page load that waits on the model provider, so several seconds pass
     * with nothing on screen having changed. The pressed button carries the wait instead:
     * a spinner, a label naming what is happening, and no second submission until the
     * first has resolved.
     */
    var PENDING_LABEL_ATTRIBUTE = 'data-pending-label';
    var SUBMITTED_ATTRIBUTE = 'data-pending';

    // Every button currently waiting, with the content it had at rest.
    var waiting = [];

    function beginWaiting(form, button) {
        var restingContent = document.createDocumentFragment();
        while (button.firstChild !== null) {
            restingContent.appendChild(button.firstChild);
        }

        var spinner = document.createElement('span');
        spinner.className = 'button__spinner';
        spinner.setAttribute('aria-hidden', 'true');

        button.appendChild(spinner);
        button.appendChild(document.createTextNode(button.getAttribute(PENDING_LABEL_ATTRIBUTE) || ''));
        button.classList.add('button--pending');
        button.setAttribute('aria-busy', 'true');
        // aria-disabled, not disabled: disabling moves focus to the body mid-submission,
        // and the submit handler below is what actually refuses the repeat press.
        button.setAttribute('aria-disabled', 'true');
        form.setAttribute(SUBMITTED_ATTRIBUTE, 'true');

        waiting.push({ form: form, button: button, restingContent: restingContent });
    }

    function stopWaiting() {
        waiting.forEach(function (entry) {
            entry.button.textContent = '';
            entry.button.appendChild(entry.restingContent);
            entry.button.classList.remove('button--pending');
            entry.button.removeAttribute('aria-busy');
            entry.button.removeAttribute('aria-disabled');
            entry.form.removeAttribute(SUBMITTED_ATTRIBUTE);
        });

        waiting = [];
    }

    // Delegated, so a button rendered anywhere on any page is covered. The submit event
    // only fires once the browser's own field validation has passed, so a form rejected
    // for a missing date never enters the waiting state.
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        var button = form.querySelector('button[' + PENDING_LABEL_ATTRIBUTE + ']');
        if (button === null) {
            return;
        }

        if (form.hasAttribute(SUBMITTED_ATTRIBUTE)) {
            event.preventDefault();
            return;
        }

        beginWaiting(form, button);
    });

    /*
     * A page restored from the back/forward cache comes back exactly as it left, which for
     * these forms means mid-submission: a spinner turning for a request that finished long
     * ago, on a button that will not accept another press. Going back to the range picker
     * after reading a summary is the ordinary way to run a second one, so reset there.
     */
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            stopWaiting();
        }
    });
})();
