import { useCallback, useEffect, useState } from "react";
import api from "../../api/axios";

export default function VendorDashboard() {
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState("");

    const load = useCallback(async () => {
        setLoading(true);
        setError("");
        try {
            const response = await api.get("/vendor/dashboard");
            const payload = response?.data?.data;
            if (!response?.data?.success || !payload || typeof payload !== "object") {
                throw new Error("The server returned an unexpected dashboard response.");
            }
            setData(payload);
        } catch (exception) {
            setData(null);
            setError(exception?.response?.data?.message || exception?.message || "Unable to load your vendor dashboard.");
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => { void load(); }, [load]);

    if (loading) return <div className="container py-5 text-center">Loading your store dashboard…</div>;
    if (error) return <div className="container py-5"><div className="alert alert-danger" role="alert"><strong>Something went wrong.</strong> {error}<button className="btn btn-sm btn-outline-danger ms-3" onClick={() => void load()}>Try again</button></div></div>;

    const metrics = [
        ["Products", data?.products?.total ?? 0],
        ["Active products", data?.products?.active ?? 0],
        ["Orders", data?.orders?.total ?? 0],
        ["Sales", data?.sales?.gross ?? 0],
    ];

    return <div className="container-fluid py-4">
        <h1 className="h3 mb-1">Vendor dashboard</h1>
        <p className="text-muted mb-4">A summary of your store activity.</p>
        <div className="row g-3">
            {metrics.map(([label, value]) => <div className="col-sm-6 col-xl-3" key={label}><div className="card border-0 shadow-sm h-100"><div className="card-body"><div className="text-muted small">{label}</div><div className="fs-3 fw-semibold mt-2">{value}</div></div></div></div>)}
        </div>
        <div className="card border-0 shadow-sm mt-4"><div className="card-body"><h2 className="h5">Store status</h2><p className="mb-0">{data?.store?.status || "No store profile found"}</p></div></div>
    </div>;
}
