const sidebar = document.querySelector('[data-sidebar]');
if (sidebar) {
    const smallScreen = window.matchMedia('(max-width: 860px)');
    const adaptSidebar = () => { sidebar.open = !smallScreen.matches; };
    adaptSidebar();
    smallScreen.addEventListener('change', adaptSidebar);
}
const dropdowns = () => document.querySelectorAll('.context-switcher, .account-menu');
document.addEventListener('click', event => {
    dropdowns().forEach(menu => { if (!menu.contains(event.target)) menu.open = false; });
});
document.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    dropdowns().forEach(menu => {
        if (menu.open) { menu.open = false; menu.querySelector('summary').focus(); }
    });
    if (sidebar?.open && window.matchMedia('(max-width: 860px)').matches) {
        sidebar.open = false;
        sidebar.querySelector('summary').focus();
    }
});
