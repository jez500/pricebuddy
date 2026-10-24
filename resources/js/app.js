import pbChart from "./pbChart.js";
window.pbChart = pbChart;

const clipboard = new ClipboardJS('.copy-to-clipboard');

clipboard.on('success', (event) => {
    // ClipboardJS selects the target to run execCommand('copy'); clear the
    // highlight once the copy has completed.
    event.clearSelection?.();

    if (! event.trigger?.hasAttribute('data-copy-notify') || ! window.FilamentNotification) {
        return;
    }

    new window.FilamentNotification()
        .title('Copied to clipboard')
        .success()
        .send();
});

window.copyToClipboard = function (id) {
    document.getElementById(id).select();
    document.execCommand('copy');
}
