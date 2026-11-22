import { useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { t } from '@/lib/i18n';

// Shared Add / Edit product form sections - markup copied from the
// prototype's Add Product view (inventory.html, inv-add-product panel).
export const CARD = 'border border-border/60 bg-card text-card-foreground shadow-xs rounded-xl';
export const CARD_HEAD = 'mb-2 flex flex-col space-y-1.5 border-b py-4 px-4 pb-4 sm:px-6';
export const CARD_TITLE = 'sm:text-base text-lg font-semibold tracking-tight';
export const CARD_DESC = 'text-muted-foreground text-xs';
export const CARD_BODY = 'p-6 pt-0 space-y-4 px-4 sm:px-6';
export const LABEL = 'text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70';
export const INPUT = 'flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50';
export const TEXTAREA = 'flex min-h-[80px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50';
export const BTN_PRIMARY = 'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 bg-primary text-primary-foreground hover:bg-primary/90 h-10 px-4 py-2';
export const BTN_OUTLINE = 'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 border border-input bg-background hover:bg-accent hover:text-accent-foreground h-10 px-4 py-2';

function Section({ title, desc, children }) {
    return (
        <div className={CARD} data-slot="card">
            <div className={CARD_HEAD} data-slot="card-header">
                <h3 className={CARD_TITLE} data-slot="card-title">{title}</h3>
                <p className={CARD_DESC} data-slot="card-description">{desc}</p>
            </div>
            <div className={CARD_BODY} data-slot="card-content">{children}</div>
        </div>
    );
}

function Field({ label, children }) {
    return (
        <div className="space-y-2">
            <label className={LABEL}>{label}</label>
            {children}
        </div>
    );
}

function Switch({ checked, onChange, label, hint }) {
    return (
        <div className="flex items-center justify-between gap-4">
            <div>
                <div className="text-sm font-medium">{label}</div>
                {hint && <div className="text-xs text-muted-foreground">{hint}</div>}
            </div>
            <button type="button" role="switch" aria-checked={checked} onClick={() => onChange(!checked)}
                className={`inline-flex h-6 w-11 shrink-0 cursor-pointer items-center rounded-full border-2 border-transparent transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 ${checked ? 'bg-primary' : 'bg-input'}`}>
                <span className={`pointer-events-none block h-5 w-5 rounded-full bg-background shadow-lg ring-0 transition-transform ${checked ? 'translate-x-5' : 'translate-x-0'}`} />
            </button>
        </div>
    );
}

export default function ProductFields({ data, setData, categories = [], brands = [], businessName = '', forEdit = false, mode, setMode, variants, setVariants, readiness = null }) {
    const [galleryUrl, setGalleryUrl] = useState('');
    const [newCategory, setNewCategory] = useState('');
    const catInputRef = useRef(null);
    // The "+" beside the category select: with text it creates the
    // category AND selects it (previously it posted silently and the
    // select never changed, so the click looked dead); with an empty
    // box it focuses the box so the click visibly does something.
    const addCategory = () => {
        const name = newCategory.trim();
        if (!name) { catInputRef.current?.focus(); return; }
        router.post('/inventory/categories', { name }, {
            preserveScroll: true,
            onSuccess: (page) => {
                const created = (page.props.categories || []).find((c) => c.name === name);
                if (created) setData('category_id', created.id);
                setNewCategory('');
            },
        });
    };
    const profit = (parseFloat(data.price) || 0) - (parseFloat(data.cost) || 0);
    const margin = parseFloat(data.price) > 0 ? Math.round((profit / parseFloat(data.price)) * 100) : 0;

    const genSku = () => setData('sku', 'SKU-' + Math.floor(1000 + Math.random() * 9000));

    return (
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 lg:gap-6 items-start">
            <div className="lg:col-span-2 space-y-4 lg:space-y-6">
                <Section title={t('Product Basics')} desc={t('Provide essential details for this item.')}>
                    <Field label={t('Product Name')}>
                        <input className={INPUT} name="name" placeholder={t('e.g. Premium Cotton Polo T-Shirt')} value={data.name} onChange={(e) => setData('name', e.target.value)} required />
                    </Field>
                    <div className="space-y-2">
                        <div className="flex justify-between items-center">
                            <label className={LABEL}>{t('Product Code')}</label>
                            <button type="button" onClick={genSku} className="justify-center whitespace-nowrap rounded-md ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 hover:text-accent-foreground h-7 text-xs text-primary font-medium flex items-center gap-1 hover:bg-primary/10 transition-colors px-2 py-0.5">{t('Generate SKU')}</button>
                        </div>
                        <input className={INPUT} name="sku" placeholder={t('e.g. SKU-1002')} value={data.sku} onChange={(e) => setData('sku', e.target.value)} />
                    </div>
                </Section>

                {setMode && (
                    <Section title={t('Product Mode')} desc={t('Choose listing model')}>
                        <div className="flex gap-2">
                            <button type="button" onClick={() => setMode('single')} className={`inline-flex items-center justify-center rounded-md text-sm font-medium h-9 px-4 border ${mode === 'single' ? 'bg-primary text-primary-foreground border-primary' : 'border-input bg-background hover:bg-accent'}`}>{t('Single SKU')}</button>
                            <button type="button" onClick={() => setMode('variants')} className={`inline-flex items-center justify-center rounded-md text-sm font-medium h-9 px-4 border ${mode === 'variants' ? 'bg-primary text-primary-foreground border-primary' : 'border-input bg-background hover:bg-accent'}`}>{t('With Variants')}</button>
                        </div>
                        {mode === 'variants' && (
                            <div className="space-y-2">
                                {variants.map((v, i) => (
                                    <div key={i} className="flex flex-wrap gap-2">
                                        <input className={INPUT + ' flex-1 min-w-[140px]'} placeholder={t('e.g. Size: L / Color: Red')} value={v.name} onChange={(e) => { const n = [...variants]; n[i].name = e.target.value; setVariants(n); }} />
                                        <input className={INPUT + ' w-28'} type="number" placeholder={t('Price')} value={v.price} onChange={(e) => { const n = [...variants]; n[i].price = e.target.value; setVariants(n); }} />
                                        <input className={INPUT + ' w-24'} type="number" placeholder={t('Stock')} value={v.stock_qty} onChange={(e) => { const n = [...variants]; n[i].stock_qty = e.target.value; setVariants(n); }} />
                                        <button type="button" className={BTN_OUTLINE + ' px-3'} onClick={() => setVariants(variants.filter((_, j) => j !== i))}>×</button>
                                    </div>
                                ))}
                                <button type="button" className={BTN_OUTLINE + ' h-9'} onClick={() => setVariants([...variants, { name: '', price: '', stock_qty: 0 }])}>+ {t('Add Variant')}</button>
                            </div>
                        )}
                    </Section>
                )}

                <Section title={t('Pricing')} desc={t('Manage catalog rates and inventory cost margins.')}>
                    <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <Field label={t('Cost Price')}>
                            <input className={INPUT} type="number" step="0.01" name="costPrice" placeholder="0.00" value={data.cost} onChange={(e) => setData('cost', e.target.value)} />
                        </Field>
                        <Field label={t('Sell Price')}>
                            <input className={INPUT} type="number" step="0.01" name="price" placeholder="0.00" value={data.price} onChange={(e) => setData('price', e.target.value)} required />
                        </Field>
                        <Field label={t('Website Regular Price')}>
                            <input className={INPUT} type="number" step="0.01" name="originalPrice" placeholder="0.00" value={data.original_price} onChange={(e) => setData('original_price', e.target.value)} />
                        </Field>
                    </div>
                    {forEdit && (
                        <div className="text-sm text-muted-foreground">{t('Projected Profit')}: <b className="text-foreground">৳{profit.toFixed(2)}</b> · {t('Margin')}: <b className="text-foreground">{margin}%</b></div>
                    )}
                </Section>

                <Section title={t('Offer & AI')} desc={t('Campaign price the AI quotes while the offer runs, other names customers use, and this product’s own discount limit.')}>
                    {readiness && (
                        <div className="text-sm text-muted-foreground">{t('AI readiness')}: <b className="text-foreground">{readiness.score}%</b>{readiness.missing?.length > 0 && <> · {t('Missing')}: {readiness.missing.map((x) => t(x)).join(', ')}</>}</div>
                    )}
                    <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <Field label={t('Offer Price')}>
                            <input className={INPUT} type="number" step="0.01" placeholder="0.00" value={data.offer_price} onChange={(e) => setData('offer_price', e.target.value)} />
                        </Field>
                        <Field label={t('Offer Starts')}>
                            <input className={INPUT} type="date" value={data.offer_starts_at} onChange={(e) => setData('offer_starts_at', e.target.value)} />
                        </Field>
                        <Field label={t('Offer Ends')}>
                            <input className={INPUT} type="date" value={data.offer_ends_at} onChange={(e) => setData('offer_ends_at', e.target.value)} />
                        </Field>
                    </div>
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <Field label={t('Other Names (comma separated)')}>
                            <input className={INPUT} type="text" placeholder={t('e.g. sada panjabi, eid panjabi')} value={data.aliases} onChange={(e) => setData('aliases', e.target.value)} />
                        </Field>
                        <Field label={t('Max Discount % for this product')}>
                            <input className={INPUT} type="number" min="0" max="90" placeholder={t('Shop default')} value={data.max_discount_pct} onChange={(e) => setData('max_discount_pct', e.target.value)} />
                        </Field>
                    </div>
                </Section>

                <Section title={t('Inventory')} desc={t('Configure stock counts, alerts, and tracking switches.')}>
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <Field label={t('Stock Qty')}>
                            <input className={INPUT} type="number" name="stockQty" placeholder="0" value={data.stock_qty} onChange={(e) => setData('stock_qty', e.target.value)} disabled={forEdit} />
                        </Field>
                        <Field label={t('Alert Quantity')}>
                            <input className={INPUT} type="number" name="stockOutAlertQty" placeholder="0" value={data.alert_qty} onChange={(e) => setData('alert_qty', e.target.value)} />
                        </Field>
                    </div>
                    <Switch checked={!!data.manage_stock} onChange={(v) => setData('manage_stock', v)} label={t('Manage Stock')} hint={t('Track current inventory count and block oversell.')} />
                    <Switch checked={!!data.featured} onChange={(v) => setData('featured', v)} label={t('Featured Product')} hint={t('Display this product prominently on your website store.')} />
                </Section>

                <Section title={t('Media & Images')} desc={t('Drag and drop file or supply web URLs to build the catalog gallery.')}>
                    <label className="flex flex-col items-center justify-center gap-1 rounded-md border border-dashed border-input bg-background px-4 py-8 text-center cursor-pointer hover:bg-accent/40">
                        <span className="text-sm font-medium">{t('Click to upload or drag & drop')}</span>
                        <span className="text-xs text-muted-foreground">{t('Main product photo (PNG, JPG up to 5MB)')}</span>
                        <input type="file" accept="image/*" className="hidden" onChange={(e) => setData('image', e.target.files[0] || null)} />
                        <span className="text-xs text-muted-foreground">{data.image ? (data.image.name || t('Image selected')) : t('No image')}</span>
                    </label>
                    <div className="flex gap-2">
                        <input className={INPUT} placeholder="https://example.com/gallery-image.jpg" value={galleryUrl} onChange={(e) => setGalleryUrl(e.target.value)} />
                        <button type="button" className={BTN_OUTLINE} onClick={() => { if (galleryUrl.trim()) { setData('gallery', [...(data.gallery || []), galleryUrl.trim()]); setGalleryUrl(''); } }}>{t('Add to Gallery')}</button>
                    </div>
                    {(data.gallery || []).length > 0 && (
                        <div className="flex flex-wrap gap-2">
                            {data.gallery.map((g, i) => (
                                <span key={i} className="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs">{g}<button type="button" onClick={() => setData('gallery', data.gallery.filter((_, j) => j !== i))}>×</button></span>
                            ))}
                        </div>
                    )}
                </Section>

                <Section title={t('Catalog Info')} desc={t('Manage product descriptions and alternative category links.')}>
                    <Field label={t('Short Description')}>
                        <textarea className={TEXTAREA} placeholder={t('Summarize product features...')} value={data.short_description || ''} onChange={(e) => setData('short_description', e.target.value)} />
                    </Field>
                    <Field label={t('Long Description')}>
                        <textarea className={TEXTAREA} placeholder={t('Detailed description showing in storefront...')} value={data.long_description || ''} onChange={(e) => setData('long_description', e.target.value)} />
                    </Field>
                </Section>
            </div>

            <div className="space-y-4 lg:space-y-6">
                <Section title={t('Organization')} desc="">
                    <Field label={t('Primary Category')}>
                        <select className={INPUT} value={data.category_id || ''} onChange={(e) => setData('category_id', e.target.value)}>
                            <option value="">{t('Select')}</option>
                            {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                        </select>
                    </Field>
                    <div className="flex gap-2">
                        <input ref={catInputRef} className={INPUT} placeholder={t('Create Category')} value={newCategory} onChange={(e) => setNewCategory(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addCategory(); } }} />
                        <button type="button" className={BTN_OUTLINE + ' px-3'} onClick={addCategory}>+</button>
                    </div>
                    <Field label={t('Product Brand')}>
                        <select className={INPUT} value={data.brand_id || ''} onChange={(e) => setData('brand_id', e.target.value)}>
                            <option value="">{t('Select')}</option>
                            {brands.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                        </select>
                    </Field>
                    <Field label={t('Product Type')}>
                        <select className={INPUT} value={data.type || 'single'} onChange={(e) => setData('type', e.target.value)}>
                            <option value="single">{t('Single')}</option>
                            <option value="combo">{t('Combo')}</option>
                            <option value="wrap">{t('Wrap')}</option>
                        </select>
                    </Field>
                </Section>

                <Section title={t('Shipping Specs')} desc="">
                    <Field label={t('Product Weight')}>
                        <div className="flex gap-2">
                            <input className={INPUT} type="number" step="0.001" value={data.weight || ''} onChange={(e) => setData('weight', e.target.value)} />
                            <select className={INPUT + ' w-24'} value={data.weight_unit || 'kg'} onChange={(e) => setData('weight_unit', e.target.value)}>
                                <option value="kg">kg</option>
                                <option value="g">g</option>
                            </select>
                        </div>
                    </Field>
                </Section>

                <Section title={t('Store Access')} desc={t('Mark businesses this product is listed inside.')}>
                    <Field label={t('Assign to Business')}>
                        <div className="rounded-md border border-input bg-background px-3 py-2 text-sm">{businessName || t('Your Business')}</div>
                    </Field>
                </Section>

                <Section title={t('Extra Details')} desc="">
                    <Field label={t('Default Shipping Note')}>
                        <textarea className={TEXTAREA} placeholder={t('Add special packaging/delivery notes...')} value={data.shipping_note || ''} onChange={(e) => setData('shipping_note', e.target.value)} />
                    </Field>
                    <Field label={t('Internal Admin Note')}>
                        <textarea className={TEXTAREA} placeholder={t('Supplier, batch info, etc...')} value={data.admin_note || ''} onChange={(e) => setData('admin_note', e.target.value)} />
                    </Field>
                    <Field label={t('Warranty Policy')}>
                        <input className={INPUT} placeholder={t('e.g. 6 Months brand warranty')} value={data.warranty_policy || ''} onChange={(e) => setData('warranty_policy', e.target.value)} />
                    </Field>
                    <Field label={t('Warranty Coverage')}>
                        <input className={INPUT} value={data.warranty_coverage || ''} onChange={(e) => setData('warranty_coverage', e.target.value)} />
                    </Field>
                </Section>
            </div>
        </div>
    );
}
