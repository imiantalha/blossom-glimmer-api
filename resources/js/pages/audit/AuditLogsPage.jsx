import { useCallback, useEffect, useState } from "react";
import api from "../../api/axios";

const PAGE_SIZE = 30;

export default function AuditLogsPage() {
    const [items, setItems] = useState([]);
    const [pagination, setPagination] = useState(null);
    const [search, setSearch] = useState("");
    const [role, setRole] = useState("");
    const [page, setPage] = useState(1);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState("");

    const load = useCallback(async () => {
        setLoading(true);
        setError("");
        try {
            const response = await api.get("/admin/audit-logs", {
                params: { page, per_page: PAGE_SIZE, ...(search ? { search } : {}), ...(role ? { role } : {}) },
            });
            const payload = response?.data;
            if (!payload || payload.success !== true || !Array.isArray(payload.data)) {
                throw new Error("The server returned an unexpected activity-log format.");
            }
            setItems(payload.data.filter((entry) => entry && typeof entry === "object" && entry.id != null));
            setPagination(payload.pagination && typeof payload.pagination === "object" ? payload.pagination : null);
        } catch (exception) {
            setItems([]);
            setPagination(null);
            setError(exception?.response?.data?.message || exception?.message || "Unable to load audit activity. Please try again.");
        } finally {
            setLoading(false);
        }
    }, [page, search, role]);

    useEffect(() => { void load(); }, [load]);

    const statusLabel = (status) => Number(status) >= 400 ? "Failed" : "Succeeded";

    return (
        <div className="container-fluid py-4">
            <div className="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
                <div>
                    <h1 className="h3 mb-1">Audit activity</h1>
                    <p className="text-muted mb-0">Track customer, vendor, admin and super-admin API activity.</p>
                </div>
                <button className="btn btn-outline-primary" type="button" onClick={() => void load()} disabled={loading}>
                    {loading ? "Refreshing…" : "Refresh"}
                </button>
            </div>

            <div className="card border-0 shadow-sm mb-3">
                <div className="card-body d-flex flex-wrap gap-2">
                    <input className="form-control" style={{ maxWidth: 360 }} value={search} onChange={(event) => { setPage(1); setSearch(event.target.value); }} placeholder="Search actor, resource or path" aria-label="Search audit logs" />
                    <select className="form-select" style={{ maxWidth: 200 }} value={role} onChange={(event) => { setPage(1); setRole(event.target.value); }} aria-label="Filter by role">
                        <option value="">All roles</option>
                        <option value="Customer">Customer</option>
                        <option value="Vendor">Vendor</option>
                        <option value="Admin">Admin</option>
                        <option value="Super Admin">Super Admin</option>
                        <option value="Guest">Guest</option>
                    </select>
                </div>
            </div>

            {error ? <div className="alert alert-danger" role="alert"><strong>Something went wrong.</strong> {error} <button className="btn btn-sm btn-outline-danger ms-2" onClick={() => void load()}>Try again</button></div> : null}

            <div className="card border-0 shadow-sm">
                <div className="table-responsive">
                    <table className="table table-hover align-middle mb-0">
                        <thead><tr><th>Actor</th><th>Role</th><th>Action</th><th>Resource</th><th>Result</th><th>IP</th><th>Date</th></tr></thead>
                        <tbody>
                            {loading ? <tr><td colSpan="7" className="text-center py-5">Loading activity…</td></tr> : items.length === 0 ? <tr><td colSpan="7" className="text-center text-muted py-5">No activity records found.</td></tr> : items.map((item) => (
                                <tr key={item.id}>
                                    <td><div className="fw-semibold">{item.actor?.name || "Guest / Unknown"}</div><small className="text-muted">{item.actor?.email || "Unauthenticated request"}</small></td>
                                    <td><span className="badge text-bg-secondary">{item.actorRole || "Guest"}</span></td>
                                    <td><div className="fw-semibold">{item.action || "API activity"}</div><small className="text-muted">{item.method || "—"}</small></td>
                                    <td><div>{item.subjectType || "Endpoint"}</div><small className="text-muted text-break">{item.subjectId || item.path || "—"}</small></td>
                                    <td><span className={Number(item.statusCode) >= 400 ? "badge text-bg-danger" : "badge text-bg-success"}>{statusLabel(item.statusCode)} ({item.statusCode ?? "—"})</span></td>
                                    <td><small>{item.ipAddress || "—"}</small></td>
                                    <td><small>{item.createdAt ? new Date(item.createdAt).toLocaleString() : "—"}</small></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <div className="card-footer bg-white d-flex justify-content-between align-items-center">
                    <small className="text-muted">{pagination?.total ?? items.length} records</small>
                    <div className="d-flex gap-2">
                        <button className="btn btn-sm btn-outline-secondary" disabled={loading || page <= 1} onClick={() => setPage((value) => Math.max(1, value - 1))}>Previous</button>
                        <span className="small align-self-center">Page {pagination?.current_page ?? page} of {pagination?.last_page ?? 1}</span>
                        <button className="btn btn-sm btn-outline-secondary" disabled={loading || !pagination?.has_next} onClick={() => setPage((value) => value + 1)}>Next</button>
                    </div>
                </div>
            </div>
        </div>
    );
}
