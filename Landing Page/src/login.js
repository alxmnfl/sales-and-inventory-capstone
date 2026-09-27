function togglePassword() {
    const input = document.getElementById('passwordInput');
    const icon  = document.querySelector('.toggle-pw');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.replace('fa-eye-slash', 'fa-eye');
    }
}

window.addEventListener('load', () => {
    const loader = document.getElementById('pageLoader');
    if (!loader) return;
    setTimeout(() => {
        loader.classList.add('is-hidden');
        loader.addEventListener('transitionend', () => loader.remove(), { once: true });
    }, 700);
});
