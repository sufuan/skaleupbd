import { Link, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { t } from '@/lib/i18n';
import ProductFields, { BTN_OUTLINE, BTN_PRIMARY } from './ProductFields';

export default function AddProduct({ categories, brands, businessName }) {
    const { data, setData, post, processing, errors } = useForm({
        name: '', sku: '', price: '', cost: '', original_price: '', offer_price: '', offer_starts_at: '', offer_ends_at: '', aliases: '', max_discount_pct: '', stock_qty: 0, alert_qty: 5,
        manage_stock: true, featured: false, is_active: true, type: 'single',
        category_id: '', brand_id: '', weight: '', weight_unit: 'kg',
        short_description: '', long_description: '', shipping_note: '', admin_note: '',
        warranty_policy: '', warranty_coverage: '', gallery: [], image: null,
        mode: 'single', variants: [],
    });

    const submit = (e) => {
        e.preventDefault();
        post('/inventory/products', { forceFormData: true });
    };

    return (
        <AppLayout title={t('Add Product')}>
            <div className="invx">
                <div className="container mx-auto px-2 sm:px-6 py-4 sm:py-6 space-y-4">
                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h1 className="text-3xl font-bold tracking-tight text-foreground">{t('Add Product')}</h1>
                            <p className="text-sm text-muted-foreground">{t('Create a new product to list in your store catalog and manage stock.')}</p>
                        </div>
                        <span className="inline-flex items-center gap-2 text-sm text-primary">
                            <span className="relative flex h-2.5 w-2.5">
                                <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                                <span className="relative inline-flex rounded-full h-2.5 w-2.5 bg-red-500"></span>
                            </span>
                            <span>{t('How to add a new product?')}</span>
                        </span>
                    </div>

                    {Object.keys(errors).length > 0 && (
                        <div className="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                            {Object.values(errors)[0]}
                        </div>
                    )}

                    <form className="space-y-6" onSubmit={submit}>
                        <ProductFields data={data} setData={setData} categories={categories} brands={brands}
                            businessName={businessName} mode={data.mode} setMode={(v) => setData('mode', v)}
                            variants={data.variants} setVariants={(v) => setData('variants', v)} />
                        <div className="flex items-center justify-end gap-2">
                            <Link href="/inventory/products" className={BTN_OUTLINE}>{t('Cancel')}</Link>
                            <button type="submit" className={BTN_PRIMARY} disabled={processing || !data.name.trim() || data.price === ''}>{t('Create Product')}</button>
                        </div>
                    </form>
                </div>
            </div>
        </AppLayout>
    );
}
