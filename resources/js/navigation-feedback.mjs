export function createBusyTracker(
    setBusy,
    delay = 150,
    { schedule = setTimeout, cancel = clearTimeout } = {},
) {
    let pending = 0,
        timer = null;
    return {
        start() {
            pending++;
            if (timer === null && pending === 1)
                timer = schedule(() => {
                    timer = null;
                    setBusy(true);
                }, delay);
        },
        finish() {
            pending = Math.max(0, pending - 1);
            if (pending > 0) return;
            if (timer !== null) {
                cancel(timer);
                timer = null;
            } else setBusy(false);
        },
    };
}
export const networkErrorToast = () => ({
    title: "Request failed",
    description:
        "Check your connection and reload the page if the problem persists.",
    color: "error",
    icon: "i-tabler-alert-triangle",
});

const HTTP_MESSAGES = {
    403: ["You do not have access to this page.", "Ask an administrator if you think this is a mistake."],
    404: ["This page does not exist.", "It may have been removed, or the address is wrong."],
    419: ["Your session has expired.", "Reload the page and sign in again if needed."],
    429: ["Too many requests.", "Wait a moment and try again."],
};

/**
 * The toast for a response that is not an Inertia page, such as an error page. Inertia would otherwise
 * show the whole response in a modal.
 */
export const httpErrorToast = (status) => {
    const [title, description] =
        HTTP_MESSAGES[status] ??
        (status >= 500
            ? ["The server could not handle the request.", "Try again. If it keeps failing, check Instance health and the application log."]
            : ["Request failed", "Reload the page if the problem persists."]);

    return { title, description, color: "error", icon: "i-tabler-alert-triangle" };
};

