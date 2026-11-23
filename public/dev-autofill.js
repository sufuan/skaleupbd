/*
 * dev-autofill.js - DEVELOPMENT convenience only. Loaded by the login page.
 *
 * The two buttons this file adds ("Admin Login" and "Seller Login")
 * ONLY fill the email and password boxes of the login form. Nothing is
 * submitted, fetched, or redirected: the user still presses the normal
 * Login button and the standard auth flow (including TOTP when enabled)
 * runs exactly as for any other user.
 *
 * Which accounts are filled depends on the host:
 * - Local / test hosts: the seeded LOCAL TEST accounts (see below). These
 *   exist only on a developer machine and are not real credentials.
 * - scalenowbd.com (live, site still under development): the Dashboard
 *   button fills the live CRUD test seller account. The Admin button
 *   fills the platform admin email plus whatever the owner has put in
 *   LIVE_ADMIN_PASSWORD below.
 *
 * OWNER: to make the live Admin button fill the password too, put the
 * value between the quotes of LIVE_ADMIN_PASSWORD (first line of code
 * below). While it stays empty, the live Admin button fills the email
 * only and leaves the password box untouched.
 *
 * PRE-LAUNCH: this is a public file - anyone can read it. DELETE this
 * whole file (and the one script-loading block in the login page) before
 * the real launch. The login page works completely normally without it.
 */
(function () {
    'use strict';

    // Live admin password, placed here on the owner's explicit order
    // (site still under development). Remove this whole file before
    // real launch. Empty = email only.
    const LIVE_ADMIN_PASSWORD = "7T*lp]EB35Pl6l";

    var isLive = window.location.hostname === 'scalenowbd.com' ||
        window.location.hostname === 'www.scalenowbd.com';

    var accounts;
    if (isLive) {
        accounts = [
            { label: 'Admin Login', email: 'admin@admin.com', password: LIVE_ADMIN_PASSWORD },
            { label: 'Seller Login', email: 'crud.seller@scalenowbd.com', password: 'CrudSeller2026!' }
        ];
    } else {
        accounts = [
            { label: 'Admin Login', email: 'platform@skaleup.test', password: 'password' },
            { label: 'Seller Login', email: 'admin@skaleup.test', password: 'password' }
        ];
    }

    // The login inputs are React-controlled, so set the value through the
    // native setter and dispatch an 'input' event for React to pick it up.
    function setReactValue(input, value) {
        var descriptor = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value');
        if (descriptor && descriptor.set) {
            descriptor.set.call(input, value);
        } else {
            input.value = value;
        }
        input.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function findLoginForm() {
        var forms = document.querySelectorAll('form');
        for (var i = 0; i < forms.length; i++) {
            if (forms[i].querySelector('input[name="email"]') &&
                forms[i].querySelector('input[name="password"]')) {
                return forms[i];
            }
        }
        return null;
    }

    function inject() {
        var form = findLoginForm();
        if (!form) {
            return false;
        }
        if (form.parentNode.querySelector('[data-dev-autofill]')) {
            return true; // Already injected; never add the buttons twice.
        }

        var wrap = document.createElement('div');
        wrap.setAttribute('data-dev-autofill', '1');
        wrap.style.cssText = 'display:flex;gap:10px;margin-top:10px;flex-wrap:wrap;';

        accounts.forEach(function (account) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = account.label;
            btn.style.cssText = 'flex:1 1 140px;padding:11px 16px;border:1px solid #411550;' +
                'color:#411550;background:transparent;border-radius:8px;cursor:pointer;font-size:14px;';
            btn.addEventListener('click', function () {
                var email = form.querySelector('input[name="email"]');
                var password = form.querySelector('input[name="password"]');
                if (email) {
                    setReactValue(email, account.email);
                }
                // An empty password (live Admin before the owner fills the
                // constant in) leaves the password box exactly as it was.
                if (password && account.password) {
                    setReactValue(password, account.password);
                }
            });
            wrap.appendChild(btn);
        });

        form.parentNode.insertBefore(wrap, form.nextSibling);
        return true;
    }

    // The React page may not have rendered the form yet when this runs.
    function tryInject(retriesLeft) {
        if (inject()) {
            return;
        }
        if (retriesLeft > 0) {
            setTimeout(function () { tryInject(retriesLeft - 1); }, 250);
        }
    }

    try {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function () { tryInject(20); });
        } else {
            tryInject(20);
        }
    } catch (e) {
        // Never let this convenience script break the login page.
    }
})();
