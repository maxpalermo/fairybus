/**
 * Toggle password visibility for input fields.
 *
 * Use data-toggle-password="<input-id>" on the toggle button.
 * Include .icon-eye and .icon-eye-off SVGs inside the button.
 */

export default class PasswordToggle {
    static init(root = document) {
        root.querySelectorAll('[data-toggle-password]').forEach((btn) => {
            // Avoid double binding
            if (btn.dataset.passwordToggleBound === '1') {
                return;
            }
            btn.dataset.passwordToggleBound = '1';

            btn.addEventListener('click', () => {
                const inputId = btn.dataset.togglePassword;
                const input = root.getElementById ? root.getElementById(inputId) : document.getElementById(inputId);
                if (!input) {
                    return;
                }

                const isHidden = input.type === 'password';
                input.type = isHidden ? 'text' : 'password';

                const eye = btn.querySelector('.icon-eye');
                const eyeOff = btn.querySelector('.icon-eye-off');
                if (eye) eye.style.display = isHidden ? 'none' : 'inline';
                if (eyeOff) eyeOff.style.display = isHidden ? 'inline' : 'none';

                btn.setAttribute('aria-label', isHidden ? 'Nascondi password' : 'Mostra password');
            });
        });
    }
}
