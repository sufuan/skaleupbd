import { router } from '@inertiajs/react';
import { t } from '@/lib/i18n';

export const fmt = (n) => '৳' + Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 });
export const fmtNum = (n) => Number(n || 0).toLocaleString('en-IN');
export const fmtSigned = (n) => (Number(n) < 0 ? '− ' + fmt(Math.abs(Number(n))) : fmt(n));

const PRESETS = [['today', 'Today'], ['7d', 'Past 7 days'], ['month', 'This month']];

export function RepHead({ title, sub, preset, base, exportKey }) {
    return (
        <>
            <div className="x-head"><h3>{title}</h3><p>{sub}</p></div>
            <div className="date-chips">
                {PRESETS.map(([p, label]) => (
                    <button key={p} className={`ptab${preset === p ? ' act' : ''}`} onClick={() => router.get(base, { preset: p }, { preserveScroll: true })}>{t(label)}</button>
                ))}
                <a className="btn-s" style={{ marginLeft: 'auto' }} href={`/reports/${exportKey}/export?preset=${preset}`}>{t('Print / Download')}</a>
            </div>
        </>
    );
}
