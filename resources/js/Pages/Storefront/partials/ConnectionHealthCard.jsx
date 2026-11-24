import { useState, useEffect } from 'react';
import { router } from '@inertiajs/react';

/**
 * ConnectionHealthCard
 *
 * Place in: resources/js/Pages/Storefront/partials/ConnectionHealthCard.jsx
 *
 * Shows the live health of one integration connection in plain words.
 * Only surfaces actionable items — when everything is fine, the card says
 * nothing is wrong. No em-dashes. No emoji. All strings via t().
 *
 * Props:
 *   connectionId  number  — ID of the IntegrationConnection row
 *   topUpUrl      string  — URL to the Points & Billing top-up page
 *   t             func    — translation helper
 */
export default function ConnectionHealthCard({ connectionId, topUpUrl, t }) {
    const [health, setHealth]   = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError]     = useState(null);

    function load() {
        setLoading(true);
        fetch(`/storefront/integrations/connections/${connectionId}/health`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then(r => {
                if (!r.ok) throw new Error(r.status);
                return r.json();
            })
            .then(data => { setHealth(data); setError(null); })
            .catch(() => setError(t('Could not load connection status.')))
            .finally(() => setLoading(false));
    }

    useEffect(() => {
        load();
        // Refresh every 2 minutes.
        const timer = setInterval(load, 120_000);
        return () => clearInterval(timer);
    }, [connectionId]);

    if (loading && !health) {
        return (
            <div className="card" style={{ padding: '1rem', color: '#666' }}>
                {t('Checking connection...')}
            </div>
        );
    }

    if (error) {
        return (
            <div className="card" style={{ padding: '1rem', color: '#a00' }}>
                {error}
            </div>
        );
    }

    const h = health;

    // ----- Paused (zero points) ------------------------------------------------
    if (h.status_label === 'paused') {
        return (
            <div className="card" style={{ borderLeft: '4px solid #d97706', padding: '1rem' }}>
                <p style={{ fontWeight: 600, marginBottom: '0.5rem', color: '#92400e' }}>
                    {t('Your connection is paused.')}
                </p>
                <p style={{ color: '#78350f', marginBottom: '0.75rem' }}>
                    {t('You have no points left. Orders from your website are no longer appearing here, and tracking updates have stopped. Top up your points to reconnect automatically.')}
                </p>
                <a href={topUpUrl} className="btn pri">
                    {t('Buy more points')}
                </a>
            </div>
        );
    }

    // ----- Error ---------------------------------------------------------------
    if (h.status_label === 'error' || h.status_label === 'degraded') {
        const label = h.status_label === 'error'
            ? t('Connection error')
            : t('Connection issues detected');

        return (
            <div className="card" style={{ borderLeft: '4px solid #dc2626', padding: '1rem' }}>
                <p style={{ fontWeight: 600, color: '#991b1b', marginBottom: '0.5rem' }}>
                    {label}
                </p>
                {h.recent_failures > 0 && (
                    <p style={{ color: '#7f1d1d', marginBottom: '0.5rem' }}>
                        {t(':count webhook deliveries failed in the last 24 hours.', { count: h.recent_failures })}
                    </p>
                )}
                {h.failure_count >= 3 && (
                    <p style={{ color: '#7f1d1d', marginBottom: '0.5rem' }}>
                        {t('Repeated failures detected. Check that your website is reachable.')}
                    </p>
                )}
                <button
                    type="button"
                    className="btn"
                    onClick={load}
                    style={{ marginTop: '0.5rem' }}
                >
                    {t('Check again')}
                </button>
            </div>
        );
    }

    // ----- Disabled (connection removed / turned off) ---------------------------
    if (h.status_label === 'disabled') {
        return (
            <div className="card" style={{ borderLeft: '4px solid #6b7280', padding: '1rem' }}>
                <p style={{ fontWeight: 600, color: '#374151' }}>
                    {t('This connection is turned off. Reconnect to start receiving orders again.')}
                </p>
            </div>
        );
    }

    // ----- Pending (connection test still running) ------------------------------
    if (h.status_label === 'pending') {
        return (
            <div className="card" style={{ borderLeft: '4px solid #6b7280', padding: '1rem' }}>
                <p style={{ fontWeight: 600, color: '#374151' }}>
                    {t('Connection is being tested. This can take up to one minute.')}
                </p>
            </div>
        );
    }

    // ----- Active -- build a list of items that need attention -----------------
    if (h.status_label === 'active') {
    const warnings = [];

    if (h.unmatched_products > 0) {
        warnings.push(
            <li key="unmatched">
                {t(':count product(s) from your website are not matched yet. Match them so stock stays in step.', {
                    count: h.unmatched_products,
                })}
            </li>
        );
    }

    if (h.pending_outbound > 0) {
        warnings.push(
            <li key="outbound">
                {t(':count status update(s) are queued to send to your website.', {
                    count: h.pending_outbound,
                })}
            </li>
        );
    }

    if (h.pending_inbound > 0) {
        warnings.push(
            <li key="inbound">
                {t(':count order event(s) from your website are waiting to be processed.', {
                    count: h.pending_inbound,
                })}
            </li>
        );
    }

    // All clear.
    if (warnings.length === 0) {
        return (
            <div className="card" style={{ borderLeft: '4px solid #16a34a', padding: '1rem' }}>
                <p style={{ color: '#14532d', fontWeight: 600 }}>
                    {t('Connection is active.')}
                </p>
                {h.last_success_at && (
                    <p style={{ color: '#166534', fontSize: '0.85rem', marginTop: '0.25rem' }}>
                        {t('Last successful sync: :time', { time: formatTime(h.last_success_at) })}
                    </p>
                )}
            </div>
        );
    }

    return (
        <div className="card" style={{ borderLeft: '4px solid #d97706', padding: '1rem' }}>
            <p style={{ fontWeight: 600, color: '#78350f', marginBottom: '0.5rem' }}>
                {t('Action needed')}
            </p>
            <ul style={{ margin: 0, paddingLeft: '1.25rem', color: '#92400e' }}>
                {warnings}
            </ul>
        </div>
    );
    }

    // ----- Any other label: never claim the connection is fine ----------------
    return (
        <div className="card" style={{ padding: '1rem', color: '#666' }}>
            {t('Connection status unavailable.')}
        </div>
    );
}

function formatTime(iso) {
    if (!iso) return '';
    try {
        return new Intl.DateTimeFormat(undefined, {
            dateStyle: 'medium',
            timeStyle: 'short',
        }).format(new Date(iso));
    } catch {
        return iso;
    }
}
