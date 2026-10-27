/*
 * Distance, formatting, and the one browser rule that catches everybody out.
 *
 * Both maps need these: the whole-municipality map in map.js, and the single
 * pin on every resort and tourist spot page in location-map.js. They had a
 * copy each, which is two places for the same metre to be measured two
 * different ways.
 *
 * There is no bundler in this project, so this attaches to one namespace on
 * window and both files read it from there. Load it before either of them.
 *
 * Everything here is a pure function of its arguments. Nothing touches the
 * page, the network, or Leaflet.
 */
window.VisitDasol = window.VisitDasol || {};

window.VisitDasol.geo = (function () {
  'use strict';

  var EARTH_RADIUS_KM = 6371;

  /*
   * Great-circle distance in kilometres. This is the distance a bird flies,
   * not the distance a tricycle drives, so it is only ever shown labelled as
   * such. It costs nothing and needs no network, which is why every point can
   * carry one the moment a location is known.
   */
  function straightLineKm(from, to) {
    var toRad = Math.PI / 180;
    var dLat = (to.lat - from.lat) * toRad;
    var dLng = (to.lng - from.lng) * toRad;

    var a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
      Math.cos(from.lat * toRad) * Math.cos(to.lat * toRad) *
      Math.sin(dLng / 2) * Math.sin(dLng / 2);

    return EARTH_RADIUS_KM * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
  }

  /** Metres under a kilometre, then one decimal until the number gets long. */
  function formatKm(km) {
    return km < 1 ? Math.round(km * 1000) + ' m' : km.toFixed(km < 10 ? 1 : 0) + ' km';
  }

  function formatMinutes(seconds) {
    var minutes = Math.round(seconds / 60);

    if (minutes < 60) {
      return minutes + ' min';
    }

    var hours = Math.floor(minutes / 60);
    var rest = minutes % 60;

    return hours + ' hr' + (rest ? ' ' + rest + ' min' : '');
  }

  /**
   * How good a fix is, in words, with the figure beside it.
   *
   * The number alone means nothing to most people: "accurate to 2400 m"
   * reads like a measurement rather than a warning. A phone outdoors
   * with a sky view lands inside 20 m; a laptop on wifi is routinely out
   * by a kilometre or more, because it is being placed by which network
   * it can see and not by where it is.
   */
  function describeAccuracy(metres) {
    if (typeof metres !== 'number' || !isFinite(metres) || metres <= 0) {
      return { grade: 'unknown', text: 'of unknown accuracy' };
    }

    var reading = metres >= 1000
      ? (metres / 1000).toFixed(1) + ' km'
      : Math.round(metres) + ' m';

    if (metres <= 50) {
      return { grade: 'good', text: 'accurate to about ' + reading };
    }

    if (metres <= 500) {
      return { grade: 'fair', text: 'accurate to about ' + reading };
    }

    return { grade: 'poor', text: 'only accurate to about ' + reading };
  }

  /**
   * Whether a point is inside the municipal bounds the rest of the
   * system enforces. Used to tell somebody their browser has placed
   * them outside Dasol, which on a laptop happens all the time.
   */
  function withinBounds(point, bounds) {
    if (!bounds) {
      return true;
    }

    return point.lat >= bounds.south
      && point.lat <= bounds.north
      && point.lng >= bounds.west
      && point.lng <= bounds.east;
  }

  /**
   * Why the browser will not share a location, when it will not.
   *
   * Browsers hand one out only on https or localhost. Opened over plain http
   * at a LAN address — which is exactly how this site gets shown on a phone
   * during a demonstration — the refusal says nothing useful, and the feature
   * looks broken rather than switched off. Returns null when there is no
   * problem to report.
   */
  function insecureOriginMessage() {
    if (window.isSecureContext !== false) {
      return null;
    }

    return 'Your browser only shares a location over https, or on localhost. ' +
      'This page was opened at ' + window.location.protocol + '//' + window.location.host +
      ', so location is switched off by the browser and not by this site.';
  }

  return {
    straightLineKm: straightLineKm,
    formatKm: formatKm,
    formatMinutes: formatMinutes,
    describeAccuracy: describeAccuracy,
    withinBounds: withinBounds,
    insecureOriginMessage: insecureOriginMessage,
  };
})();
