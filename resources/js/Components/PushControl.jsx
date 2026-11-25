import { t } from '@/lib/i18n';
import { disablePush, enablePush, pushState, registerPushWorker } from '@/lib/push';
import { useEffect, useState } from 'react';

// Tiny topbar control for browser Web Push (owner order 2026-11-25):
// registers the service worker, and lets the signed-in user turn
// browser notifications on or off for this browser. Per-event choice
// lives in Work Inbox Settings (each rule's push toggle).
export default function PushControl() {
    const [state, setState] = useState(null);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        registerPushWorker();
        pushState().then(setState);
    }, []);

    if (state === null || state === 'unsupported') return null;

    if (state === 'denied') {
        return <span className="tb-item tb-hide-sm" style={{ opacity: 0.55 }} title={t('Notifications are blocked in this browser')}>{t('Notifications blocked')}</span>;
    }

    const on = state === 'on';
    const click = async () => {
        setBusy(true);
        try {
            setState(on ? await disablePush() : await enablePush());
        } finally {
            setBusy(false);
        }
    };

    return (
        <button type="button" className="tb-item tb-hide-sm" onClick={click} disabled={busy}
            title={on ? t('Browser notifications are on - click to turn off') : t('Get browser notifications for urgent work')}>
            <span style={{ width: 8, height: 8, borderRadius: '50%', background: on ? '#16a34a' : 'var(--mut, #94a3b8)', flexShrink: 0 }} />
            {on ? t('Notifications on') : t('Enable notifications')}
        </button>
    );
}
