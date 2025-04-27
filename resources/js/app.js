import pbChart from "./pbChart.js";

window.pbChart = pbChart;

new ClipboardJS('.copy-to-clipboard');

window.copyToClipboard = function (id) {
    document.getElementById(id).select();
    document.execCommand('copy');
}
