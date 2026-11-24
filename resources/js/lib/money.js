// The one taka formatter for every page: ৳ + en-IN digit grouping.
// Pages used to each carry a copy-pasted copy of this one-liner; any
// change to money display now happens here only.
export const tk = (n) => `৳${Number(n || 0).toLocaleString('en-IN')}`;
