import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { withI18n } from './lib/withI18n';

const appName = import.meta.env.VITE_APP_NAME || 'Scalenow Bd';

// Wheel hardening (Round 3 fix batch): a mouse wheel over a FOCUSED
// number input silently steps its value in every browser, so a seller
// scrolling a form mid-edit could save a price they never typed (the
// corrupted-price probe in Round 3 traced the bug to exactly this).
// One delegated handler for the whole app: when the wheel lands on
// the focused number input, blur it - the typed value stays and the
// page scrolls instead.
if (typeof window !== 'undefined') {
    window.addEventListener('wheel', (event) => {
        const el = event.target;
        if (el instanceof HTMLInputElement && el.type === 'number' && document.activeElement === el) {
            el.blur();
        }
    }, { passive: true });
}

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.jsx`,
            import.meta.glob('./Pages/**/*.jsx'),
        ).then((module) => withI18n(module.default)),
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(<App {...props} />);
    },
    progress: {
        color: '#411550',
    },
});
