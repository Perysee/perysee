// Apply the saved appearance before styles load to avoid a theme flash.
try {
    document.documentElement.classList.toggle('dark', localStorage.getItem('perysee-color-mode') === 'dark');
} catch { /* Storage may be unavailable in private browser contexts. */ }
