/*
 * The card form on the reservation page.
 *
 * THE CARD NUMBER NEVER REACHES VISIT DASOL. It goes from this script
 * straight to PayMongo, using the publishable key, and what comes back is
 * a token. Our own server is told the token and nothing else — it could
 * not log a card number if somebody asked it to.
 *
 * The four steps, in order:
 *
 *   1. ask our server to open a payment  -> intent id + client key
 *   2. send the card to PayMongo         -> payment method id
 *   3. attach the method to the intent   -> paid, or a 3-D Secure redirect
 *   4. hand back to our server to confirm, which asks PayMongo itself
 *
 * Step 4 matters more than it looks. This script knows whether the payment
 * worked, and a script can be made to say anything; the server never takes
 * its word for it.
 */
(function () {
    'use strict';

    var form = document.querySelector('[data-card-form]');

    if (!form) {
        return;
    }

    var API = 'https://api.paymongo.com/v1';
    var button = form.querySelector('[data-card-submit]');
    var errorBox = form.querySelector('[data-card-error]');
    var busyLabel = button ? button.getAttribute('data-busy') : 'Working…';
    var idleLabel = button ? button.innerHTML : '';

    function fail(message) {
        if (errorBox) {
            errorBox.textContent = message;
            errorBox.hidden = false;
            errorBox.scrollIntoView({ block: 'center', behavior: 'smooth' });
        }

        if (button) {
            button.disabled = false;
            button.innerHTML = idleLabel;
        }
    }

    function busy() {
        if (errorBox) {
            errorBox.hidden = true;
        }

        if (button) {
            button.disabled = true;
            button.textContent = busyLabel;
        }
    }

    /* PayMongo authenticates with the key as the HTTP Basic username and
       no password, the same as the server side does with its own key. */
    function paymongo(path, key, body) {
        return fetch(API + path, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Authorization: 'Basic ' + btoa(key + ':')
            },
            body: JSON.stringify(body)
        }).then(function (response) {
            return response.json().then(function (json) {
                if (!response.ok) {
                    var detail = json && json.errors && json.errors[0] && json.errors[0].detail;

                    throw new Error(detail || 'The card could not be processed.');
                }

                return json;
            });
        });
    }

    /* "12 / 30", "12/2030" and "1230" all mean the same thing to a person
       typing in a hurry, so they mean the same thing here. */
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

        // A two-digit year is this century; nobody is entering 1930.
        if (year < 100) {
            year += 2000;
        }

        return { month: month, year: year };
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();

        var dates = expiry(form.querySelector('[name="card_expiry"]').value);

        if (!dates) {
            fail('Check the expiry date. It goes in as MM/YY.');

            return;
        }

        busy();

        var payment;

        // 1. Our server opens the payment and says what to attach to.
        fetch(form.getAttribute('action'), {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': form.querySelector('[name="_token"]').value,
                Accept: 'application/json'
            }
        }).then(function (response) {
            return response.json().then(function (json) {
                if (!response.ok) {
                    throw new Error(json.message || 'The payment could not be started.');
                }

                return json;
            });
        }).then(function (opened) {
            payment = opened;

            // 2. The card goes to PayMongo. This is the only request that
            //    carries it, and it does not come near our server.
            return paymongo('/payment_methods', opened.public_key, {
                data: {
                    attributes: {
                        type: 'card',
                        details: {
                            card_number: form.querySelector('[name="card_number"]').value.replace(/\s/g, ''),
                            exp_month: dates.month,
                            exp_year: dates.year,
                            cvc: form.querySelector('[name="card_cvc"]').value.trim()
                        },
                        billing: {
                            name: form.querySelector('[name="card_name"]').value.trim(),
                            email: opened.billing.email
                        }
                    }
                }
            });
        }).then(function (method) {
            // 3. Attach it. The client key may do this and nothing else.
            return paymongo(
                '/payment_intents/' + payment.intent_id + '/attach?client_key=' + encodeURIComponent(payment.client_key),
                payment.public_key,
                {
                    data: {
                        attributes: {
                            payment_method: method.data.id,
                            return_url: payment.return_url
                        }
                    }
                }
            );
        }).then(function (intent) {
            var attributes = intent.data.attributes;
            var next = attributes.next_action;

            // The bank wants the cardholder to confirm. Most Philippine
            // cards do, so this is the usual path rather than the odd one.
            if (next && next.redirect && next.redirect.url) {
                window.location.href = next.redirect.url;

                return;
            }

            if (attributes.status === 'succeeded') {
                window.location.href = payment.return_url;

                return;
            }

            var last = attributes.last_payment_error;

            throw new Error(
                (last && (last.detail || last.message)) ||
                'The card was not accepted. Try another card, or pay at the resort.'
            );
        }).catch(function (error) {
            fail(error.message || 'Something went wrong. Please try again.');
        });
    });
}());
