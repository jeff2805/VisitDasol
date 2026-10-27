/*
 * VisitDasol — GIS map dashboard
 *
 * The furniture around the map on /map: the search box, the category
 * filters, the barangay information panel, and the bigger view.
 *
 * Deliberately NOT part of map.js. That file is the map — it is used by
 * the office map and by the small map on every resort and spot page, and
 * none of those have a dashboard around them. This file only runs where
 * [data-gis] is on the page, and it talks to the map through one event
 * and the data-map-focus buttons the map already understands.
 *
 * Nothing here decides anything. Availability, distances, routes and
 * boundaries all come from the server or from map.js; this is the
 * cabinetwork.
 */
(function () {
  'use strict';

  function setUp(stage) {
    var searchBox = stage.querySelector('[data-gis-search]');
    var list = stage.querySelector('[data-gis-list]');
    var emptyNote = stage.querySelector('[data-gis-empty]');

    var items = Array.prototype.slice.call(
      stage.querySelectorAll('[data-gis-item]')
    );

    var groups = Array.prototype.slice.call(
      stage.querySelectorAll('[data-gis-group]')
    );

    var filters = Array.prototype.slice.call(
      stage.querySelectorAll('[data-gis-filter]')
    );

    /* What the list is currently narrowed to ---------------------------- */

    var state = {
      text: '',
      kind: 'all',
      barangay: null,
    };

    function matches(item) {
      if (state.kind !== 'all' && item.dataset.gisItem !== state.kind) {
        return false;
      }

      /*
       * A barangay row is never hidden by its own barangay filter: it
       * is the heading of what is being shown, and taking it away
       * leaves a list with no way back to the thing it is filtered by.
       */
      if (
        state.barangay
        && item.dataset.gisBarangay !== state.barangay
      ) {
        return false;
      }

      if (state.text === '') {
        return true;
      }

      return (item.dataset.gisName || '').indexOf(state.text) !== -1;
    }

    /*
     * A group heading is only worth showing while something is under
     * it. "Resorts" above an empty stretch reads like a resort went
     * missing rather than like a filter is on.
     */
    function showGroups() {
      groups.forEach(function (heading) {
        var kind = heading.dataset.gisGroup;

        var any = items.some(function (item) {
          return item.dataset.gisItem === kind && !item.hidden;
        });

        heading.hidden = !any;
      });
    }

    function apply() {
      var shown = 0;

      items.forEach(function (item) {
        var on = matches(item);

        item.hidden = !on;
        shown += on ? 1 : 0;
      });

      showGroups();

      if (emptyNote) {
        emptyNote.hidden = shown > 0;
      }
    }

    /* The search box ----------------------------------------------------- */

    if (searchBox) {
      var typing = null;

      searchBox.addEventListener('input', function () {
        /*
         * Debounced, though nothing is fetched: the filtering is local,
         * but on a long list it runs on every keystroke, and a phone
         * typing into a list of a hundred rows should not redraw a
         * hundred rows a hundred times.
         */
        window.clearTimeout(typing);

        typing = window.setTimeout(function () {
          state.text = searchBox.value.trim().toLowerCase();
          apply();
        }, 150);
      });

      // A search box with a clear button fires this and not 'input'
      // in some browsers.
      searchBox.addEventListener('search', function () {
        state.text = searchBox.value.trim().toLowerCase();
        apply();
      });
    }

    /* The category filters ------------------------------------------------ */

    filters.forEach(function (button) {
      button.addEventListener('click', function () {
        state.kind = button.dataset.gisFilter;

        filters.forEach(function (other) {
          var on = other === button;

          other.classList.toggle('is-on', on);
          other.setAttribute('aria-pressed', on ? 'true' : 'false');
        });

        apply();
      });
    });

    /* The barangay panel -------------------------------------------------- */

    var panel = {
      idle: stage.querySelector('[data-gis-brgy-idle]'),
      body: stage.querySelector('[data-gis-brgy-body]'),
      name: stage.querySelector('[data-gis-brgy-name]'),
      resorts: stage.querySelector('[data-gis-brgy-resorts]'),
      spots: stage.querySelector('[data-gis-brgy-spots]'),
      landmark: stage.querySelector('[data-gis-brgy-landmark]'),
      filter: stage.querySelector('[data-gis-brgy-filter]'),
      close: stage.querySelector('[data-gis-brgy-close]'),
    };

    var scopeNote = stage.querySelector('[data-gis-scope-note]');
    var scopeLabel = stage.querySelector('[data-gis-scope-label]');
    var scopeClear = stage.querySelector('[data-gis-scope-clear]');

    // The barangay the panel is currently describing, by plain name.
    var showing = null;

    /*
     * Counted from the rows already on the page rather than fetched.
     * The server put them there from real records, so counting them is
     * the same answer with no second request — and it stays correct if
     * the listing changes, because there is only one source.
     */
    function countIn(barangay, kind) {
      return items.filter(function (item) {
        return item.dataset.gisItem === kind
          && item.dataset.gisBarangay === barangay;
      }).length;
    }

    function clearPanel() {
      showing = null;

      if (panel.idle) { panel.idle.hidden = false; }
      if (panel.body) { panel.body.hidden = true; }
      if (panel.close) { panel.close.hidden = true; }
    }

    function fillPanel(barangay) {
      if (!barangay || !panel.body) {
        clearPanel();

        return;
      }

      showing = barangay.name;

      if (panel.name) { panel.name.textContent = 'Brgy. ' + barangay.name; }
      if (panel.resorts) { panel.resorts.textContent = countIn(barangay.name, 'resort'); }
      if (panel.spots) { panel.spots.textContent = countIn(barangay.name, 'destination'); }

      /*
       * Where the point came from, when it came from a landmark. More
       * use than the word "approximate" on its own: a visitor can
       * actually navigate to a school.
       */
      if (panel.landmark) {
        if (barangay.landmark) {
          panel.landmark.textContent = 'This point was taken from ' + barangay.landmark + '.';
          panel.landmark.hidden = false;
        } else if (barangay.approximate) {
          panel.landmark.textContent =
            'This point is a guide to the area, not a confirmed location.';
          panel.landmark.hidden = false;
        } else {
          panel.landmark.hidden = true;
        }
      }

      if (panel.idle) { panel.idle.hidden = true; }
      panel.body.hidden = false;
      if (panel.close) { panel.close.hidden = false; }
    }

    function narrowTo(barangay) {
      state.barangay = barangay;

      if (scopeNote) {
        scopeNote.hidden = !barangay;
      }

      if (scopeLabel && barangay) {
        scopeLabel.textContent = 'Showing Brgy. ' + barangay + ' only';
      }

      apply();
    }

    // map.js says which barangay is showing; this panel follows it, and
    // the map does not know this panel exists.
    stage.addEventListener('vd:barangay', function (event) {
      fillPanel(event.detail);

      if (!event.detail) {
        narrowTo(null);
      }
    });

    if (panel.filter) {
      panel.filter.addEventListener('click', function () {
        narrowTo(showing);
      });
    }

    if (panel.close) {
      panel.close.addEventListener('click', function () {
        clearPanel();
        narrowTo(null);
      });
    }

    if (scopeClear) {
      scopeClear.addEventListener('click', function () {
        narrowTo(null);
      });
    }

    /* Which row is chosen -------------------------------------------------- */

    if (list) {
      list.addEventListener('click', function (event) {
        var row = event.target.closest('[data-gis-item]');

        if (!row) {
          return;
        }

        items.forEach(function (item) {
          item.classList.toggle('is-chosen', item === row);
        });
      });
    }

    /* Bigger view ---------------------------------------------------------- */

    var expand = stage.querySelector('[data-gis-fullscreen]');
    var canvasWrap = stage.querySelector('.vd-gis__canvaswrap');
    var mapElement = stage.querySelector('[data-visit-map]');

    /*
     * Leaflet measures its container once. Every time that container
     * changes size — going fullscreen, coming back, a phone turning —
     * it has to be told, or the map draws into the old rectangle and
     * leaves grey where the new one grew.
     */
    function remeasure() {
      if (!mapElement) {
        return;
      }

      window.setTimeout(function () {
        mapElement.dispatchEvent(new Event('vd:resize'));
      }, 120);
    }

    if (expand && canvasWrap && document.fullscreenEnabled) {
      expand.hidden = false;

      expand.addEventListener('click', function () {
        if (document.fullscreenElement) {
          document.exitFullscreen();

          return;
        }

        var request = canvasWrap.requestFullscreen();

        // A browser may refuse — a permissions policy, or a gesture it
        // did not accept. Nothing breaks; the map stays where it is.
        if (request && request.catch) {
          request.catch(function () {});
        }
      });

      document.addEventListener('fullscreenchange', function () {
        var on = document.fullscreenElement === canvasWrap;

        expand.setAttribute('aria-pressed', on ? 'true' : 'false');
        remeasure();
      });
    } else if (expand) {
      // No fullscreen here. A button that does nothing is worse than no
      // button, so it goes.
      expand.hidden = true;
    }

    window.addEventListener('resize', remeasure);

    apply();
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-gis]').forEach(setUp);
  });
})();
