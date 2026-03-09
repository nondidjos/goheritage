// GoHéritage — global entry point
// handles mobile menu toggle

document.addEventListener('DOMContentLoaded', function () {
    // mobile hamburger menu
    const toggle = document.getElementById('mobile-menu-toggle');
    const nav = document.getElementById('site-nav');

    if (toggle && nav) {
        toggle.addEventListener('click', function () {
            const expanded = toggle.getAttribute('aria-expanded') === 'true';
            toggle.setAttribute('aria-expanded', String(!expanded));
            nav.classList.toggle('is-open');
            document.body.classList.toggle('nav-open');
        });
    }
});
