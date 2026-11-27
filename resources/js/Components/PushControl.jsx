import { t } from '@/lib/i18n';
import { disablePush, enablePush, pushState, registerPushWorker } from '@/lib/push';
import { useEffect, useState } from 'react';

// Topbar control for browser Web Push (owner order 2026-11-25):
// registers the service worker, and lets the signed-in user turn
// browser notifications on or off for this browser. Per-event choice
// lives in Work Inbox Settings (each rule's push toggle).
//
// Every outcome is VISIBLE (Round 2, item 25): the old control
// swallowed errors, so a click on a browser with notifications
// blocked (or a failed subscribe) changed nothing on screen and the
// seller never learned why. Now the control always shows its true
// state, the blocked state explains itself in a small panel, and a
// failed request raises a toast.
const TOAST = {
    position: 'fixed', right: 16, bottom: 16, zIndex: 2000, maxWidth: 340,
    background: '#14532d', color: '#fff', borderRadius: 10, padding: '10px 14px',
    fontSize: 13.5, boxShadow: '0 8px 24px rgba(0,0,0,.25)',
};
const TOAST_ERR = { ...TOAST, background: '#7f1d1d' };
const PANEL = {
    position: 'absolute', top: 'calc(100% + 8px)', right: 0, width: 290, zIndex: 1500,
    background: 'var(--card, #fff)', color: 'var(--ink, #1e293b)', border: '1px solid var(--line, #e2e8f0)',
    borderRadius: 12, padding: '12px 14px', fontSize: 13, lineHeight: 1.55, textAlign: 'left',
    boxShadow: '0 12px 32px rgba(0,0,0,.18)', whiteSpace: 'normal', cursor: 'default',
};

export default function PushControl() {
    const [state, setState] = useState(null);
    const [busy, setBusy] = useState(false);
    const [panelOpen, setPanelOpen] = useState(false);
    const [toast, setToast] = useState(null); // {text, err}

    useEffect(() => {
        registerPushWorker();
        pushState().then(setState);
    }, []);

    useEffect(() => {
        if (!toast) return undefined;
        const id = setTimeout(() => setToast(null), 5000);
        return () => clearTimeout(id);
    }, [toast]);

    const feedback = toast && (
        <div style={toast.err ? TOAST_ERR : TOAST} role="status">{toast.text}</div>
    );

    if (state === null) {
        return (
            <span className="tb-item tb-hide-sm" style={{ opacity: 0.55 }}>
                <span style={{ width: 8, height: 8, borderRadius: '50%', background: 'var(--mut, #94a3b8)', flexShrink: 0 }} />
                {t('Notifications')}
            </span>
        );
    }

    if (state === 'unsupported') {
        return (
            <>
                <span className="tb-item tb-hide-sm" style={{ opacity: 0.55 }}
                    title={t('This browser cannot show notifications. Try Chrome on a phone or computer.')}>
                    {t('Notifications not supported in this browser')}
                </span>
                {feedback}
            </>
        );
    }

    if (state === 'denied') {
        return (
            <>
                <span style={{ position: 'relative', display: 'inline-flex' }}>
                    <button type="button" className="tb-item tb-hide-sm" style={{ opacity: 0.75 }} onClick={() => setPanelOpen(!panelOpen)}
                        title={t('Notifications are blocked in this browser')}>
                        <span style={{ width: 8, height: 8, borderRadius: '50%', background: '#dc2626', flexShrink: 0 }} />
                        {t('Notifications blocked')}
                    </button>
                    {panelOpen && (
                        <span style={PANEL}>
                            {t('Notifications are blocked in your browser settings, so this site cannot send them. To allow them: open the site settings for this site in your browser, set Notifications to Allow, then reload this page. Once allowed, this button shows "Notifications on" with a green dot.')}
                            <button type="button" onClick={() => setPanelOpen(false)}
                                style={{ display: 'block', marginTop: 8, background: 'none', border: 'none', padding: 0, fontWeight: 700, cursor: 'pointer', color: 'inherit', font: 'inherit' }}>
                                {t('Close')}
                            </button>
                        </span>
                    )}
                </span>
                {feedback}
            </>
        );
    }

    const on = state === 'on';
    const click = async () => {
        setBusy(true);
        try {
            const next = on ? await disablePush() : await enablePush();
            setState(next);
            if (!on && next === 'on') setToast({ text: t('Browser notifications are on.'), err: false });
            if (!on && next === 'denied') setPanelOpen(true);
            if (!on && next === 'off') setToast({ text: t('Notifications were not turned on. The browser prompt was closed.'), err: true });
            if (on && next === 'off') setToast({ text: t('Browser notifications are off.'), err: false });
        } catch (e) {
            setToast({ text: e?.message ? t(e.message) : t('Something went wrong. Please try again.'), err: true });
            pushState().then(setState);
        } finally {
            setBusy(false);
        }
    };

    return (
        <>
            <button type="button" className="tb-item tb-hide-sm" onClick={click} disabled={busy}
                title={on ? t('Browser notifications are on - click to turn off') : t('Get browser notifications for urgent work')}>
                <span style={{ width: 8, height: 8, borderRadius: '50%', background: on ? '#16a34a' : 'var(--mut, #94a3b8)', flexShrink: 0 }} />
                {busy ? t('Working...') : on ? t('Notifications on') : t('Enable notifications')}
            </button>
            {feedback}
        </>
    );
}
