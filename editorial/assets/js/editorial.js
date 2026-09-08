/**
 * Editorial V2 — JavaScript.
 *
 * Minimal JS for Phase 1.
 * Handles mobile sidebar toggle (same pattern as admin.js).
 */
(function () {
    'use strict';

    // Mobile sidebar toggle
    var sidebar = document.querySelector('.admin-sidebar');
    if (sidebar) {
        var brand = sidebar.querySelector('.admin-brand');
        if (brand && window.innerWidth <= 900) {
            brand.addEventListener('click', function (e) {
                if (e.target.closest('a')) return;
                sidebar.classList.toggle('is-open');
            });
        }
    }

    // Auto-dismiss flash messages after 6 seconds
    var flashes = document.querySelectorAll('.flash');
    flashes.forEach(function (el) {
        setTimeout(function () {
            el.style.transition = 'opacity 0.4s ease';
            el.style.opacity = '0';
            setTimeout(function () {
                el.remove();
            }, 400);
        }, 6000);
    });

    // Shared read-only dialog for return feedback. Note sources are escaped
    // template nodes; copy only textContent so Admin feedback cannot render HTML.
    var returnFeedbackDialog = document.getElementById('editorialReturnFeedbackDialog');
    var returnFeedbackBody = document.getElementById('editorialReturnFeedbackDialogBody');
    var returnFeedbackLastTrigger = null;
    var returnFeedbackFallbackOpen = false;

    function closeReturnFeedbackDialog() {
        if (!returnFeedbackDialog) return;
        if (typeof returnFeedbackDialog.close === 'function') {
            returnFeedbackDialog.close();
        } else {
            returnFeedbackDialog.removeAttribute('open');
            returnFeedbackFallbackOpen = false;
            if (returnFeedbackLastTrigger) returnFeedbackLastTrigger.focus();
        }
    }

    function openReturnFeedbackDialog(trigger) {
        if (!returnFeedbackDialog || !returnFeedbackBody) return;
        var sourceId = trigger.getAttribute('data-return-feedback-source');
        var source = sourceId ? document.getElementById(sourceId) : null;
        if (!source) return;

        var note = source.content ? source.content.textContent : source.textContent;
        returnFeedbackBody.textContent = note || '';
        returnFeedbackLastTrigger = trigger;
        if (typeof returnFeedbackDialog.showModal === 'function') {
            returnFeedbackDialog.showModal();
        } else {
            returnFeedbackDialog.setAttribute('open', '');
            returnFeedbackFallbackOpen = true;
        }

        var closeButton = returnFeedbackDialog.querySelector('[data-return-feedback-close]');
        window.setTimeout(function () {
            if (closeButton) closeButton.focus();
        }, 0);
    }

    document.querySelectorAll('[data-return-feedback-source]').forEach(function (trigger) {
        trigger.addEventListener('click', function (event) {
            event.preventDefault();
            openReturnFeedbackDialog(trigger);
        });
    });
    if (returnFeedbackDialog) {
        returnFeedbackDialog.querySelectorAll('[data-return-feedback-close]').forEach(function (button) {
            button.addEventListener('click', closeReturnFeedbackDialog);
        });
        returnFeedbackDialog.addEventListener('close', function () {
            returnFeedbackFallbackOpen = false;
            if (returnFeedbackLastTrigger) returnFeedbackLastTrigger.focus();
        });
    }
    document.addEventListener('keydown', function (event) {
        if (returnFeedbackFallbackOpen && event.key === 'Escape') {
            event.preventDefault();
            closeReturnFeedbackDialog();
        }
    });
})();
