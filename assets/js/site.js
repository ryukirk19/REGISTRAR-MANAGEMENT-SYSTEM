(function () {
    'use strict';

    const header = document.querySelector('.site-nav');
    const onScroll = () => {
        if (!header) return;
        header.style.boxShadow = window.scrollY > 8 ? '0 14px 34px -26px rgba(0,0,0,.8)' : 'none';
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();

    const items = document.querySelectorAll('.module, .pain, .flow-step, .impact-card, .privacy-item');
    if ('IntersectionObserver' in window) {
        items.forEach((el) => {
            el.style.opacity = '0';
            el.style.transform = 'translateY(12px)';
            el.style.transition = 'opacity .5s ease, transform .5s ease';
        });
        const io = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'none';
                    io.unobserve(entry.target);
                }
            });
        }, { threshold: 0.12 });
        items.forEach((el) => io.observe(el));
    }
})();
