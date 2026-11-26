import { useCallback, useEffect, useState } from "react";
import api from "../../api/axios";

export default function VendorOrdersPage() {
    const [orders, setOrders] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState("");
    const [message, setMessage] = useState("");

    const load = useCallback(async () => {
        setLoading(true); setError("");
        try {
            const response = await api.get("/vendor/orders", { params: { per_page: 50 } });
            if (!response?.data?.success || !Array.isArray(response?.data?.data)) throw new Error("The server returned an unexpected orders format.");
            setOrders(response.data.data);
        } catch (exception) { setError(exception?.response?.data?.message || exception?.message || "Unable to load your orders."); }
        finally { setLoading(false); }
    }, []);

    useEffect(() => { void load(); }, [load]);

    const advance = async (order) => {
        const sequence = { pending: "processing", processing: "ready_to_ship", ready_to_ship: "shipped" };
        const nextStatus = sequence[order.fulfillment?.status || order.status];
        if (!nextStatus) return;
        const payload = { status: nextStatus };
        if (nextStatus === "shipped") {
            payload.carrier = window.prompt("Shipping carrier name:");
            if (!payload.carrier) return;
            payload.tracking_number = window.prompt("Tracking number:");
            if (!payload.tracking_number) return;
        }
        setError(""); setMessage("");
        try {
            await api.patch(`/vendor/orders/${order.id}/fulfillment`, payload);
            setMessage(`Order ${order.number} moved to ${nextStatus.replaceAll("_", " ")}.`);
            await load();
        } catch (exception) { setError(exception?.response?.data?.message || "Unable to update order fulfilment."); }
    };

    return <div className="container-fluid py-4">
        <h1 className="h3">Vendor orders</h1>
        <p className="text-muted">Only orders containing your products are shown. Customer totals and other vendors’ items remain private.</p>
        {error ? <div className="alert alert-danger" role="alert">{error}</div> : null}
        {message ? <div className="alert alert-success" role="status">{message}</div> : null}
        <div className="card border-0 shadow-sm"><div className="table-responsive"><table className="table align-middle mb-0">
            <thead><tr><th>Order</th><th>Items</th><th>Your subtotal</th><th>Fulfilment</th><th>Tracking</th><th>Action</th></tr></thead>
            <tbody>{loading ? <tr><td colSpan="6" className="text-center py-5">Loading orders…</td></tr> : orders.length ? orders.map((order) => {
                const status = order.fulfillment?.status || order.status;
                const canAdvance = ["pending", "processing", "ready_to_ship"].includes(status);
                return <tr key={order.id}>
                    <td><div className="fw-semibold">{order.number}</div><small className="text-muted">{order.createdAt ? new Date(order.createdAt).toLocaleDateString() : "—"}</small></td>
                    <td>{Array.isArray(order.items) ? order.items.reduce((sum, item) => sum + Number(item.quantity || 0), 0) : 0}</td>
                    <td>{new Intl.NumberFormat(undefined, { style: "currency", currency: order.currency || "USD" }).format(Number(order.vendorSubtotal || 0))}</td>
                    <td><span className="badge text-bg-secondary">{String(status).replaceAll("_", " ")}</span></td>
                    <td>{order.fulfillment?.trackingNumber || "—"}</td>
                    <td>{canAdvance ? <button className="btn btn-sm btn-primary" onClick={() => void advance(order)}>Advance status</button> : "—"}</td>
                </tr>;
            }) : <tr><td colSpan="6" className="text-center text-muted py-5">No vendor orders yet.</td></tr>}</tbody>
        </table></div></div>
    </div>;
}
