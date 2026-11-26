import { useEffect, useState } from "react";
import api from "../../api/axios";
import PageHeader from "../../components/common/layout/PageHeader";
import Card from "../../components/common/data-display/Card";
import Input from "../../components/common/form/Input";
import Button from "../../components/common/form/Button";
import DataTable from "../../components/common/data-display/DataTable";
import Pagination from "../../components/common/navigation/Pagination";
import Alert from "../../components/common/feedback/Alert";

const money = (value, currency = "USD") => new Intl.NumberFormat(undefined, {
    style: "currency", currency,
}).format(Number(value || 0));

export default function CustomersPage() {
    const [customers, setCustomers] = useState([]);
    const [pagination, setPagination] = useState(null);
    const [search, setSearch] = useState("");
    const [page, setPage] = useState(1);
    const [selected, setSelected] = useState(null);
    const [detail, setDetail] = useState(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState("");

    useEffect(() => {
        let active = true;
        const timer = setTimeout(async () => {
            setLoading(true);
            setError("");
            try {
                const response = await api.get("/v1/admin/customers", {
                    params: { search: search || undefined, page, per_page: 20 },
                });
                if (!active) return;
                setCustomers(response.data.data || []);
                setPagination(response.data.pagination || null);
            } catch (exception) {
                if (active) setError(exception.response?.data?.message || "Unable to load customers.");
            } finally {
                if (active) setLoading(false);
            }
        }, 250);
        return () => { active = false; clearTimeout(timer); };
    }, [search, page]);

    const openCustomer = async (customer) => {
        setSelected(customer);
        setDetail(null);
        try {
            const response = await api.get(`/v1/admin/customers/${encodeURIComponent(customer.id)}`);
            setDetail(response.data.data);
        } catch (exception) {
            setError(exception.response?.data?.message || "Unable to load customer purchase history.");
        }
    };

    const columns = [
        { key: "name", label: "Customer" },
        { key: "email", label: "Email" },
        { key: "orderCount", label: "Orders" },
        { key: "lifetimeValue", label: "Lifetime value", render: (row) => money(row.lifetimeValue) },
        { key: "lastOrderAt", label: "Last purchase", render: (row) => row.lastOrderAt ? new Date(row.lastOrderAt).toLocaleDateString() : "—" },
        { key: "isActive", label: "Account", render: (row) => row.isActive ? "Active" : "Disabled" },
        { key: "actions", label: "Details", render: (row) => <Button size="sm" onClick={() => openCustomer(row)}>View</Button> },
    ];

    return (
        <>
            <PageHeader title="Customers" subtitle="Customer accounts and purchase history." breadcrumb={[{ label: "Dashboard", href: "/dashboard" }, { label: "Customers" }]} />
            <Card className="shadow-sm border-0">
                <div className="d-flex gap-3 align-items-center mb-3">
                    <div style={{ maxWidth: 360, width: "100%" }}>
                        <Input type="search" value={search} placeholder="Search name, email or phone..." onChange={(event) => { setSearch(event.target.value); setPage(1); }} />
                    </div>
                    {loading && <span className="spinner-border spinner-border-sm" role="status" aria-label="Loading customers" />}
                </div>
                {error && <Alert variant="danger" message={error} />}
                <DataTable columns={columns} data={customers} />
                {pagination && <Pagination currentPage={pagination.current_page} lastPage={pagination.last_page} onPageChange={setPage} />}
            </Card>
            {selected && (
                <Card className="shadow-sm border-0 mt-4">
                    <div className="d-flex justify-content-between align-items-start">
                        <div>
                            <h5 className="mb-1">{selected.name}</h5>
                            <div className="text-muted">{selected.email} · {selected.phone || "No phone number"}</div>
                        </div>
                        <Button size="sm" variant="secondary" onClick={() => { setSelected(null); setDetail(null); }}>Close</Button>
                    </div>
                    {!detail ? <p className="mt-3 mb-0">Loading purchase history…</p> : (
                        <>
                            <div className="d-flex flex-wrap gap-4 my-3">
                                <div><small className="text-muted d-block">Total orders</small><strong>{detail.orderCount}</strong></div>
                                <div><small className="text-muted d-block">Lifetime value</small><strong>{money(detail.lifetimeValue)}</strong></div>
                                <div><small className="text-muted d-block">Account</small><strong>{detail.isActive ? "Active" : "Disabled"}</strong></div>
                            </div>
                            <h6>Recent purchases</h6>
                            {detail.recentOrders?.length ? (
                                <div className="table-responsive">
                                    <table className="table table-sm align-middle">
                                        <thead><tr><th>Order</th><th>Date</th><th>Status</th><th>Payment</th><th>Fulfilment</th><th className="text-end">Total</th></tr></thead>
                                        <tbody>{detail.recentOrders.map((order) => (
                                            <tr key={order.id}>
                                                <td>{order.number}</td><td>{order.createdAt ? new Date(order.createdAt).toLocaleDateString() : "—"}</td>
                                                <td>{order.status}</td><td>{order.paymentStatus}</td><td>{order.fulfillmentStatus}</td>
                                                <td className="text-end">{money(order.total, order.currency || "USD")}</td>
                                            </tr>
                                        ))}</tbody>
                                    </table>
                                </div>
                            ) : <p className="text-muted mb-0">No purchases yet.</p>}
                        </>
                    )}
                </Card>
            )}
        </>
    );
}
