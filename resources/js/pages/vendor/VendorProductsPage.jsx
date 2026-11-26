import { useCallback, useEffect, useState } from "react";
import api from "../../api/axios";

export default function VendorProductsPage() {
    const [products, setProducts] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState("");
    const [message, setMessage] = useState("");

    const load = useCallback(async () => {
        setLoading(true); setError("");
        try {
            const response = await api.get("/vendor/products", { params: { per_page: 50 } });
            if (!response?.data?.success || !Array.isArray(response?.data?.data)) throw new Error("The server returned an unexpected products format.");
            setProducts(response.data.data);
        } catch (exception) { setError(exception?.response?.data?.message || exception?.message || "Unable to load your products."); }
        finally { setLoading(false); }
    }, []);

    useEffect(() => { void load(); }, [load]);

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
            setMessage(`Inventory updated for ${product.name}.`);
            await load();
        } catch (exception) { setError(exception?.response?.data?.message || "Unable to update inventory."); }
    };

    return <div className="container-fluid py-4">
        <h1 className="h3">My products</h1>
        <p className="text-muted">Manage your catalogue and inventory. Product creation and editing are being connected to the complete catalogue form.</p>
        {error ? <div className="alert alert-danger" role="alert">{error}</div> : null}
        {message ? <div className="alert alert-success" role="status">{message}</div> : null}
        <div className="card border-0 shadow-sm"><div className="table-responsive"><table className="table align-middle mb-0">
            <thead><tr><th>Product</th><th>SKU</th><th>Price</th><th>Status</th><th>Approval</th><th>Variants / Stock</th></tr></thead>
            <tbody>{loading ? <tr><td colSpan="6" className="text-center py-5">Loading products…</td></tr> : products.length ? products.map((product) => <tr key={product.id}>
                <td><div className="fw-semibold">{product.name}</div><small className="text-muted">{product.slug}</small></td>
                <td>{product.sku || product.variants?.[0]?.sku || "—"}</td>
                <td>{product.basePrice ?? product.variants?.[0]?.price ?? "—"}</td>
                <td><span className="badge text-bg-secondary">{product.status || "—"}</span></td>
                <td>{product.approvalStatus || "—"}</td>
                <td>{Array.isArray(product.variants) ? product.variants.map((variant) => <div className="d-flex align-items-center gap-2 mb-1" key={variant.id}><small>{variant.label || variant.sku || "Variant"}: {variant.stockQuantity ?? variant.inventory ?? 0}</small><button className="btn btn-sm btn-outline-primary" onClick={() => void adjustStock(product, variant)}>Adjust</button></div>) : <button className="btn btn-sm btn-outline-primary" onClick={() => void adjustStock(product)}>Adjust stock</button>}</td>
            </tr>) : <tr><td colSpan="6" className="text-center text-muted py-5">You haven’t added any products yet.</td></tr>}</tbody>
        </table></div></div>
    </div>;
}
