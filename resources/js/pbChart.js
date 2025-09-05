import Chart from 'chart.js/auto'

export default function pbChart({ cachedData, options, type }) {
    return {
        init: function () {
            this.initChart()

            this.$wire.$on('updateChartData', ({ data }) => {
                pbChart = this.getChart()
                pbChart.data = data
                pbChart.update('resize')
            })

            Alpine.effect(() => {
                Alpine.store('theme')

                this.$nextTick(() => {
                    if (!this.getChart()) {
                        return
                    }

                    this.getChart().destroy()
                    this.initChart()
                })
            })

            window
                .matchMedia('(prefers-color-scheme: dark)')
                .addEventListener('change', () => {
                    if (Alpine.store('theme') !== 'system') {
                        return
                    }

                    this.$nextTick(() => {
                        this.getChart().destroy()
                        this.initChart()
                    })
                })
        },

        initChart: function (data = null) {
            Chart.defaults.animation.duration = 0
            if (this.getChart()) this.getChart().destroy()

            return new Chart(this.$refs.canvas, {
                type: type,
                data: cachedData,
                options: this.getOptions(),
            })
        },

        getChart: function () {
            if (!this.$refs.canvas) {
                return null
            }

            return Chart.getChart(this.$refs.canvas)
        },

        getCurrency: function () {
            return new Intl.NumberFormat(
                window.jsSettings.locale,
                { style: 'currency', currency: window.jsSettings.currency }
            )
        },

        getOptions: function () {
            return {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        display: false,
                        stacked: true,
                        beginAtZero: false,
                    },
                    x: {
                        display: false,
                    },
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    title: {
                        display: false
                    },
                    tooltip: false,
                    // @todo do we want tooltips on the chart? its a bit messy.
                    // tooltip: {
                    //     display: false,
                    //     caretSize: 0,
                    //     displayColors: false,
                    //     callbacks: {
                    //         title: function(context) {
                    //             const date = new Date(context[0].label)
                    //             return date.toLocaleDateString(undefined, {
                    //                 weekday: "long",
                    //                 year: "numeric",
                    //                 month: "long",
                    //                 day: "numeric",
                    //             })
                    //         },
                    //         label: (context) => {
                    //             const idx = context.dataIndex;
                    //             const datasets = cachedData.datasets
                    //
                    //             let min = datasets[0].data[idx] || 0;
                    //             let avg = (datasets[1].data[idx] || 0) + min;
                    //             let max = (datasets[2].data[idx] || 0) + avg;
                    //
                    //             const currency = this.getCurrency()
                    //
                    //             return `Min: ${currency.format(min)} - Avg: ${currency.format(avg)} - Max: ${currency.format(max)}`
                    //         }
                    //     }
                    // }
                },
                elements: {
                    point:{
                        radius: 0,
                        hitRadius: 10,
                        borderWidth: 0,
                        hoverBorderWidth: 0,
                        borderColor: 'rgba(0, 0, 0, 0)',
                        backgroundColor: 'rgba(0, 0, 0, 0)',
                    }
                }
            }
        },
    }
}
