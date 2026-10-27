/*
 * Visit Dasol shared JavaScript (no build step, no frameworks).
 * Requires Bootstrap's bundle, which is loaded before this file.
 *
 *   data-autodismiss            on an .alert: closes it after 6 seconds
 *   data-confirm="Message"      on a <form>: asks before submitting
 *   data-submit-once            on a <form>: disables its submit buttons after the first submit
 *   data-auto-submit            on a <select>: sends its form as soon as the choice changes
 *   data-sidebar-toggle         on a button: opens or folds away the dashboard sidebar
 *   data-password-toggle        on a button: shows or hides the password it controls
 *   data-discount-toggle="x"    on a checkbox: reveals [data-discount-fields="x"]
 *   data-countdown="90"         counts those seconds down in the page
 *   data-print                  on a button: prints the page
 *   data-bs-toggle="tooltip"    Bootstrap tooltips are switched on automatically
 */
(function () {
  'use strict';

  /*
   * Print this page.
   *
   * A button rather than "press Ctrl+P", because the people printing a
   * receipt at a gate are doing it on a shared machine in a hurry. The
   * handler lives here and not in an onclick, which the Content
   * Security Policy blocks outright.
   */
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-print]').forEach(function (button) {
      button.hidden = false;

      button.addEventListener('click', function (event) {
        event.preventDefault();
        window.print();
      });
    });
  });

  /*
   * A stay cannot end before it begins.
   *
   * Choosing a check-in date pushes the check-out box's minimum to the
   * day after, and drags a now-impossible check-out along with it.
   *
   * This was an inline <script> in stay-search.blade.php, which the
   * Content Security Policy blocks — so it never ran for anybody, and
   * the form happily accepted a check-out before the arrival. Here it
   * actually runs.
   */
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-stay-check-in]').forEach(function (checkIn) {
      var form = checkIn.form || document;
      var checkOut = form.querySelector('[data-stay-check-out]');

      if (!checkOut) {
        return;
      }

      function dayAfter(value) {
        var next = new Date(value + 'T00:00:00');

        if (isNaN(next.getTime())) {
          return '';
        }

        next.setDate(next.getDate() + 1);

        // Built by hand rather than with toISOString(), which converts
        // to UTC and lands on the wrong day for most of a Philippine
        // afternoon.
        return next.getFullYear() + '-'
          + String(next.getMonth() + 1).padStart(2, '0') + '-'
          + String(next.getDate()).padStart(2, '0');
      }

      function follow() {
        if (!checkIn.value) {
          checkOut.min = '';

          return;
        }

        var earliest = dayAfter(checkIn.value);

        if (earliest === '') {
          return;
        }

        checkOut.min = earliest;

        // A check-out that is now in the past is moved, not left to be
        // refused by the server after the guest has filled the rest in.
        if (checkOut.value && checkOut.value < earliest) {
          checkOut.value = earliest;
        }
      }

      checkIn.addEventListener('change', follow);
      follow();
    });
  });

  /*
   * The dashboard menu button. Below the large breakpoint the sidebar is a
   * Bootstrap offcanvas and the button opens it; at large sizes the sidebar is
   * part of the page, so the button folds it away to give the page the width.
   * Which it is, is remembered for the next page.
   */
  var TUCKED = 'vd.sidebar.tucked';
  var wide = window.matchMedia('(min-width: 992px)');

  function showSidebar(open) {
    var shell = document.querySelector('.vd-shell');

    if (shell) {
      shell.classList.toggle('vd-shell--tucked', !open);
    }

    document.querySelectorAll('[data-sidebar-toggle]').forEach(function (button) {
      button.setAttribute('aria-expanded', open ? 'true' : 'false');
    });

    try {
      window.localStorage.setItem(TUCKED, open ? '0' : '1');
    } catch (error) {
      // Private browsing can refuse storage; the page works either way.
    }
  }

  function restoreSidebar() {
    var tucked = false;

    try {
      tucked = window.localStorage.getItem(TUCKED) === '1';
    } catch (error) {
      tucked = false;
    }

    if (wide.matches && tucked) {
      showSidebar(false);
    }
  }

  document.addEventListener('click', function (event) {
    if (!event.target.closest('[data-sidebar-toggle]')) {
      return;
    }

    var sidebar = document.getElementById('vdSidebar');

    if (!sidebar) {
      return;
    }

    if (wide.matches) {
      var shell = document.querySelector('.vd-shell');
      showSidebar(shell ? shell.classList.contains('vd-shell--tucked') : true);
      return;
    }

    bootstrap.Offcanvas.getOrCreateInstance(sidebar).toggle();
  });

  document.addEventListener('DOMContentLoaded', function () {
    restoreSidebar();

    // Tooltips
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (element) {
      bootstrap.Tooltip.getOrCreateInstance(element);
    });

    // If a dialog's form was sent back with a validation error, open it again
    // so the person can see what went wrong instead of a silently closed dialog.
    var invalidField = document.querySelector('.modal .is-invalid');
    if (invalidField) {
      var dialog = invalidField.closest('.modal');
      if (dialog) {
        bootstrap.Modal.getOrCreateInstance(dialog).show();
        invalidField.focus({ preventScroll: true });
      }
    }

    // A table that scrolls sideways must be reachable with the keyboard,
    // otherwise someone not using a mouse cannot see its right-hand columns.
    // Only tables that actually overflow are made focusable, so nothing
    // gains a stray tab stop.
    var labelScrollableTables = function () {
      document.querySelectorAll('.table-responsive').forEach(function (wrapper) {
        var scrolls = wrapper.scrollWidth > wrapper.clientWidth;

        if (!scrolls) {
          wrapper.removeAttribute('tabindex');
          wrapper.removeAttribute('role');
          return;
        }

        wrapper.setAttribute('tabindex', '0');
        wrapper.setAttribute('role', 'region');

        if (!wrapper.hasAttribute('aria-label')) {
          var section = wrapper.closest('section, article, .vd-panel');
          var heading = section ? section.querySelector('h1, h2, h3, h4, h5, h6') : null;
          wrapper.setAttribute('aria-label', heading ? heading.textContent.trim() : 'Table, scrolls sideways');
        }
      });
    };

    labelScrollableTables();
    window.addEventListener('resize', labelScrollableTables);

    // Auto-dismiss success messages. Errors stay until the user closes them.
    document.querySelectorAll('.alert[data-autodismiss]').forEach(function (alertElement) {
      window.setTimeout(function () {
        bootstrap.Alert.getOrCreateInstance(alertElement).close();
      }, 6000);
    });

    document.querySelectorAll('[data-countdown]').forEach(startCountdown);
  });

  // Filters that apply themselves: <select data-auto-submit>. Every such form
  // keeps a real submit button (visually hidden) so it still works with the
  // keyboard alone and when JavaScript does not run.
  document.addEventListener('change', function (event) {
    var field = event.target;

    if (field.matches('select[data-auto-submit]') && field.form) {
      field.form.requestSubmit ? field.form.requestSubmit() : field.form.submit();
    }
  });

  /*
   * Show or hide a password: <button data-password-toggle aria-controls="id">.
   * Nothing is stored and nothing is sent; only the field's own type changes,
   * so the password is revealed to whoever is already looking at the screen
   * and to nobody else. It hides itself again on the next page load.
   */
  document.addEventListener('click', function (event) {
    var toggle = event.target.closest('[data-password-toggle]');

    if (!toggle) {
      return;
    }

    var field = document.getElementById(toggle.getAttribute('aria-controls'));

    if (!field) {
      return;
    }

    var reveal = field.type === 'password';

    // Changing the type moves the caret to the end in some browsers, which
    // loses someone's place halfway through typing.
    var start = null;
    var end = null;
    try {
      start = field.selectionStart;
      end = field.selectionEnd;
    } catch (error) {
      start = null;
    }

    field.type = reveal ? 'text' : 'password';

    toggle.setAttribute('aria-pressed', reveal ? 'true' : 'false');

    /*
     * The icon reports the STATE of the password, not the action the
     * button would take: an open eye means the characters are on
     * screen, a crossed-out eye means they are not.
     *
     * That is how the eye is read everywhere else in this project —
     * bi-eye-slash sits beside "hidden from the public", bi-eye beside
     * "this listing is live" — and an icon that meant one thing on a
     * resort page and the opposite on a sign-in form is why this
     * button felt backwards.
     */
    var icon = toggle.querySelector('.bi');
    if (icon) {
      icon.className = reveal ? 'bi bi-eye' : 'bi bi-eye-slash';
    }

    /*
     * The accessible name deliberately does NOT change.
     *
     * It used to flip to "Hide password" at the same moment
     * aria-pressed became true, so a screen reader announced "Hide
     * password, pressed" — saying hiding was switched on while the
     * password was in plain sight. A toggle button carries its state
     * in aria-pressed and keeps one name: "Show password, pressed"
     * reads correctly.
     */

    // Back to the field so typing can carry on, keyboard and all on a phone.
    field.focus();
    if (start !== null) {
      try {
        field.setSelectionRange(start, end);
      } catch (error) {
        // Some browsers refuse this on a field that just changed type.
      }
    }
  });

  /*
   * A discount's figures, shown only while the discount is switched on:
   *
   *   <input type="checkbox" data-discount-toggle="child">
   *   <div data-discount-fields="child" hidden> ... </div>
   *
   * The fields are hidden rather than removed, so what an owner typed
   * is still there if they tick the box again. They are disabled while
   * hidden so the browser cannot refuse to submit the form over a
   * required box nobody can see, and the server reads the same thing
   * either way: a discount that is off stores zero.
   */
  function showDiscountFields(toggle) {
    var fields = document.querySelector(
      '[data-discount-fields="' + toggle.dataset.discountToggle + '"]'
    );

    if (!fields) {
      return;
    }

    fields.hidden = !toggle.checked;

    fields.querySelectorAll('input, select').forEach(function (field) {
      field.disabled = !toggle.checked;
    });
  }

  document.addEventListener('change', function (event) {
    var toggle = event.target.closest('[data-discount-toggle]');

    if (toggle) {
      showDiscountFields(toggle);
    }
  });

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-discount-toggle]')
      .forEach(showDiscountFields);
  });

  /*
   * The deposit box on the payment terms page, shown only while the
   * owner has actually chosen a deposit. A percentage means nothing
   * beside "no advance payment" or "the full amount".
   *
   * Hidden, not removed, so a figure already typed survives a change of
   * mind. The server ignores it either way: PaymentPolicyRequest only
   * reads the percentage when the requirement is a deposit.
   */
  function showDepositFields() {
    var fields = document.querySelector('[data-deposit-fields]');
    var chosen = document.querySelector('[data-requirement]:checked');

    if (!fields || !chosen) {
      return;
    }

    fields.hidden = chosen.value !== 'deposit';
  }

  document.addEventListener('change', function (event) {
    if (event.target.closest('[data-requirement]')) {
      showDepositFields();
    }
  });

  document.addEventListener('DOMContentLoaded', showDepositFields);

  /*
   * The GCash boxes on the environmental fee form, shown only when the
   * collector says the visitor paid that way. A reference number means
   * nothing beside a cash payment.
   *
   * Hidden, not removed, and the server ignores them either way:
   * exclude_unless on the request drops them unless the method is
   * gcash.
   */
  function showFeeGcashFields() {
    var fields = document.querySelector('[data-fee-gcash-fields]');
    var chosen = document.querySelector('[data-fee-method]:checked');

    if (!fields || !chosen) {
      return;
    }

    fields.hidden = chosen.value !== 'gcash';
  }

  document.addEventListener('change', function (event) {
    if (event.target.closest('[data-fee-method]')) {
      showFeeGcashFields();
    }
  });

  document.addEventListener('DOMContentLoaded', showFeeGcashFields);

  /*
   * A number of seconds counted down in the page:
   *
   *   <p data-countdown="52" data-countdown-enables="resend-button">
   *     <span data-countdown-waiting>Another code in <b data-countdown-left></b>.</span>
   *     <span data-countdown-done hidden>You can ask for another code now.</span>
   *   </p>
   *
   * Without this the server's figure is written once and then goes stale, so
   * a waiting page claims "52 seconds" forever and the button it guards stays
   * disabled until the page is reloaded.
   *
   * Only the display is handled here. The server checks the cooldown again
   * when the form is sent, so a clock nudged forward in the browser gains
   * nothing.
   */
  function startCountdown(element) {
    var left = parseInt(element.dataset.countdown, 10);

    if (isNaN(left)) {
      return;
    }

    var slot = element.querySelector('[data-countdown-left]');
    var waiting = element.querySelector('[data-countdown-waiting]');
    var done = element.querySelector('[data-countdown-done]');
    var freed = element.dataset.countdownEnables
      ? document.getElementById(element.dataset.countdownEnables)
      : null;
    var clock = element.dataset.countdownFormat === 'clock';

    var show = function (seconds) {
      if (!slot) {
        return;
      }

      if (clock) {
        slot.textContent = Math.floor(seconds / 60) + ':' + ('0' + (seconds % 60)).slice(-2);
        return;
      }

      slot.textContent = seconds + (seconds === 1 ? ' second' : ' seconds');
    };

    var finish = function () {
      if (waiting) { waiting.hidden = true; }
      if (done) { done.hidden = false; }
      if (freed) { freed.disabled = false; }
    };

    if (left <= 0) {
      finish();
      return;
    }

    if (waiting) { waiting.hidden = false; }
    if (done) { done.hidden = true; }
    show(left);

    var tick = window.setInterval(function () {
      left -= 1;

      if (left <= 0) {
        window.clearInterval(tick);
        finish();
        return;
      }

      show(left);
    }, 1000);
  }

  // Print buttons: <button data-print>. Handled here because the Content
  // Security Policy blocks inline onclick handlers.
  document.addEventListener('click', function (event) {
    if (event.target.closest('[data-print]')) {
      window.print();
    }
  });

  // Confirmation before submitting sensitive forms (approve, reject, cancel, delete).
  // Phase 4 upgrades this to a Bootstrap modal; forms keep the same attribute.
  document.addEventListener('submit', function (event) {
    var form = event.target;

    if (form.hasAttribute('data-confirm') && !form.dataset.confirmed) {
      if (!window.confirm(form.getAttribute('data-confirm'))) {
        event.preventDefault();
        return;
      }
    }

    if (form.hasAttribute('data-submit-once')) {
      if (form.dataset.submitting === 'true') {
        event.preventDefault();
        return;
      }
      form.dataset.submitting = 'true';
      form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (button) {
        button.disabled = true;
        if (button.tagName === 'BUTTON') {
          var spinner = document.createElement('span');
          spinner.className = 'spinner-border spinner-border-sm ms-2';
          spinner.setAttribute('aria-hidden', 'true');
          button.appendChild(spinner);
        }
      });
    }
  });

  // Browsers restore pages from the back/forward cache with buttons still disabled.
  window.addEventListener('pageshow', function (event) {
    if (!event.persisted) {
      return;
    }
    document.querySelectorAll('form[data-submit-once]').forEach(function (form) {
      delete form.dataset.submitting;
      form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (button) {
        button.disabled = false;
        button.querySelectorAll('.spinner-border').forEach(function (spinner) {
          spinner.remove();
        });
      });
    });
  });
})();
