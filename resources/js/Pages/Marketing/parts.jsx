import { t } from '@/lib/i18n';

export const fmt = (n) => '৳' + Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 });
export const fmtNum = (n) => Number(n || 0).toLocaleString('en-IN');

export function Verdict({ profit }) {
    return profit > 0
        ? <span className="chip chip-green">{t('Profit made')}</span>
        : <span className="chip chip-amber">{t('Not much profit — next time send to a smaller audience')}</span>;
}

/** SMS parts, mirroring SmsService::parts (GSM 160/153, Unicode 70/67). */
export function smsParts(body) {
    const len = [...body].length;
    if (len === 0) return 1;
    const unicode = /[^\x20-\x7E\r\n]/.test(body);
    if (unicode) return len <= 70 ? 1 : Math.ceil(len / 67);
    return len <= 160 ? 1 : Math.ceil(len / 153);
}
