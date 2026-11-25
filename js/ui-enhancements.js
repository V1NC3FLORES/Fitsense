(function () {
    function initTheme() {
        const validThemes = ['light', 'dark', 'auto'];
        const colorScheme = window.matchMedia('(prefers-color-scheme: dark)');
        let preference = 'auto';

        function applyTheme() {
            const resolvedTheme = preference === 'auto'
                ? (colorScheme.matches ? 'dark' : 'light')
                : preference;
            document.documentElement.dataset.theme = resolvedTheme;
            document.documentElement.dataset.themePreference = preference;
        }

        function syncThemePickers() {
            document.querySelectorAll('.theme-picker').forEach(function (picker) {
                const input = picker.querySelector('[data-theme-input]');
                if (input) input.value = preference;

                picker.querySelectorAll('[data-theme-choice]').forEach(function (button) {
                    button.setAttribute(
                        'aria-pressed',
                        button.dataset.themeChoice === preference ? 'true' : 'false'
                    );
                });
            });
        }

        applyTheme();
        syncThemePickers();

        document.addEventListener('click', function (event) {
            const button = event.target.closest('[data-theme-choice]');
            if (!button || !validThemes.includes(button.dataset.themeChoice)) return;

            preference = button.dataset.themeChoice;
            applyTheme();
            syncThemePickers();
        });

        const onColorSchemeChange = function () {
            if (preference === 'auto') applyTheme();
        };
        if (typeof colorScheme.addEventListener === 'function') {
            colorScheme.addEventListener('change', onColorSchemeChange);
        } else if (typeof colorScheme.addListener === 'function') {
            colorScheme.addListener(onColorSchemeChange);
        }

        fetch('/PBL/theme-preference.php', {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { Accept: 'application/json' }
        })
            .then(function (response) {
                if (!response.ok) throw new Error('Theme preference request failed.');
                return response.json();
            })
            .then(function (data) {
                if (!validThemes.includes(data.theme)) {
                    throw new Error('Theme preference response was invalid.');
                }
                preference = data.theme;
                applyTheme();
                syncThemePickers();
            })
            .catch(function (error) {
                console.error('Unable to load the saved theme preference.', error);
            });
    }

    function initSidebar() {
        const sidebar = document.querySelector('.sidebar');
        if (!sidebar) return;

        let backdrop = document.querySelector('.sidebar-backdrop');
        if (!backdrop) {
            backdrop = document.createElement('div');
            backdrop.className = 'sidebar-backdrop';
            document.body.appendChild(backdrop);
        }

        let closeButton = sidebar.querySelector('.sidebar-close-btn');
        if (!closeButton) {
            closeButton = document.createElement('button');
            closeButton.type = 'button';
            closeButton.className = 'sidebar-close-btn';
            closeButton.setAttribute('aria-label', 'Close navigation menu');
            closeButton.innerHTML = '<i class="bi bi-x-lg" aria-hidden="true"></i>';
            sidebar.appendChild(closeButton);
        }

        function openSidebar() {
            sidebar.classList.add('mobile-open');
            document.body.classList.add('sidebar-open');
        }

        function closeSidebar() {
            sidebar.classList.remove('mobile-open');
            document.body.classList.remove('sidebar-open');
        }

        closeButton.addEventListener('click', closeSidebar);
        backdrop.addEventListener('click', closeSidebar);

        document.querySelectorAll('.sidebar-toggle').forEach(function (button) {
            button.setAttribute('aria-controls', sidebar.id || 'sidebar');
            button.setAttribute('aria-expanded', 'false');
            button.addEventListener('click', function () {
                if (sidebar.classList.contains('mobile-open')) {
                    closeSidebar();
                } else {
                    openSidebar();
                }
                button.setAttribute('aria-expanded', sidebar.classList.contains('mobile-open') ? 'true' : 'false');
            });
        });

        sidebar.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', function () {
                if (window.innerWidth <= 992) closeSidebar();
            });
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') closeSidebar();
        });

        window.addEventListener('resize', function () {
            if (window.innerWidth > 992) closeSidebar();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSidebar);
    } else {
        initSidebar();
    }

    initTheme();
})();
