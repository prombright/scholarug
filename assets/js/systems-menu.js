document.addEventListener('DOMContentLoaded', function () {
    const toggle = document.getElementById('systemsMenuToggle');
    const menu = document.getElementById('systemsMenu');

    if (!toggle || !menu) return;

    toggle.addEventListener('click', function (event) {
        event.preventDefault();
        menu.classList.toggle('active');
    });

    document.addEventListener('click', function (event) {
        if (!menu.contains(event.target) && !toggle.contains(event.target)) {
            menu.classList.remove('active');
        }
    });
});
