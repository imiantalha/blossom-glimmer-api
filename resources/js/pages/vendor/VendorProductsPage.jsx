import { useCallback, useEffect, useState } from "react";
import api from "../../api/axios";

const blankVariant = () => ({ sku: "", price: "", salePrice: "", stockQuantity: "0", weight: "1", length: "1", width: "1", height: "1", label: "Default", attributeValues: [], images: [] });
const blankForm = () => ({ id: null, name: "", slug: "", brandId: "", categoryId: "", productType: "Simple", shippingProfileId: "", shortDescription: "", fullDescription: "", featuredImageUrl: "", featuredImageAlt: "", tags: "", intent: "draft", variants: [blankVariant()] });

export default function VendorProductsPage() {
    const [products, setProducts] = useState([]);
    const [options, setOptions] = useState({ brands: [], categories: [], shippingProfiles: [], productTypes: [] });
    const [form, setForm] = useState(blankForm());
    const [editing, setEditing] = useState(false);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState("");
    const [message, setMessage] = useState("");

    const load = useCallback(async () => {
        setLoading(true); setError("");
        try {
            const [productsResponse, optionsResponse] = await Promise.all([
                api.get("/vendor/products", { params: { per_page: 100 } }),
                api.get("/vendor/products/options"),
            ]);
            if (!productsResponse?.data?.success || !Array.isArray(productsResponse?.data?.data) || !optionsResponse?.data?.success) throw new Error("The server returned an unexpected catalogue response.");
            setProducts(productsResponse.data.data);
            setOptions(optionsResponse.data.data ?? { brands: [], categories: [], shippingProfiles: [], productTypes: [] });
        } catch (exception) { setError(exception?.response?.data?.message || exception?.message || "Unable to load your catalogue."); }
        finally { setLoading(false); }
    }, []);

    useEffect(() => { void load(); }, [load]);

    const change = (key, value) => setForm(current => ({ ...current, [key]: value }));
    const changeVariant = (index, key, value) => setForm(current => ({ ...current, variants: current.variants.map((variant, i) => i === index ? { ...variant, [key]: value } : variant) }));
    const startCreate = () => { setForm({ ...blankForm(), brandId: String(options.brands?.[0]?.id ?? ""), categoryId: String(options.categories?.[0]?.id ?? ""), shippingProfileId: String(options.shippingProfiles?.[0]?.id ?? "") }); setEditing(true); setMessage(""); setError(""); };
    const startEdit = (product) => {
        const firstImage = product.images?.[0] ?? {};
        const variants = (product.variants ?? []).map(v => ({
            sku: v.sku ?? "", price: String(v.price ?? ""), salePrice: String(v.salePrice ?? ""),
            stockQuantity: String(v.stockQuantity ?? v.inventory ?? 0), weight: String(v.weight ?? 1),
            length: String(v.length ?? 1), width: String(v.width ?? 1), height: String(v.height ?? 1),
            label: v.label ?? "Default", attributeValues: v.attributeValues ?? [], images: v.images ?? [],
        }));
        setForm({
            ...blankForm(), id: product.id, name: product.name ?? "", slug: product.slug ?? "",
            brandId: String(product.brandId ?? product.brand?.id ?? ""), categoryId: String(product.categoryId ?? product.category?.id ?? ""),
            productType: String(product.productTypeId ?? "simple").toLowerCase() === "variable" ? "Variable" : "Simple",
            shippingProfileId: String(product.shippingProfileId ?? ""), shortDescription: product.shortDescription ?? "",
            fullDescription: product.description ?? "", featuredImageUrl: firstImage.url ?? "", featuredImageAlt: firstImage.altText ?? "",
            tags: (product.tags ?? []).map(t => t.name).join(", "), intent: "draft",
            variants: variants.length ? variants : [blankVariant()],
        });
        setEditing(true); setMessage(""); setError("");
    };

    async function save(event) {
        event.preventDefault(); setSaving(true); setError(""); setMessage("");
        const payload = {
            name: form.name.trim(), slug: form.slug.trim() || undefined,
            brandId: Number(form.brandId), categoryId: Number(form.categoryId),
            productType: form.productType, shippingProfileId: form.shippingProfileId,
            shortDescription: form.shortDescription, fullDescription: form.fullDescription,
            tags: form.tags.split(",").map(tag => tag.trim()).filter(Boolean),
            media: { featuredImage: { url: form.featuredImageUrl.trim(), altText: form.featuredImageAlt.trim() || null }, galleryImages: [] },
            attributes: [],
            variants: form.variants.map((v, index) => ({
                sku: v.sku.trim(), price: Number(v.price), salePrice: v.salePrice === "" ? null : Number(v.salePrice),
                stockQuantity: Number(v.stockQuantity), weight: Number(v.weight), length: Number(v.length),
                width: Number(v.width), height: Number(v.height), label: v.label.trim() || (form.productType === "Simple" ? "Default" : `Variant ${index + 1}`),
                attributeValues: v.attributeValues ?? [], images: v.images ?? [],
            })),
            specifications: [], faqs: [], intent: form.intent,
        };
        try {
            const response = form.id
                ? await api.patch(`/vendor/products/${form.id}`, payload)
                : await api.post("/vendor/products", payload);
            if (!response?.data?.success) throw new Error(response?.data?.message || "Unable to save product.");
            setEditing(false); setMessage(form.intent === "submit" ? "Product submitted for administrator approval." : "Product draft saved.");
            await load();
        } catch (exception) {
            const errors = exception?.response?.data?.errors;
            const firstError = errors && typeof errors === "object" ? Object.values(errors).flat()[0] : null;
            setError(firstError || exception?.response?.data?.message || exception?.message || "Unable to save product.");
        } finally { setSaving(false); }
    }

    async function archive(product) {
        if (!window.confirm(`Archive “${product.name}”? It will no longer be available for sale.`)) return;
        setError(""); setMessage("");
        try {
            const response = await api.delete(`/vendor/products/${product.id}`);
            if (!response?.data?.success) throw new Error(response?.data?.message || "Unable to archive product.");
            setMessage("Product archived."); await load();
        } catch (exception) { setError(exception?.response?.data?.message || exception?.message || "Unable to archive product."); }
    }

    const adjustStock = async (product, variant = null) => {
        const current = Number(variant?.stockQuantity ?? variant?.inventory ?? 0);
        const value = window.prompt(`Set stock quantity for ${product.name}${variant?.label ? " — " + variant.label : ""}:`, String(current));
        if (value === null) return;
        const quantity = Number(value);
        if (!Number.isInteger(quantity) || quantity < 0) { setError("Stock must be a whole number greater than or equal to zero."); return; }
        const payload = { stock_quantity: quantity, manage_inventory: true, low_stock_threshold: 5 };
        if (variant?.id && !String(variant.id).startsWith("simple-")) payload.variant_id = Number(variant.id);
        setError(""); setMessage("");
        try {
            await api.patch(`/vendor/products/${product.id}/inventory`, payload);
            setMessage(`Inventory updated for ${product.name}.`); await load();
        } catch (exception) { setError(exception?.response?.data?.message || "Unable to update inventory."); }
    };

    return <div className="container-fluid py-4">
        <div className="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><h1 className="h3 mb-1">My products</h1><p className="text-muted mb-0">Create products, manage variants and inventory, and submit products for approval.</p></div><button className="btn btn-primary" onClick={startCreate}>Add product</button></div>
        {error ? <div className="alert alert-danger" role="alert">{error}<button className="btn btn-sm btn-outline-danger ms-3" onClick={() => void load()}>Retry</button></div> : null}
        {message ? <div className="alert alert-success" role="status">{message}</div> : null}
        {editing ? <div className="card border-0 shadow-sm mb-4"><div className="card-body"><div className="d-flex justify-content-between align-items-center"><h2 className="h5">{form.id ? "Edit product" : "Create product"}</h2><button type="button" className="btn-close" aria-label="Close" onClick={() => setEditing(false)} /></div>
            <form onSubmit={save}>
                <div className="row g-3 mt-1">
                    <div className="col-md-6"><label className="form-label" htmlFor="product-name">Product name</label><input id="product-name" className="form-control" value={form.name} onChange={e=>change("name",e.target.value)} maxLength={255} required /></div>
                    <div className="col-md-6"><label className="form-label" htmlFor="product-slug">URL slug (optional)</label><input id="product-slug" className="form-control" value={form.slug} onChange={e=>change("slug",e.target.value)} /></div>
                    <div className="col-md-4"><label className="form-label" htmlFor="product-brand">Brand</label><select id="product-brand" className="form-select" value={form.brandId} onChange={e=>change("brandId",e.target.value)} required><option value="">Select brand</option>{options.brands.map(item=><option key={item.id} value={item.id}>{item.name}</option>)}</select></div>
                    <div className="col-md-4"><label className="form-label" htmlFor="product-category">Category</label><select id="product-category" className="form-select" value={form.categoryId} onChange={e=>change("categoryId",e.target.value)} required><option value="">Select category</option>{options.categories.map(item=><option key={item.id} value={item.id}>{item.name}</option>)}</select></div>
                    <div className="col-md-4"><label className="form-label" htmlFor="product-shipping">Shipping profile</label><select id="product-shipping" className="form-select" value={form.shippingProfileId} onChange={e=>change("shippingProfileId",e.target.value)} required><option value="">Select shipping</option>{options.shippingProfiles.map(item=><option key={item.id} value={item.id}>{item.name}</option>)}</select></div>
                    <div className="col-md-4"><label className="form-label" htmlFor="product-type">Product type</label><select id="product-type" className="form-select" value={form.productType} onChange={e=>change("productType",e.target.value)}><option value="Simple">Simple</option><option value="Variable">Variable</option></select></div>
                    <div className="col-md-8"><label className="form-label" htmlFor="product-tags">Approved tags (comma separated)</label><input id="product-tags" className="form-control" value={form.tags} onChange={e=>change("tags",e.target.value)} placeholder="e.g. organic, handmade" /></div>
                    <div className="col-md-8"><label className="form-label" htmlFor="product-image">Featured image URL</label><input id="product-image" type="url" className="form-control" value={form.featuredImageUrl} onChange={e=>change("featuredImageUrl",e.target.value)} required /></div>
                    <div className="col-md-4"><label className="form-label" htmlFor="product-image-alt">Image alt text</label><input id="product-image-alt" className="form-control" value={form.featuredImageAlt} onChange={e=>change("featuredImageAlt",e.target.value)} /></div>
                    <div className="col-md-6"><label className="form-label" htmlFor="product-short">Short description</label><textarea id="product-short" className="form-control" rows="2" maxLength={500} value={form.shortDescription} onChange={e=>change("shortDescription",e.target.value)} /></div>
                    <div className="col-md-6"><label className="form-label" htmlFor="product-description">Full description</label><textarea id="product-description" className="form-control" rows="2" maxLength={5000} value={form.fullDescription} onChange={e=>change("fullDescription",e.target.value)} /></div>
                </div>
                <div className="d-flex justify-content-between align-items-center mt-4 mb-2"><h3 className="h6 mb-0">{form.productType === "Simple" ? "Product price & stock" : "Product variants"}</h3>{form.productType === "Variable" ? <button type="button" className="btn btn-sm btn-outline-primary" onClick={()=>change("variants",[...form.variants,blankVariant()])}>Add variant</button> : null}</div>
                {form.variants.map((variant,index)=><div className="rounded border p-3 mb-3" key={index}><div className="d-flex justify-content-between mb-2"><strong>{form.productType === "Simple" ? "Default variant" : `Variant ${index+1}`}</strong>{form.productType === "Variable" && form.variants.length>1 ? <button type="button" className="btn btn-sm btn-outline-danger" onClick={()=>change("variants",form.variants.filter((_,i)=>i!==index))}>Remove</button> : null}</div><div className="row g-2">
                    <div className="col-md-3"><label className="form-label">SKU</label><input className="form-control" value={variant.sku} onChange={e=>changeVariant(index,"sku",e.target.value)} required /></div>
                    <div className="col-md-2"><label className="form-label">Price</label><input type="number" min="0.01" step="0.01" className="form-control" value={variant.price} onChange={e=>changeVariant(index,"price",e.target.value)} required /></div>
                    <div className="col-md-2"><label className="form-label">Sale price</label><input type="number" min="0.01" step="0.01" className="form-control" value={variant.salePrice} onChange={e=>changeVariant(index,"salePrice",e.target.value)} /></div>
                    <div className="col-md-2"><label className="form-label">Stock</label><input type="number" min="0" step="1" className="form-control" value={variant.stockQuantity} onChange={e=>changeVariant(index,"stockQuantity",e.target.value)} required /></div>
                    <div className="col-md-3"><label className="form-label">Variant label</label><input className="form-control" value={variant.label} onChange={e=>changeVariant(index,"label",e.target.value)} /></div>
                    {["weight","length","width","height"].map(key=><div className="col-3 col-md-2" key={key}><label className="form-label text-capitalize">{key}</label><input type="number" min="0" step="0.01" className="form-control" value={variant[key]} onChange={e=>changeVariant(index,key,e.target.value)} required /></div>)}
                </div></div>)}
                <div className="d-flex flex-wrap justify-content-end gap-2 mt-3"><button type="button" className="btn btn-outline-secondary" onClick={()=>setEditing(false)}>Cancel</button><button type="submit" className="btn btn-outline-primary" disabled={saving}>{saving?"Saving…":"Save draft"}</button><button type="button" className="btn btn-primary" disabled={saving} onClick={()=>change("intent","submit") || document.getElementById("vendor-product-submit")?.click()}>Submit for approval</button><button id="vendor-product-submit" type="submit" hidden /></div>
            </form>
        </div></div> : null}
        <div className="card border-0 shadow-sm"><div className="table-responsive"><table className="table align-middle mb-0">
            <thead><tr><th>Product</th><th>SKU</th><th>Price</th><th>Status</th><th>Approval</th><th>Variants / Stock</th><th>Actions</th></tr></thead>
            <tbody>{loading ? <tr><td colSpan="7" className="text-center py-5">Loading products…</td></tr> : products.length ? products.map(product => <tr key={product.id}>
                <td><div className="fw-semibold">{product.name}</div><small className="text-muted">{product.slug}</small></td>
                <td>{product.sku || product.variants?.[0]?.sku || "—"}</td>
                <td>{product.variants?.[0]?.price ?? "—"}</td>
                <td><span className="badge text-bg-secondary">{product.status || "—"}</span></td>
                <td>{product.approvalStatus || "—"}</td>
                <td>{Array.isArray(product.variants) ? product.variants.map(variant => <div className="d-flex align-items-center gap-2 mb-1" key={variant.id}><small>{variant.label || variant.sku || "Variant"}: {variant.stockQuantity ?? variant.inventory ?? 0}</small><button className="btn btn-sm btn-outline-primary" onClick={() => void adjustStock(product,variant)}>Adjust</button></div>) : "—"}</td>
                <td><div className="d-flex gap-1"><button className="btn btn-sm btn-outline-primary" onClick={()=>startEdit(product)}>Edit</button><button className="btn btn-sm btn-outline-danger" onClick={()=>void archive(product)}>Archive</button></div></td>
            </tr>) : <tr><td colSpan="7" className="text-center text-muted py-5">You haven’t added any products yet.</td></tr>}</tbody>
        </table></div></div>
    </div>;
}
