/**
 * Dashboard page interactions.
 */

function initOverviewTabs() {
    const tabs = document.querySelectorAll(".fb-overview-tab");
    const seriesGroups = document.querySelectorAll(".fb-chart-series");

    tabs.forEach((tab) => {
        tab.addEventListener("click", () => {
            const seriesName = tab.dataset.series;

            tabs.forEach((t) => {
                t.classList.remove("active");
                t.setAttribute("aria-selected", "false");
            });
            tab.classList.add("active");
            tab.setAttribute("aria-selected", "true");

            seriesGroups.forEach((g) => {
                g.style.display = g.dataset.series === seriesName ? "block" : "none";
            });
        });
    });
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initOverviewTabs);
} else {
    initOverviewTabs();
}
