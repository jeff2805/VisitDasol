/*
 * Read-only map that shows one or more pins.
 *
 *   <div class="vd-map" data-location-map
 *        data-points='[{"lat":..,"lng":..,"label":"Resort name"}]'
 *        data-zoom="15" ...></div>
 *
 * With data-locate, a button beside the map puts the visitor on it and says
 * how far away the pin is. That is the question a guest reading one resort
 * page actually has, and before this they had to go back to the main map to
 * ask it.
 *
 * Popups are built with text nodes, never with HTML strings, so a resort
 * name containing markup can never run as code.
 *
 * The richer version of this — layers, routing, the trip planner — lives in
 * map.js and serves the whole-municipality map. The two share no code on
 * purpose: this file stays small enough to read in one sitting, and it is
 * loaded on every resort, tourist spot and reservation page.
 */
(function () {
  'use strict';

  /* Geometry and formatting, shared with map.js. See geo.js. */
  var geo = (window.VisitDasol || {}).geo || {};
  var straightLineKm = geo.straightLineKm;
  var formatKm = geo.formatKm;

  function setUp(element) {
    if (typeof L === 'undefined') {
      return;
    }

    var points = JSON.parse(element.dataset.points || '[]');
    if (points.length === 0) {
      return;
    }

    var map = L.map(element, {
      scrollWheelZoom: false,
      minZoom: parseInt(element.dataset.minZoom, 10) || 8,
      maxZoom: parseInt(element.dataset.maxZoom, 10) || 18,
    });

    L.tileLayer(element.dataset.tileUrl, {
      maxZoom: parseInt(element.dataset.maxZoom, 10) || 18,
      attribution: element.dataset.attribution,
    }).addTo(map);

    var markers = points.map(function (point) {
      var marker = L.marker([point.lat, point.lng]).addTo(map);

      if (point.label) {
        var label = document.createElement('strong');
        label.textContent = point.label;
        marker.bindPopup(label);
      }

      return marker;
    });

    if (markers.length === 1) {
      map.setView(markers[0].getLatLng(), parseInt(element.dataset.zoom, 10) || 15);
    } else {
      map.fitBounds(L.featureGroup(markers).getBounds().pad(0.2));
    }

    window.setTimeout(function () {
      map.invalidateSize();
    }, 200);

    /* Where the visitor is ------------------------------------------------ */

    if (!element.hasAttribute('data-locate')) {
      return;
    }

    var button = element.parentNode.querySelector('[data-locate-button]');
    var readout = element.parentNode.querySelector('[data-locate-readout]');

    if (!button) {
      return;
    }

    var hereMarker = null;
    var hereCircle = null;

    function report(text, failed) {
      if (!readout) {
        return;
      }

      readout.hidden = false;
      readout.textContent = text;
      readout.classList.toggle('is-warning', failed === true);
    }

    button.hidden = false;

    button.addEventListener('click', function () {
      if (!navigator.geolocation) {
        report('This browser cannot share a location.', true);

        return;
      }

      var blocked = geo.insecureOriginMessage();

      if (blocked) {
        report(blocked, true);

        return;
      }

      var original = button.textContent;
      button.disabled = true;
      button.textContent = 'Locating…';

      /*
       * The sharpest reading within a few seconds, not the first one.
       *
       * A phone's first answer is usually cell tower or wifi, hundreds
       * of metres out, and the GPS fix follows a moment later.
       * getCurrentPosition hands back the first and never mentions the
       * second, which is how a distance ends up a kilometre wrong with
       * nothing on screen to suggest it.
       *
       * Kept in step with locate() in map.js — the same two numbers.
       */
      var GOOD_ENOUGH_METRES = 40;
      var PATIENCE_MS = 9000;

      var best = null;
      var watch = null;
      var timer = null;

      function stopWatching() {
        if (watch !== null) {
          navigator.geolocation.clearWatch(watch);
          watch = null;
        }

        window.clearTimeout(timer);

        button.disabled = false;
        button.textContent = original;
      }

      function finish() {
        stopWatching();

        if (!best) {
          report('Your location could not be found just now.', true);

          return;
        }

        var here = { lat: best.coords.latitude, lng: best.coords.longitude };
        var quality = geo.describeAccuracy(best.coords.accuracy);

        if (hereMarker) { map.removeLayer(hereMarker); }
        if (hereCircle) { map.removeLayer(hereCircle); }

        hereMarker = L.marker([here.lat, here.lng], {
          icon: L.divIcon({
            className: 'vd-pin-wrap',
            html: '<span class="vd-pin vd-pin--here"><i class="bi bi-person-fill"></i></span>',
            iconSize: [30, 30],
            iconAnchor: [15, 30],
            popupAnchor: [0, -28],
          }),
          title: 'Your location',
        }).addTo(map).bindPopup('You are here, ' + quality.text + '.');

        hereCircle = L.circle([here.lat, here.lng], {
          radius: best.coords.accuracy,
          color: '#0F7C7A',
          weight: 1,
          fillOpacity: .08,
        }).addTo(map);

        var target = points[0];
        var km = straightLineKm(here, target);

        map.fitBounds([[here.lat, here.lng], [target.lat, target.lng]], { padding: [40, 40] });

        /*
         * A distance is only worth as much as the position it was
         * measured from. When the fix is wide, the figure is given with
         * that said out loud rather than to one tidy decimal place.
         */
        if (quality.grade === 'poor' || quality.grade === 'unknown') {
          report('Your browser puts you ' + quality.text
            + ', so this is rough: about ' + formatKm(km)
            + ' in a straight line. The map page can place you more precisely.', true);

          return;
        }

        report('You are about ' + formatKm(km) + ' away in a straight line, '
          + quality.text + '. The road will be longer; the map page can work out the drive.');
      }

      watch = navigator.geolocation.watchPosition(
        function (position) {
          if (!best || position.coords.accuracy < best.coords.accuracy) {
            best = position;
          }

          if (best.coords.accuracy <= GOOD_ENOUGH_METRES) {
            finish();
          }
        },
        function (error) {
          if (error.code === error.PERMISSION_DENIED) {
            stopWatching();

            report('Location permission was refused. You can allow it from the icon in the address bar.', true);

            return;
          }

          // A timeout with a reading already in hand still has an answer.
          if (!best) {
            finish();
          }
        },
        // maximumAge 0: never a fix from before the button was pressed.
        { enableHighAccuracy: true, timeout: PATIENCE_MS, maximumAge: 0 }
      );

      timer = window.setTimeout(finish, PATIENCE_MS);
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-location-map]').forEach(setUp);
  });
})();
