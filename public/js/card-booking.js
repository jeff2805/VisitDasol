/*
 * The card on the booking form.
 *
 * ONE JOB: turn the card into a token before the form is sent, so the
 * number reaches PayMongo and nothing else. The four inputs it reads have
 * no name attribute, so the browser could not post them even if this file
 * were deleted — what the server receives is pm_xxx in a hidden field.
 *
 * WHY TOKENISE BEFORE SUBMITTING RATHER THAN AFTER. Creating a payment
 * method needs no 3-D Secure; the bank only asks to see the cardholder
 * when the money is taken. Doing it in this order means the booking form
 * is still on screen, with everything typed into it, right up to the
 * moment the server has both the booking and a token. The redirect to the
 * bank — when there is one — happens afterwards, from a page that has
 * already saved the reservation.
 */
(function () {
    'use strict';

    var form = document.querySelector('[data-booking-form], form[data-submit-once]');
    var panel = document.querySelector('[data-card-panel]');

    if (!form || !panel) {
        return;
    }

    var API = 'https://api.paymongo.com/v1/payment_methods';
    var tokenField = form.querySelector('[data-card-token]');
    var errorBox = panel.querySelector('[data-card-error]');
    var publicKey = panel.getAttribute('data-public-key');
    var billingEmail = panel.getAttribute('data-billing-email');
    var tokenised = false;

    function field(name) {
        return panel.querySelector('[data-card-field="' + name + '"]');
    }

    function chosenCard() {
        var selected = form.querySelector('[data-payment-preference]:checked');

        return !!selected && selected.value === 'online';
    }

    function fail(message) {
        if (errorBox) {
            errorBox.textContent = message;
            errorBox.hidden = false;
            errorBox.scrollIntoView({ block: 'center', behavior: 'smooth' });
        }

        /* The form's own submit-once guard has already disabled the
           button by now; a card that was refused has to be able to try
           again, so it goes back on. */
        form.querySelectorAll('button[type="submit"]').forEach(function (button) {
            button.disabled = false;
        });
    }

    /* "12 / 30", "12/2030" and "1230" are the same thing to somebody
       typing in a hurry, so they are the same thing here. */
    function expiry(value) {
        var digits = (value || '').replace(/\D/g, '');

        if (digits.length < 4) {
            return null;
        }

        var month = parseInt(digits.slice(0, 2), 10);
        var year = parseInt(digits.slice(2), 10);

        if (!month || month < 1 || month > 12) {
            return null;
        }

        return { month: month, year: year < 100 ? year + 2000 : year };
    }

    form.addEventListener('submit', function (event) {
        // Not paying by card, or the card is already a token: let it go.
        if (!chosenCard() || tokenised) {
            return;
        }

        event.preventDefault();

        var dates = expiry(field('expiry').value);

        if (!dates) {
            fail('Check the expiry date. It goes in as MM/YY.');

            return;
        }

        if (errorBox) {
            errorBox.hidden = true;
        }

        fetch(API, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Authorization: 'Basic ' + btoa(publicKey + ':')
            },
            body: JSON.stringify({
                data: {
                    attributes: {
                        type: 'card',
                        details: {
                            card_number: field('number').value.replace(/\s/g, ''),
                            exp_month: dates.month,
                            exp_year: dates.year,
                            cvc: field('cvc').value.trim()
                        },
                        billing: {
                            name: field('name').value.trim(),
                            email: billingEmail
                        }
                    }
                }
            })
        }).then(function (response) {
            return response.json().then(function (json) {
                if (!response.ok) {
                    var detail = json && json.errors && json.errors[0] && json.errors[0].detail;

                    throw new Error(detail || 'That card was not accepted.');
                }

                return json;
            });
        }).then(function (method) {
            tokenField.value = method.data.id;
            tokenised = true;

            /*
             * The card number is cleared from the page before the form
             * goes. It has done its job, it is not being sent, and a
             * number sitting in an input is a number in a screenshot, a
             * browser's autofill cache, or a back button away.
             */
            ['number', 'cvc'].forEach(function (name) {
                field(name).value = '';
            });

            form.submit();
        }).catch(function (error) {
            fail(error.message || 'The card could not be checked. Please try again.');
        });
    });
}());
