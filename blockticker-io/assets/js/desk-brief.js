/**
 * Desk Brief — interactive layer.
 *
 * 1. Citation tooltips — hover [1] footnote refs to see the source inline
 * 2. Scroll reveal — .bt-brief__sec elements fade up as they enter viewport
 *
 * No dependencies. Runs after DOM ready.
 *
 * @since 119.28.0
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        // ── 1. Scroll reveal ────────────────────────────────────
        var reveals = document.querySelectorAll('.bt-brief__sec, .bt-brief__tldr, .bt-brief__watching');
        if ('IntersectionObserver' in window && reveals.length) {
            var io = new IntersectionObserver(function (entries) {
                entries.forEach(function (e) {
                    if (e.isIntersecting) {
                        e.target.classList.add('bt-brief__sec--visible');
                        io.unobserve(e.target);
                    }
                });
            }, { threshold: 0.08 });

            reveals.forEach(function (el) {
                el.style.opacity = '0';
                el.style.transform = 'translateY(16px)';
                el.style.transition = 'opacity .45s ease, transform .45s ease';
                io.observe(el);
            });
        }

        document.addEventListener('animationend', function (e) {
            if (e.target.classList.contains('bt-brief__sec--visible')) {
                e.target.style.opacity = '';
                e.target.style.transform = '';
            }
        });

        // Apply visible class effect via JS since we can't add CSS @keyframes here
        document.querySelectorAll('.bt-brief__sec, .bt-brief__tldr, .bt-brief__watching').forEach(function (el) {
            if (el.classList.contains('bt-brief__sec--visible')) {
                el.style.opacity = '1';
                el.style.transform = 'none';
            }
        });

    });
}());
