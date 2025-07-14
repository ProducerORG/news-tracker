const password = 'meinpasswort';
const checkLogin = () => {
    const input = prompt('Passwort eingeben:');
    if (input !== password) window.location.href = 'about:blank';
};
document.addEventListener('DOMContentLoaded', checkLogin);

