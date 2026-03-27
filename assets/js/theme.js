(() => {
    const STORAGE_KEY = 'mikreman-theme';
    const LIGHT = 'light';
    const DARK = 'dark';

    function getStoredTheme() {
        try {
            return localStorage.getItem(STORAGE_KEY) === DARK ? DARK : LIGHT;
        } catch (error) {
            return LIGHT;
        }
    }

    function persistTheme(theme) {
        try {
            localStorage.setItem(STORAGE_KEY, theme);
        } catch (error) {
            // Ignore persistence failures and keep the current session theme.
        }
    }

    function getNextTheme(theme) {
        return theme === DARK ? LIGHT : DARK;
    }

    function updateToggleButtons(theme) {
        const nextTheme = getNextTheme(theme);
        const iconClass = theme === DARK ? 'bi-sun-fill' : 'bi-moon-stars-fill';
        const label = nextTheme === DARK ? 'Dark' : 'Light';

        document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
            button.setAttribute('aria-label', `Switch to ${label.toLowerCase()} theme`);

            const icon = button.querySelector('.theme-toggle-icon');
            if (icon) {
                icon.className = `bi ${iconClass} theme-toggle-icon`;
            }

            const text = button.querySelector('.theme-toggle-label');
            if (text) {
                text.textContent = label;
            }
        });
    }

    function applyTheme(theme, persist = false) {
        const resolvedTheme = theme === DARK ? DARK : LIGHT;
        document.documentElement.dataset.theme = resolvedTheme;
        document.documentElement.classList.toggle('theme-dark', resolvedTheme === DARK);
        document.documentElement.classList.toggle('theme-light', resolvedTheme === LIGHT);
        updateToggleButtons(resolvedTheme);

        if (persist) {
            persistTheme(resolvedTheme);
        }
    }

    function handleToggleClick() {
        applyTheme(getNextTheme(document.documentElement.dataset.theme), true);
    }

    document.addEventListener('DOMContentLoaded', () => {
        applyTheme(getStoredTheme());

        document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
            button.addEventListener('click', handleToggleClick);
        });
    });
})();
