document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('login-form');
    form.addEventListener('submit', (e) => {
        e.preventDefault();
        const input = document.getElementById('password').value;
        if (input === 'meinpasswort') {
            window.location.href = '/dashboard.html';
        } else {
            document.getElementById('error-message').classList.remove('hidden');
        }
    });
});