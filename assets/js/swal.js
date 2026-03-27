(function () {
    const iconMap = {
        success: 'success',
        warning: 'warning',
        danger: 'error',
        error: 'error',
        info: 'info'
    };

    function resolveIcon(type) {
        return iconMap[type] || 'info';
    }

    function fallbackAlert(message) {
        window.alert(message);
        return Promise.resolve();
    }

    function fire(config) {
        if (!window.Swal) {
            return fallbackAlert(config.text || config.title || '');
        }

        return window.Swal.fire({
            confirmButtonColor: '#3273dc',
            cancelButtonColor: '#6b7280',
            ...config
        });
    }

    const toastInstance = window.Swal
        ? window.Swal.mixin({
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 4000,
            timerProgressBar: true,
            didOpen: (toast) => {
                toast.addEventListener('mouseenter', window.Swal.stopTimer);
                toast.addEventListener('mouseleave', window.Swal.resumeTimer);
            }
        })
        : null;

    window.AppSwal = {
        toast(message, type = 'info', options = {}) {
            if (!toastInstance) {
                return fallbackAlert(message);
            }

            return toastInstance.fire({
                icon: resolveIcon(type),
                title: message,
                ...options
            });
        },

        alert(configOrMessage, type = 'info') {
            if (typeof configOrMessage === 'string') {
                return fire({
                    icon: resolveIcon(type),
                    text: configOrMessage
                });
            }

            const config = configOrMessage || {};

            return fire({
                icon: resolveIcon(config.icon || type),
                title: config.title,
                text: config.text,
                html: config.html,
                footer: config.footer,
                showConfirmButton: config.showConfirmButton !== false,
                confirmButtonText: config.confirmButtonText || 'OK',
                allowOutsideClick: config.allowOutsideClick !== false,
                allowEscapeKey: config.allowEscapeKey !== false,
                timer: config.timer,
                timerProgressBar: config.timerProgressBar,
                didOpen: config.didOpen
            });
        },

        confirm(options = {}) {
            return fire({
                icon: resolveIcon(options.icon || 'warning'),
                title: options.title || 'Please Confirm',
                text: options.text,
                html: options.html,
                footer: options.footer,
                showCancelButton: true,
                confirmButtonText: options.confirmButtonText || 'Confirm',
                cancelButtonText: options.cancelButtonText || 'Cancel',
                reverseButtons: options.reverseButtons !== false,
                focusCancel: options.focusCancel !== false,
                allowOutsideClick: options.allowOutsideClick !== false,
                allowEscapeKey: options.allowEscapeKey !== false
            }).then((result) => result.isConfirmed);
        },

        sessionExpired(redirectUrl, message = 'Redirecting to login page...') {
            return fire({
                icon: 'warning',
                title: 'Session Expired',
                text: message,
                showConfirmButton: false,
                allowOutsideClick: false,
                allowEscapeKey: false,
                timer: 1800,
                timerProgressBar: true
            }).then(() => {
                window.location.href = redirectUrl;
            });
        }
    };
})();
