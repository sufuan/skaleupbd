import { useEffect, useState } from 'react';
import { setConfirmListener } from '@/lib/confirm';
import { t } from '@/lib/i18n';

// Renders the in-page confirmation dialog used by confirmDialog().
// Mounted once in AppLayout.
export default function ConfirmHost() {
    const [current, setCurrent] = useState(null);

    useEffect(() => {
        setConfirmListener((req) => setCurrent(req));
        return () => setConfirmListener(null);
    }, []);

    if (!current) return null;

    const answer = (value) => {
        current.resolve(value);
        setCurrent(null);
    };

    return (
        <div className="overlay" onClick={() => answer(false)}>
            <div className="modal" style={{ maxWidth: 420 }} onClick={(e) => e.stopPropagation()}>
                <p style={{ fontSize: 15, lineHeight: 1.55, margin: '4px 0 18px' }}>{current.message}</p>
                <div style={{ display: 'flex', gap: 10, justifyContent: 'flex-end' }}>
                    <button type="button" className="btn" data-confirm-no onClick={() => answer(false)}>
                        {t('Cancel')}
                    </button>
                    <button
                        type="button"
                        className="btn pri"
                        data-confirm-yes
                        style={{ background: '#B91C1C' }}
                        onClick={() => answer(true)}
                    >
                        {t('Yes, continue')}
                    </button>
                </div>
            </div>
        </div>
    );
}
