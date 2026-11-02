const revealOnScroll = () => {
    const items = document.querySelectorAll('[data-reveal]');

    if (!('IntersectionObserver' in window)) {
        items.forEach((item) => item.classList.add('is-visible'));
        return;
    }

    const observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    observer.unobserve(entry.target);
                }
            });
        },
        { rootMargin: '0px 0px -8% 0px', threshold: 0.12 },
    );

    items.forEach((item) => observer.observe(item));
};

const mobileMenu = () => {
    const toggle = document.querySelector('[data-menu-toggle]');
    const menu = document.querySelector('[data-menu]');

    if (!toggle || !menu) {
        return;
    }

    toggle.addEventListener('click', () => {
        const open = menu.classList.toggle('hidden') === false;
        toggle.setAttribute('aria-expanded', String(open));
        toggle.querySelector('[data-icon-open]')?.classList.toggle('hidden', open);
        toggle.querySelector('[data-icon-close]')?.classList.toggle('hidden', !open);
    });
};

// Header "Features" dropdown: opens on hover (on devices that can hover) or
// with its arrow button; closes on leaving, Escape, focus leaving or a click outside.
const featuresDropdown = () => {
    document.querySelectorAll('[data-dropdown]').forEach((root) => {
        const toggle = root.querySelector('[data-dropdown-toggle]');

        if (!toggle) {
            return;
        }

        const canHover = window.matchMedia('(hover: hover)');
        let closeTimer = null;

        const isOpen = () => root.hasAttribute('data-open');
        const setOpen = (open) => {
            clearTimeout(closeTimer);
            root.toggleAttribute('data-open', open);
            toggle.setAttribute('aria-expanded', String(open));
        };

        root.addEventListener('mouseenter', () => {
            if (canHover.matches) {
                setOpen(true);
            }
        });
        root.addEventListener('mouseleave', () => {
            if (canHover.matches) {
                closeTimer = setTimeout(() => setOpen(false), 150);
            }
        });
        toggle.addEventListener('click', () => setOpen(!isOpen()));
        root.addEventListener('focusout', (event) => {
            if (!root.contains(event.relatedTarget)) {
                setOpen(false);
            }
        });
        document.addEventListener('click', (event) => {
            if (isOpen() && !root.contains(event.target)) {
                setOpen(false);
            }
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && isOpen()) {
                setOpen(false);
                toggle.focus();
            }
        });
    });
};

// Mobile menu: the Features row expands to list each feature page.
const mobileSubmenus = () => {
    document.querySelectorAll('[data-submenu]').forEach((root) => {
        const toggle = root.querySelector('[data-submenu-toggle]');

        toggle?.addEventListener('click', () => {
            const open = !root.hasAttribute('data-open');
            root.toggleAttribute('data-open', open);
            toggle.setAttribute('aria-expanded', String(open));
        });
    });
};

const headerShadow = () => {
    const header = document.querySelector('[data-site-header]');

    if (!header) {
        return;
    }

    const update = () => header.classList.toggle('is-scrolled', window.scrollY > 8);
    update();
    window.addEventListener('scroll', update, { passive: true });
};

const countUp = () => {
    const counters = document.querySelectorAll('[data-count-to]');
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (reduced || !('IntersectionObserver' in window)) {
        return;
    }

    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (!entry.isIntersecting) {
                return;
            }

            observer.unobserve(entry.target);
            const el = entry.target;
            const target = Number(el.dataset.countTo);
            const prefix = el.dataset.countPrefix ?? '';
            const suffix = el.dataset.countSuffix ?? '';
            const started = performance.now();
            const duration = 1400;

            const step = (now) => {
                const progress = Math.min((now - started) / duration, 1);
                const eased = 1 - Math.pow(1 - progress, 3);
                el.textContent = prefix + Math.round(target * eased).toLocaleString('en-US') + suffix;

                if (progress < 1) {
                    requestAnimationFrame(step);
                }
            };

            requestAnimationFrame(step);
        });
    }, { threshold: 0.6 });

    counters.forEach((counter) => observer.observe(counter));
};

document.addEventListener('DOMContentLoaded', () => {
    revealOnScroll();
    mobileMenu();
    featuresDropdown();
    mobileSubmenus();
    headerShadow();
    countUp();
});
