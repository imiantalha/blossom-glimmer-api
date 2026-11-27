import { useCallback, useEffect, useState } from "react";
import api from "../../api/axios";
import Pagination from "../../components/common/navigation/Pagination";

export default function VendorFinancePage() {
    const [summary, setSummary] = useState(null);
    const [payouts, setPayouts] = useState([]);
    const [page, setPage] = useState(1);
    const [ledgerPage, setLedgerPage] = useState(1);
    const [ledger, setLedger] = useState([]);
    const [ledgerPagination, setLedgerPagination] = useState(null);
    const [selectedCurrency, setSelectedCurrency] = useState("USD");
    const [pagination, setPagination] = useState(null);
    const [amount, setAmount] = useState("");
    const [note, setNote] = useState("");
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState("");
    const [notice, setNotice] = useState("");

    const load = useCallback(async () => {
        setLoading(true); setError("");
        try {
            const [financeResponse, payoutResponse, ledgerResponse] = await Promise.all([api.get("/vendor/finance/summary"), api.get("/vendor/finance/payouts", { params: { page, per_page: 25 } }), api.get("/vendor/finance/ledger", { params: { page: ledgerPage, per_page: 25 } })]);
            if (!financeResponse?.data?.success || !financeResponse?.data?.data || !payoutResponse?.data?.success) throw new Error("Unexpected finance response.");
            setSummary(financeResponse.data.data);
            if (!financeResponse.data.data?.balances?.some((balance) => balance.currency === selectedCurrency)) setSelectedCurrency(financeResponse.data.data?.currency ?? "USD");
            setPayouts(payoutResponse.data.data?.data ?? []);
            setPagination(payoutResponse.data.data?.pagination ?? null);
            setLedger(ledgerResponse.data.data?.data ?? []);
            setLedgerPagination(ledgerResponse.data.data?.pagination ?? null);
        } catch (exception) {
            setError(exception?.response?.data?.message || exception?.message || "Unable to load finance details.");
        } finally { setLoading(false); }
    }, [page, selectedCurrency, ledgerPage]);

    useEffect(() => { void load(); }, [load]);

    const activeSummary = summary?.balances?.find((balance) => balance.currency === selectedCurrency) ?? summary;

    async function submit(event) {
        event.preventDefault(); setError(""); setNotice("");
        const value = Number(amount);
        if (!Number.isFinite(value) || value <= 0) { setError("Enter a valid payout amount."); return; }
        setSaving(true);
        try {
            const response = await api.post("/vendor/finance/payouts", { amount: value, currency: activeSummary?.currency ?? "USD", vendor_note: note });
            if (!response?.data?.success) throw new Error(response?.data?.message || "Payout request failed.");
            setAmount(""); setNote(""); setNotice("Payout request submitted for administrator review."); await load();
        } catch (exception) { setError(exception?.response?.data?.message || exception?.message || "Unable to submit payout request."); }
        finally { setSaving(false); }
    }

    if (loading) return <div className="container py-5 text-center">Loading finance…</div>;
    return <div className="container-fluid py-4">
        <div className="d-flex flex-wrap justify-content-between align-items-start gap-3"><div><h1 className="h3 mb-1">Earnings & payouts</h1><p className="text-muted">Commission ledger and simulated payout requests. No bank transfer is made.</p></div><div><label className="form-label" htmlFor="vendor-finance-currency">Currency</label><select id="vendor-finance-currency" className="form-select" value={selectedCurrency} onChange={e=>setSelectedCurrency(e.target.value)}>{(summary?.balances??[{currency:summary?.currency??"USD"}]).map(balance=><option key={balance.currency} value={balance.currency}>{balance.currency}</option>)}</select></div></div>
        {error ? <div className="alert alert-danger" role="alert">{error}<button className="btn btn-sm btn-outline-danger ms-3" onClick={() => void load()}>Retry</button></div> : null}
        {notice ? <div className="alert alert-success" role="status">{notice}</div> : null}
        <div className="row g-3 mb-4">
            {[["Gross earned", activeSummary?.grossEarned],["Commission",activeSummary?.commissionDeducted],["Net earned",activeSummary?.netEarned],["Paid out",activeSummary?.paidOut],["Reserved",activeSummary?.reserved],["Available",activeSummary?.availableBalance]].map(([label,value])=><div className="col-sm-6 col-xl-2" key={label}><div className="card h-100 shadow-sm border-0"><div className="card-body"><div className="small text-muted">{label}</div><div className="fs-4 fw-semibold mt-2">{Number(value ?? 0).toFixed(2)} {activeSummary?.currency ?? "USD"}</div></div></div></div>)}
        </div>
        <div className="row g-4">
            <div className="col-lg-4"><div className="card border-0 shadow-sm"><div className="card-body"><h2 className="h5">Request payout</h2><form onSubmit={submit}><label className="form-label" htmlFor="payout-amount">Amount ({activeSummary?.currency ?? "USD"})</label><input id="payout-amount" className="form-control mb-3" type="number" min="0.01" step="0.01" max={activeSummary?.availableBalance ?? 0} value={amount} onChange={e=>setAmount(e.target.value)} required/><label className="form-label" htmlFor="payout-note">Note (optional)</label><textarea id="payout-note" className="form-control mb-3" rows="3" value={note} onChange={e=>setNote(e.target.value)}/><button className="btn btn-primary w-100" disabled={saving || Number(amount)<=0 || Number(amount)>(activeSummary?.availableBalance ?? 0)}>{saving?"Submitting…":"Submit payout request"}</button></form><p className="small text-muted mt-3 mb-0">Requests require admin approval. Approved payouts are recorded as simulated ledger entries only.</p></div></div></div>
            <div className="col-lg-8"><div className="card border-0 shadow-sm"><div className="card-body"><h2 className="h5">Payout history</h2>{payouts.length===0?<p className="text-muted mb-0">No payout requests yet.</p>:<div className="table-responsive"><table className="table align-middle"><thead><tr><th>Requested</th><th>Amount</th><th>Status</th><th>Reference</th></tr></thead><tbody>{payouts.map(item=><tr key={item.id}><td>{new Date(item.created_at).toLocaleDateString()}</td><td>{Number(item.amount).toFixed(2)} {item.currency}</td><td><span className="badge text-bg-secondary">{item.status}</span></td><td>{item.reference || "—"}</td></tr>)}</tbody></table></div>}{pagination && <Pagination currentPage={pagination.current_page} lastPage={pagination.last_page} onPageChange={setPage}/>}</div></div></div>
        </div>
        <div className="card border-0 shadow-sm mt-4"><div className="card-body"><h2 className="h5">Ledger</h2>{!ledger.length?<p className="text-muted mb-0">No earned or payout entries yet. Eligible delivered orders appear here.</p>:<div className="table-responsive"><table className="table"><thead><tr><th>Date</th><th>Type</th><th>Gross</th><th>Commission</th><th>Net</th><th>Reference</th></tr></thead><tbody>{ledger.map(item=><tr key={item.id}><td>{new Date(item.created_at).toLocaleDateString()}</td><td>{item.type}</td><td>{Number(item.gross_amount).toFixed(2)}</td><td>{Number(item.commission_amount).toFixed(2)}</td><td>{Number(item.net_amount).toFixed(2)} {item.currency}</td><td>{item.reference}</td></tr>)}</tbody></table></div>}{ledgerPagination && <Pagination currentPage={ledgerPagination.current_page} lastPage={ledgerPagination.last_page} onPageChange={setLedgerPage}/>}</div></div>
    </div>;
}
