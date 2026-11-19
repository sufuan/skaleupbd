export const fmt = (n) => '৳' + Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 });
export const fmtNum = (n) => Number(n || 0).toLocaleString('en-IN');

export function Verdict({ profit }) {
    return profit > 0
        ? <span className="chip chip-green">লাভ হয়েছে</span>
        : <span className="chip chip-amber">তেমন লাভ হয়নি — পরেরবার ছোট audience-এ পাঠাও</span>;
}

/** SMS parts, mirroring SmsService::parts (GSM 160/153, Unicode 70/67). */
export function smsParts(body) {
    const len = [...body].length;
    if (len === 0) return 1;
    const unicode = /[^\x20-\x7E\r\n]/.test(body);
    if (unicode) return len <= 70 ? 1 : Math.ceil(len / 67);
    return len <= 160 ? 1 : Math.ceil(len / 153);
}
