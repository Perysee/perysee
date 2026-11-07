const FRAGMENT = /^#secret=([A-Za-z0-9_-]{1,128})$/;

/**
 * The installer prints a link such as https://stats.example.com/install#secret=...
 * A fragment never reaches the server, so the secret stays out of access logs and
 * Referer headers. Returns the secret and removes it from the address bar, or null.
 */
export function takeInstallationSecret(location, history) {
    const match = FRAGMENT.exec(location.hash);
    if (match === null) {
        return null;
    }
    history.replaceState(null, "", location.pathname + location.search);

    return match[1];
}
