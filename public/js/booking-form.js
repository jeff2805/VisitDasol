
/*
 * VisitDasol — Booking Request Form
 *
 * Browser calculations are for display and guidance only.
 * BookingService must recalculate price, availability, capacity,
 * and applicable discounts on the server.
 *
 * Features:
 * - Adults + Children = Total Guests
 * - PWD count validation (PWD guests are included in total guests)
 * - Male/Female guest count guidance
 * - Room capacity and bed count display
 * - Live price summary, child and PWD discounts included
 * - Manual GCash details toggle
 */
(function () {
  'use strict';

  var PESO = '₱';

  function money(centavos) {
    return PESO + ((Number(centavos) || 0) / 100).toLocaleString('en-PH', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    });
  }

  // A rate off a data attribute is in pesos; everything below counts in
  // centavos, as App\Support\Money does.
  function pesos(amount) {
    return money(Math.round((Number(amount) || 0) * 100));
  }

  /*
   * What the guest is buying. Must match App\Enums\BookingType, and the
   * rate keys must match the data attributes on each room option.
   */
  var TYPE_LABELS = {
    overnight: 'Overnight stay',
    day_tour: 'Day tour',
    night_tour: 'Night tour'
  };

  var TYPE_RATES = {
    overnight: 'price',
    day_tour: 'dayTourPrice',
    night_tour: 'nightTourPrice'
  };

  // Zero, including for a blank attribute, means the room is not sold
  // that way at all. It never falls back to the nightly rate.
  function rateFor(option, type) {
    return parseFloat(option.dataset[TYPE_RATES[type] || 'price']) || 0;
  }

  /*
   * The same sums as App\Services\BookingPricer, in the same order and
   * in the same whole centavos, so the figure a guest reads here is the
   * figure the server goes on to charge. Change the two together.
   *
   * A room is priced by the night, so a discount belonging to one guest
   * comes off that guest's share of the room bill. PWD guests are read
   * against the adults first, which leaves the smallest overlap with the
   * children the stated counts allow, and anyone still in both groups is
   * discounted once, at whichever rate is kinder to them.
   */
  function quote(input) {
    var guests = input.adults + input.children;
    var pwdGuests = Math.max(0, Math.min(input.pwdGuests, guests));

    /*
     * A night is sold by the night; a tour is sold by the booking.
     * Multiplying a tour by nights would make it free, because a tour
     * has none.
     */
    var subtotal = input.overnight
      ? Math.round(input.price * 100) * input.nights * input.rooms
      : Math.round(input.price * 100) * input.rooms;

    var pwdAdults = Math.min(pwdGuests, input.adults);
    var pwdChildren = pwdGuests - pwdAdults;
    var plainChildren = Math.max(0, input.children - pwdChildren);

    var childrenDiscounted = plainChildren +
      (input.childPercent >= input.pwdPercent ? pwdChildren : 0);

    var pwdDiscounted = pwdAdults +
      (input.pwdPercent > input.childPercent ? pwdChildren : 0);

    function shareOf(discountedGuests, percent) {
      if (subtotal < 1 || guests < 1 || discountedGuests < 1 || percent < 1) {
        return 0;
      }

      // Divided last and truncated, so a share that does not divide
      // evenly rounds towards the resort and never by more than a centavo.
      return Math.floor(
        (subtotal * Math.min(discountedGuests, guests) * percent) /
        (guests * 100)
      );
    }

    var child = Math.min(subtotal, shareOf(childrenDiscounted, input.childPercent));
    var pwd = Math.min(subtotal - child, shareOf(pwdDiscounted, input.pwdPercent));

    return {
      subtotal: subtotal,
      childDiscount: child,
      pwdDiscount: pwd,
      total: subtotal - child - pwd,
      childPercent: child > 0 ? input.childPercent : 0,
      pwdPercent: pwd > 0 ? input.pwdPercent : 0,
      childrenDiscounted: child > 0 ? childrenDiscounted : 0,
      pwdDiscounted: pwd > 0 ? pwdDiscounted : 0
    };
  }

  function plural(count, word) {
    return count + ' ' + word + (count === 1 ? '' : 's');
  }

  function getNumber(field, fallback) {
    if (!field || field.value === '') {
      return fallback;
    }

    var value = parseInt(field.value, 10);

    return Number.isFinite(value) ? value : fallback;
  }

  function nightsBetween(from, to) {
    if (!from || !to) {
      return 0;
    }

    var start = Date.parse(from + 'T00:00:00');
    var end = Date.parse(to + 'T00:00:00');

    if (isNaN(start) || isNaN(end) || end <= start) {
      return 0;
    }

    return Math.round((end - start) / 86400000);
  }

  function readableTime(value) {
    if (!/^\d{2}:\d{2}$/.test(value || '')) {
      return '';
    }

    var at = new Date('2000-01-01T' + value + ':00');

    return isNaN(at.getTime())
      ? ''
      : at.toLocaleTimeString('en-PH', {
        hour: 'numeric',
        minute: '2-digit'
      });
  }

  // One date, for a tour: it starts and ends on the same day, whatever
  // the engine stores to hold the room.
  function readableDate(value) {
    if (!value) {
      return '';
    }

    var day = new Date(value + 'T00:00:00');

    if (isNaN(day.getTime())) {
      return '';
    }

    return day.toLocaleDateString('en-PH', {
      month: 'short',
      day: 'numeric',
      year: 'numeric'
    });
  }

  function readableDates(from, to) {
    if (!from || !to) {
      return '';
    }

    var start = new Date(from + 'T00:00:00');
    var end = new Date(to + 'T00:00:00');

    if (isNaN(start.getTime()) || isNaN(end.getTime())) {
      return '';
    }

    return start.toLocaleDateString('en-PH', {
      month: 'short',
      day: 'numeric'
    }) + ' to ' + end.toLocaleDateString('en-PH', {
      month: 'short',
      day: 'numeric',
      year: 'numeric'
    });
  }

  /*
   * Manual GCash details.
   *
   * Required HTML attributes:
   * data-payment-preference
   * data-manual-gcash-details
   */
  function setUpPaymentPreference(form) {
    var details = form.querySelector(
      '[data-manual-gcash-details]'
    );

    var radios = form.querySelectorAll(
      '[data-payment-preference]'
    );

    if (!details || !radios.length) {
      return;
    }

    function updateGcashDetails() {
      var selected = form.querySelector(
        '[data-payment-preference]:checked'
      );

      var show = !!selected &&
        selected.value === 'manual_gcash';

      details.hidden = !show;

      details.querySelectorAll('[data-gcash-required]')
        .forEach(function (field) {
          field.required = show;
        });
    }

    radios.forEach(function (radio) {
      radio.addEventListener('change', updateGcashDetails);
    });

    updateGcashDetails();
  }

  function setUp(form) {
    var roomSelect = form.querySelector(
      '[data-booking-room]'
    );

    var roomId = form.querySelector(
      '[data-booking-room-id]'
    );

    var checkIn = form.querySelector(
      '[data-booking-check-in]'
    );

    var checkOut = form.querySelector(
      '[data-booking-check-out]'
    );

    var roomsField = form.querySelector(
      '[data-booking-rooms]'
    );

    var adultsField = form.querySelector(
      '[data-booking-adults]'
    );

    var childrenField = form.querySelector(
      '[data-booking-children]'
    );

    var pwdField = form.querySelector(
      '[data-booking-pwd]'
    );

    var guestsField = form.querySelector(
      '[data-booking-guests]'
    );

    var maleField = form.querySelector(
      '[data-booking-male]'
    );

    var femaleField = form.querySelector(
      '[data-booking-female]'
    );

    var out = {
      roomName: form.querySelector(
        '[data-booking-room-name]'
      ),
      dates: form.querySelector(
        '[data-booking-dates]'
      ),
      nights: form.querySelector(
        '[data-booking-nights]'
      ),
      working: form.querySelector(
        '[data-booking-working]'
      ),
      subtotal: form.querySelector(
        '[data-booking-subtotal]'
      ),
      total: form.querySelector(
        '[data-booking-total]'
      ),
      childRow: form.querySelector(
        '[data-booking-child-row]'
      ),
      childLabel: form.querySelector(
        '[data-booking-child-label]'
      ),
      childDiscount: form.querySelector(
        '[data-booking-child-discount]'
      ),
      pwdRow: form.querySelector(
        '[data-booking-pwd-row]'
      ),
      pwdLabel: form.querySelector(
        '[data-booking-pwd-label]'
      ),
      pwdDiscount: form.querySelector(
        '[data-booking-pwd-discount]'
      ),
      discountNote: form.querySelector(
        '[data-booking-discount-note]'
      ),
      childAges: form.querySelector(
        '[data-booking-child-ages]'
      ),
      childNote: form.querySelector(
        '[data-booking-child-note]'
      ),
      pwdNote: form.querySelector(
        '[data-booking-pwd-note]'
      ),
      capacity: form.querySelector(
        '[data-booking-capacity]'
      ),
      capacitySummary: form.querySelector(
        '[data-booking-capacity-summary]'
      ),
      beds: form.querySelector(
        '[data-booking-beds]'
      ),
      free: form.querySelector(
        '[data-booking-free]'
      ),
      note: form.querySelector(
        '[data-booking-room-note]'
      ),
      sex: form.querySelector(
        '[data-booking-sex]'
      ),
      typeName: form.querySelector(
        '[data-booking-type-name]'
      ),
      nightsTerm: form.querySelector(
        '[data-booking-nights-term]'
      ),
      nightsValue: form.querySelector(
        '[data-booking-nights-value]'
      ),
      timeInSummary: form.querySelector(
        '[data-booking-check-in-time]'
      ),
      timeOutSummary: form.querySelector(
        '[data-booking-check-out-time]'
      )
    };

    var timeIn = form.querySelector(
      '[data-booking-time-in]'
    );

    var timeOut = form.querySelector(
      '[data-booking-time-out]'
    );

    var typeRadios = Array.prototype.slice.call(
      form.querySelectorAll('[data-booking-type]')
    );

    var typeFieldset = form.querySelector(
      '[data-booking-types]'
    );

    var checkOutField = form.querySelector(
      '[data-booking-checkout-field]'
    );

    setUpPaymentPreference(form);

    if (!roomSelect || !checkIn || !checkOut) {
      return;
    }

    var loadedRoom = roomId
      ? roomId.value
      : roomSelect.value;

    var freeText = out.free
      ? out.free.textContent
      : '';

    /*
     * Calculate total guests.
     * PWD guests are included in Adults + Children,
     * not added as a separate guest category.
     */
    function refreshGuestCounts() {
      var adults = Math.max(
        0,
        getNumber(adultsField, 0)
      );

      var children = Math.max(
        0,
        getNumber(childrenField, 0)
      );

      var totalGuests = adults + children;

      if (adultsField && childrenField && guestsField) {
        guestsField.value = totalGuests;
      }

      var pwdGuests = Math.max(
        0,
        getNumber(pwdField, 0)
      );

      if (pwdField) {
        var invalidPwd = pwdGuests > totalGuests;

        pwdField.setCustomValidity(
          invalidPwd
            ? 'PWD guests cannot exceed total guests.'
            : ''
        );
      }

      var option = roomSelect.options[
        roomSelect.selectedIndex
      ];

      var capacity = option
        ? Math.max(0, parseInt(option.dataset.capacity, 10) || 0)
        : 0;

      var rooms = Math.max(
        1,
        getNumber(roomsField, 1)
      );

      var maxGuests = capacity * rooms;

      if (out.capacitySummary && capacity > 0) {
        out.capacitySummary.textContent =
          capacity + ' guests per room (' +
          maxGuests + ' total)';
      }

      if (guestsField && capacity > 0) {
        var overCapacity = totalGuests > maxGuests;

        guestsField.setCustomValidity(
          overCapacity
            ? 'The selected room count accommodates up to ' +
              maxGuests + ' guests.'
            : ''
        );
      }

      countSexes(totalGuests);
    }

    /*
     * Validate male + female counts against total guests.
     */
    function countSexes(totalGuests) {
      if (!out.sex || !maleField || !femaleField) {
        return;
      }

      var maleBlank = maleField.value === '';
      var femaleBlank = femaleField.value === '';

      var male = Math.max(
        0,
        getNumber(maleField, 0)
      );

      var female = Math.max(
        0,
        getNumber(femaleField, 0)
      );

      var stated = male + female;
      var difference = totalGuests - stated;

      out.sex.hidden = false;

      if (maleBlank && femaleBlank) {
        out.sex.textContent =
          'Split your ' + plural(totalGuests, 'guest') +
          ' between male and female. Put 0 where there are none.';
      } else if (difference > 0) {
        out.sex.textContent =
          plural(difference, 'guest') +
          ' still to account for.';
      } else if (difference < 0) {
        out.sex.textContent =
          'That is ' + plural(-difference, 'guest') +
          ' more than the total of ' +
          plural(totalGuests, 'guest') + '.';
      } else {
        out.sex.textContent =
          'That accounts for all ' +
          plural(totalGuests, 'guest') + '.';
      }

      var mismatch = stated !== totalGuests;

      maleField.setCustomValidity(
        mismatch
          ? 'Male and female counts must add up to total guests.'
          : ''
      );

      femaleField.setCustomValidity(
        mismatch
          ? 'Male and female counts must add up to total guests.'
          : ''
      );
    }

    /*
     * The discount lines, and what the selected room's policy is.
     *
     * A line is shown only once there is something on it: a row
     * reading "Child discount ₱0.00" beside a party with no children
     * tells a guest nothing and reads like a refusal.
     */
    function showDiscounts(bill, option) {
      var childPercent = parseInt(option.dataset.childPercent, 10) || 0;
      var pwdPercent = parseInt(option.dataset.pwdPercent, 10) || 0;
      var ages = option.dataset.childAges || '';

      if (out.childRow) {
        out.childRow.hidden = bill.childDiscount < 1;
      }

      if (out.childDiscount) {
        out.childDiscount.textContent = '−' + money(bill.childDiscount);
      }

      if (out.childLabel) {
        out.childLabel.textContent = 'Child discount (' +
          bill.childPercent + '% off ' +
          plural(bill.childrenDiscounted, 'guest') + ')';
      }

      if (out.pwdRow) {
        out.pwdRow.hidden = bill.pwdDiscount < 1;
      }

      if (out.pwdDiscount) {
        out.pwdDiscount.textContent = '−' + money(bill.pwdDiscount);
      }

      if (out.pwdLabel) {
        out.pwdLabel.textContent = 'PWD discount (' +
          bill.pwdPercent + '% off ' +
          plural(bill.pwdDiscounted, 'guest') + ')';
      }

      // The room's own ages beside the Children box, because the resort
      // checks them at the gate and a guest counting to a different
      // definition is a guest who gets a different bill on arrival.
      if (out.childAges) {
        out.childAges.textContent = ages ? '(' + ages + ')' : '';
      }

      if (out.childNote) {
        out.childNote.textContent = childPercent > 0
          ? 'This room takes ' + childPercent + '% off a child\'s share of the bill.'
          : '';
      }

      if (out.pwdNote) {
        out.pwdNote.textContent = pwdPercent > 0
          ? 'Each one takes ' + pwdPercent + '% off their share of the bill. Bring the ID.'
          : '';
      }

      if (out.discountNote) {
        if (childPercent < 1 && pwdPercent < 1) {
          out.discountNote.textContent =
            'This room type offers no child or PWD discount.';
        } else if (bill.childDiscount < 1 && bill.pwdDiscount < 1) {
          out.discountNote.textContent =
            'Add the children or PWD guests in your party to see the discount.';
        } else if (bill.childDiscount > 0 && bill.pwdDiscount > 0) {
          out.discountNote.textContent =
            'A guest who is both a child and a PWD is counted once, ' +
            'at whichever rate is better for them.';
        } else {
          out.discountNote.textContent =
            'The resort confirms this when it answers your request.';
        }
      }
    }

    /*
     * Which kinds the chosen room is sold as, and at what rate.
     *
     * The guest can change room without leaving the form, and the room
     * they move to may not run tours at all. A kind that room does not
     * run is taken off the screen rather than left there to be refused
     * when they press send — and if it was the one they had chosen, the
     * choice falls back to an overnight stay.
     *
     * Returns the kind in force once that reconciling is done.
     */
    function showBookingTypes(option) {
      if (!typeRadios.length) {
        return 'overnight';
      }

      var offered = 0;
      var fallback = null;

      typeRadios.forEach(function (radio) {
        var rate = rateFor(option, radio.value);
        var runs = rate > 0;

        var label = radio.closest('[data-booking-type-option]');
        var price = label
          ? label.querySelector('[data-booking-type-price]')
          : null;

        radio.disabled = !runs;

        if (label) {
          label.hidden = !runs;
        }

        if (price) {
          price.textContent = runs
            ? '— ' + pesos(rate) +
              (radio.value === 'overnight' ? ' per night' : '')
            : '';
        }

        if (runs) {
          offered += 1;
          fallback = fallback || radio;
        }
      });

      var chosen = form.querySelector('[data-booking-type]:checked');

      if ((!chosen || chosen.disabled) && fallback) {
        fallback.checked = true;
        chosen = fallback;
      }

      // Nothing to choose between: don't ask the question.
      if (typeFieldset) {
        typeFieldset.hidden = offered < 2;
      }

      return chosen && !chosen.disabled ? chosen.value : 'overnight';
    }

    /*
     * Update room information and price summary.
     */
    function refresh() {
      var option = roomSelect.options[
        roomSelect.selectedIndex
      ];

      if (!option) {
        return;
      }

      var type = showBookingTypes(option);
      var overnight = type === 'overnight';

      var price = rateFor(option, type);

      var capacity = parseInt(
        option.dataset.capacity,
        10
      ) || 0;

      var beds = parseInt(
        option.dataset.beds,
        10
      ) || 0;

      var rooms = Math.max(
        1,
        getNumber(roomsField, 1)
      );

      /*
       * A tour has none, whatever is sitting in the folded-away
       * check-out box. The server says the same: nights = 0 for a
       * tour, and the day after is only how the room is held.
       */
      var nights = overnight
        ? nightsBetween(checkIn.value, checkOut.value)
        : 0;

      /*
       * A date the guest cannot see is a date they cannot correct, so
       * the browser must not refuse to send the form over one.
       */
      if (checkOutField) {
        checkOutField.hidden = !overnight;
      }

      checkOut.required = overnight;

      var bill = quote({
        price: price,
        nights: nights,
        rooms: rooms,
        overnight: overnight,
        adults: Math.max(0, getNumber(adultsField, 0)),
        children: Math.max(0, getNumber(childrenField, 0)),
        pwdGuests: Math.max(0, getNumber(pwdField, 0)),
        childPercent: parseInt(option.dataset.childPercent, 10) || 0,
        pwdPercent: parseInt(option.dataset.pwdPercent, 10) || 0
      });

      if (roomId) {
        roomId.value = roomSelect.value;
      }

      if (out.roomName) {
        out.roomName.textContent =
          option.textContent.split('—')[0].trim();
      }

      if (out.typeName) {
        out.typeName.textContent =
          TYPE_LABELS[type] || TYPE_LABELS.overnight;
      }

      if (out.timeInSummary && timeIn) {
        out.timeInSummary.textContent =
          readableTime(timeIn.value) || out.timeInSummary.textContent;
      }

      if (out.timeOutSummary && timeOut) {
        out.timeOutSummary.textContent =
          readableTime(timeOut.value) || out.timeOutSummary.textContent;
      }

      if (out.dates) {
        out.dates.textContent = (overnight
          ? readableDates(checkIn.value, checkOut.value)
          : readableDate(checkIn.value)) ||
          (overnight ? 'Choose your dates' : 'Choose your date');
      }

      if (out.nights) {
        out.nights.textContent = nights;
      }

      // Hidden rather than zeroed: "0 nights" beside a day tour reads
      // like the form lost the booking.
      if (out.nightsTerm) {
        out.nightsTerm.hidden = !overnight;
      }

      if (out.nightsValue) {
        out.nightsValue.hidden = !overnight;
      }

      if (out.working) {
        if (!overnight) {
          out.working.textContent =
            pesos(price) + ' x ' + plural(rooms, 'room');
        } else if (nights > 0) {
          out.working.textContent =
            pesos(price) + ' x ' +
            plural(nights, 'night') + ' x ' +
            plural(rooms, 'room');
        } else {
          out.working.textContent = pesos(price) + ' per night';
        }
      }

      if (out.subtotal) {
        out.subtotal.textContent = money(bill.subtotal);
      }

      if (out.total) {
        out.total.textContent = money(bill.total);
      }

      showDiscounts(bill, option);

      if (out.beds) {
        // The beds the party actually gets, not the beds in one room.
        out.beds.textContent = beds > 0
          ? beds * rooms
          : 'Not specified';
      }

      if (out.capacity && capacity > 0) {
        out.capacity.textContent =
          'Up to ' + capacity + ' per room; ' +
          plural(capacity * rooms, 'guest') + ' total.';
      }

      // Do not reuse availability for a different room type.
      var switched = roomSelect.value !== loadedRoom;

      if (out.free) {
        out.free.hidden = switched;

        if (!switched) {
          out.free.textContent = freeText;
        }
      }

      if (out.note) {
        out.note.hidden = !switched;
      }

      refreshGuestCounts();
    }

    /*
     * Choosing a kind brings its hours with it. Only on an actual change
     * of the choice, so a guest who then sets their own arrival time
     * keeps it — a tour's hours are a sensible start, not a rule.
     */
    typeRadios.forEach(function (radio) {
      radio.addEventListener('change', function () {
        if (!radio.checked) {
          return;
        }

        if (timeIn && radio.dataset.hoursIn) {
          timeIn.value = radio.dataset.hoursIn;
        }

        if (timeOut && radio.dataset.hoursOut) {
          timeOut.value = radio.dataset.hoursOut;
        }
      });
    });

    typeRadios.concat([
      roomSelect,
      timeIn,
      timeOut,
      checkIn,
      checkOut,
      roomsField,
      adultsField,
      childrenField,
      pwdField,
      guestsField,
      maleField,
      femaleField
    ]).forEach(function (field) {
      if (field) {
        field.addEventListener('change', refresh);
        field.addEventListener('input', refresh);
      }
    });

    refresh();
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll(
      '[data-booking-form]'
    ).forEach(setUp);
  });
})();
