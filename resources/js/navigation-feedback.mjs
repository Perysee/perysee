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
