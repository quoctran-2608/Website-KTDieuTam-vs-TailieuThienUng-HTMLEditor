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
})();
