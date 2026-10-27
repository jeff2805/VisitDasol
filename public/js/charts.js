/*
 * Charts for the report pages.
 *
 *   <canvas data-chart="line" data-labels='["Jan","Feb"]'
 *           data-series='[{"label":"Reservations","data":[3,5]}]'
 *           data-suffix=" nights"></canvas>
 *
 * The numbers always come from the page (and therefore from the database).
 * Nothing is fetched or calculated here; this file only draws.
 *
 * Optional presentation attributes, all absent by default:
 *   data-legend="false"   hide the built-in key (the page draws its own)
 *   data-points="0"       radius of the dots on a line
 *   data-fill="gradient"  fade a line's fill out towards the axis
 *   data-cutout="72%"     hole size of a doughnut
 */
(function () {
  'use strict';

  var PALETTE = ['#0F7C7A', '#F2B134', '#0D3B47', '#2E6B4E', '#8FD0C9', '#B07A0B', '#5B7178'];

  function readColours(count) {
    var colours = [];
    for (var index = 0; index < count; index++) {
      colours.push(PALETTE[index % PALETTE.length]);
    }
    return colours;
  }

  /*
   * A vertical fade from the line's own colour down to nothing. Built against
   * the chart's plotting area, so it is only available once Chart.js has
   * measured the canvas; before then the flat tint is used.
   */
  function gradientFor(context, colour) {
    var area = context.chart.chartArea;
    var flat = 'rgba(15, 124, 122, .12)';

    // The stops append an alpha channel to the line colour, which only works
    // on a plain #rrggbb value.
    if (!area || !/^#[0-9a-f]{6}$/i.test(colour)) {
      return flat;
    }

    var fade = context.chart.ctx.createLinearGradient(0, area.top, 0, area.bottom);
    fade.addColorStop(0, colour + '59');
    fade.addColorStop(1, colour + '00');

    return fade;
  }

  function build(canvas) {
    if (typeof Chart === 'undefined') {
      return;
    }

    var type = canvas.dataset.chart;
    var labels = JSON.parse(canvas.dataset.labels || '[]');
    var series = JSON.parse(canvas.dataset.series || '[]');
    var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var round = type === 'doughnut' || type === 'pie';

    var datasets = series.map(function (set, index) {
      var colour = set.color || PALETTE[index % PALETTE.length];

      if (round) {
        return {
          label: set.label || '',
          data: set.data,
          backgroundColor: set.colors || readColours(set.data.length),
          borderWidth: 0,
        };
      }

      var fill = 'rgba(15, 124, 122, .12)';
      if (type === 'line' && canvas.dataset.fill === 'gradient') {
        fill = function (context) {
          return gradientFor(context, colour);
        };
      } else if (type !== 'line') {
        fill = colour;
      }

      return {
        label: set.label || '',
        data: set.data,
        backgroundColor: fill,
        borderColor: colour,
        borderWidth: type === 'line' ? 2 : 0,
        borderRadius: type === 'bar' ? 4 : 0,
        fill: type === 'line',
        tension: 0.3,
        pointRadius: canvas.dataset.points === undefined ? 3 : Number(canvas.dataset.points),
        pointHoverRadius: 5,
        pointBackgroundColor: colour,
      };
    });

    // The page may draw its own key beside the chart; data-legend="false" then
    // stops Chart.js drawing a second one.
    var showLegend = datasets.length > 1 || round;
    if (canvas.dataset.legend !== undefined) {
      showLegend = canvas.dataset.legend === 'true';
    }

    new Chart(canvas, {
      type: type,
      data: { labels: labels, datasets: datasets },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: canvas.dataset.cutout || undefined,
        animation: reduceMotion ? false : { duration: 500 },
        plugins: {
          legend: {
            display: showLegend,
            position: round ? 'right' : 'top',
            labels: { boxWidth: 12, font: { family: 'Figtree Variable, Segoe UI, sans-serif' } },
          },
          tooltip: {
            callbacks: {
              label: function (context) {
                var value = context.parsed.y !== undefined ? context.parsed.y : context.parsed;
                var prefix = canvas.dataset.prefix || '';
                var suffix = canvas.dataset.suffix || '';
                var name = context.dataset.label || context.label;
                return name + ': ' + prefix + value.toLocaleString() + suffix;
              },
            },
          },
        },
        scales: round ? {} : {
          y: {
            beginAtZero: true,
            ticks: { precision: 0 },
            grid: { color: 'rgba(13, 59, 71, .08)' },
          },
          x: {
            grid: { display: false },
          },
        },
      },
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-chart]').forEach(build);
  });
})();
