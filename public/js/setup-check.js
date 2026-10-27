/*
 * Map on the local setup-check page. Kept in a file, not inline, so the
 * page obeys the same Content Security Policy as the rest of the site.
 */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    var element = document.getElementById('setup-map');
    if (!element || typeof L === 'undefined') {
      return;
    }

    var center = JSON.parse(element.dataset.center);
    var b = JSON.parse(element.dataset.bounds);
    var maxZoom = parseInt(element.dataset.maxZoom, 10);

    var map = L.map(element, { minZoom: parseInt(element.dataset.minZoom, 10), maxZoom: maxZoom })
      .setView([center.lat, center.lng], parseInt(element.dataset.zoom, 10));

    L.tileLayer(element.dataset.tileUrl, { maxZoom: maxZoom, attribution: element.dataset.attribution }).addTo(map);

    L.rectangle([[b.south, b.west], [b.north, b.east]], {
      color: '#0F7C7A', weight: 2, dashArray: '6 6', fill: false,
    }).addTo(map);

    var label = document.createElement('strong');
    label.textContent = 'Dasol municipal center';
    L.marker([center.lat, center.lng]).addTo(map).bindPopup(label);
  });
})();
