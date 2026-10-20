document.addEventListener('DOMContentLoaded', () => {
    const menuButton = document.querySelector('.menu-toggle');
    const navigation = document.querySelector('.main-nav');

    if (menuButton && navigation) {
        menuButton.addEventListener('click', () => {
            const open = navigation.classList.toggle('is-open');
            menuButton.setAttribute('aria-expanded', String(open));
            menuButton.textContent = open ? 'Close' : 'Menu';
        });
    }

    document.querySelectorAll('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!window.confirm(form.dataset.confirm)) {
                event.preventDefault();
            }
        });
    });

    document.querySelectorAll('[data-slideshow]').forEach((slideshow) => {
        const slides = Array.from(slideshow.querySelectorAll('[data-slide]'));
        const indicators = Array.from(slideshow.querySelectorAll('[data-slide-to]'));
        const previousButton = slideshow.querySelector('[data-slide-prev]');
        const nextButton = slideshow.querySelector('[data-slide-next]');

        if (slides.length < 2 || indicators.length !== slides.length || !previousButton || !nextButton) {
            return;
        }

        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        let currentSlide = 0;
        let timer = null;
        let paused = false;

        const showSlide = (index) => {
            currentSlide = (index + slides.length) % slides.length;

            slides.forEach((slide, slideIndex) => {
                const active = slideIndex === currentSlide;
                slide.classList.toggle('is-active', active);
                slide.setAttribute('aria-hidden', String(!active));
            });

            indicators.forEach((indicator, slideIndex) => {
                const active = slideIndex === currentSlide;
                indicator.classList.toggle('is-active', active);
                indicator.setAttribute('aria-pressed', String(active));
            });
        };

        const stopAutoAdvance = () => {
            if (timer !== null) {
                window.clearInterval(timer);
                timer = null;
            }
        };

        const startAutoAdvance = () => {
            stopAutoAdvance();
            if (!reducedMotion && !paused && !document.hidden) {
                timer = window.setInterval(() => showSlide(currentSlide + 1), 5000);
            }
        };

        previousButton.addEventListener('click', () => {
            showSlide(currentSlide - 1);
            startAutoAdvance();
        });
        nextButton.addEventListener('click', () => {
            showSlide(currentSlide + 1);
            startAutoAdvance();
        });
        indicators.forEach((indicator, index) => {
            indicator.addEventListener('click', () => {
                showSlide(index);
                startAutoAdvance();
            });
        });

        slideshow.addEventListener('mouseenter', () => {
            paused = true;
            stopAutoAdvance();
        });
        slideshow.addEventListener('mouseleave', () => {
            paused = false;
            startAutoAdvance();
        });
        slideshow.addEventListener('focusin', () => {
            paused = true;
            stopAutoAdvance();
        });
        slideshow.addEventListener('focusout', (event) => {
            if (!slideshow.contains(event.relatedTarget)) {
                paused = false;
                startAutoAdvance();
            }
        });
        document.addEventListener('visibilitychange', startAutoAdvance);

        showSlide(currentSlide);
        startAutoAdvance();
    });
});
