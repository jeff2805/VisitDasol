/*
 * Map pin picker used on resort and destination forms.
 *
 * Markup (see resources/views/components/coordinate-picker.blade.php):
 *   <div class="vd-map" data-coordinate-picker
 *        data-center='{"lat":..,"lng":..}' data-bounds='{...}'
 *        data-zoom="12" data-min-zoom="10" data-max-zoom="18"
 *        data-tile-url="..." data-attribution="..."
 *        data-latitude-input="#field-latitude" data-longitude-input="#field-longitude"></div>
 *
 * The map only fills in the two number fields; the server checks the values again.
 */
(function () {
  'use strict';

  function round(value) {
    return Math.round(value * 1e7) / 1e7;
  }

  function setUp(element) {
    if (typeof L === 'undefined') {
      return;
    }

    var center = JSON.parse(element.dataset.center);
    var bounds = JSON.parse(element.dataset.bounds);
    var latitudeInput = document.querySelector(element.dataset.latitudeInput);
    var longitudeInput = document.querySelector(element.dataset.longitudeInput);

    if (!latitudeInput || !longitudeInput) {
      return;
    }

    var limit = L.latLngBounds([bounds.south, bounds.west], [bounds.north, bounds.east]);
    var start = L.latLng(
      parseFloat(latitudeInput.value) || center.lat,
      parseFloat(longitudeInput.value) || center.lng
    );
    var hasPin = latitudeInput.value !== '' && longitudeInput.value !== '';

    var map = L.map(element, {
      minZoom: parseInt(element.dataset.minZoom, 10),
      maxZoom: parseInt(element.dataset.maxZoom, 10),
      maxBounds: limit.pad(0.25),
    }).setView(start, hasPin ? 15 : parseInt(element.dataset.zoom, 10));

    L.tileLayer(element.dataset.tileUrl, {
      maxZoom: parseInt(element.dataset.maxZoom, 10),
      attribution: element.dataset.attribution,
    }).addTo(map);

    // The dashed box shows where a pin is accepted.
    L.rectangle(limit, { color: '#0F7C7A', weight: 2, dashArray: '6 6', fill: false }).addTo(map);

    var marker = L.marker(start, { draggable: true, autoPan: true });
    if (hasPin) {
      marker.addTo(map);
    }

    function publish(latLng) {
      latitudeInput.value = round(latLng.lat);
      longitudeInput.value = round(latLng.lng);
      latitudeInput.dispatchEvent(new Event('change', { bubbles: true }));
    }

    map.on('click', function (event) {
      marker.setLatLng(event.latlng).addTo(map);
      publish(event.latlng);
    });

    marker.on('dragend', function () {
      publish(marker.getLatLng());
    });

    function moveFromInputs() {
      var lat = parseFloat(latitudeInput.value);
      var lng = parseFloat(longitudeInput.value);

      if (isNaN(lat) || isNaN(lng)) {
        return;
      }

      var position = L.latLng(lat, lng);
      marker.setLatLng(position).addTo(map);
      map.panTo(position);
    }

    latitudeInput.addEventListener('input', moveFromInputs);
    longitudeInput.addEventListener('input', moveFromInputs);

    // The map is often inside a card that is laid out after this runs.
    window.setTimeout(function () {
      map.invalidateSize();
    }, 200);
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-coordinate-picker]').forEach(setUp);
  });
})();
