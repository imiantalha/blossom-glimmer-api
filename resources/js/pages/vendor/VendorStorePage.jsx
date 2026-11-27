import { useCallback, useEffect, useState } from "react";
import api from "../../api/axios";

const EMPTY = { name: "", slug: "", description: "", logo: "", banner: "", email: "", phone: "" };

export default function VendorStorePage() {
    const [store, setStore] = useState(null);
    const [form, setForm] = useState(EMPTY);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState("");
    const [message, setMessage] = useState("");

    const load = useCallback(async () => {
        setLoading(true); setError("");
        try {
            const response = await api.get("/vendor/store/me");
            if (!response?.data?.success) throw new Error("The server returned an unexpected store response.");
            const value = response.data.data;
            setStore(value && typeof value === "object" ? value : null);
            setForm(value ? { name: value.name || "", slug: value.slug || "", description: value.description || "", logo: value.logo || "", banner: value.banner || "", email: value.email || "", phone: value.phone || "" } : EMPTY);
        } catch (exception) { setError(exception?.response?.data?.message || exception?.message || "Unable to load your store."); }
        finally { setLoading(false); }
    }, []);

    useEffect(() => { void load(); }, [load]);

    const update = (event) => setForm((current) => ({ ...current, [event.target.name]: event.target.value }));
    const save = async (event) => {
        event.preventDefault(); setSaving(true); setError(""); setMessage("");
        try {
            const response = store ? await api.patch("/vendor/store", form) : await api.post("/vendor/store", form);
            if (!response?.data?.success || !response?.data?.data) throw new Error("The server returned an unexpected store save response.");
            setStore(response.data.data);
            setMessage(store ? "Store profile updated." : "Store application submitted for admin approval.");
        } catch (exception) { setError(exception?.response?.data?.message || exception?.message || "Unable to save your store."); }
        finally { setSaving(false); }
    };

    if (loading) return <div className="container py-5 text-center">Loading store profile…</div>;
    return <div className="container-fluid py-4">
        <h1 className="h3">Store profile</h1>
        <p className="text-muted">Create your store or update its public profile. New stores require admin approval before selling.</p>
        {error ? <div className="alert alert-danger" role="alert">{error}</div> : null}
        {message ? <div className="alert alert-success" role="status">{message}</div> : null}
        {store ? <p className="mb-3">Status: <span className="badge text-bg-secondary">{store.status}</span></p> : null}
        <form className="card border-0 shadow-sm" onSubmit={save}><div className="card-body">
            <div className="row g-3">
                {[
                    ["name","Store name","text"],["slug","Store URL slug","text"],["email","Store email","email"],["phone","Store phone","text"],["logo","Logo image URL","url"],["banner","Banner image URL","url"]
                ].map(([name,label,type]) => <div className="col-md-6" key={name}><label className="form-label" htmlFor={name}>{label}</label><input id={name} name={name} type={type} className="form-control" value={form[name]} onChange={update} required={["name","slug"].includes(name)} /></div>)}
                <div className="col-12"><label className="form-label" htmlFor="description">Description</label><textarea id="description" name="description" className="form-control" rows="4" value={form.description} onChange={update} /></div>
            </div>
            <button className="btn btn-primary mt-4" disabled={saving}>{saving ? "Saving…" : store ? "Save store profile" : "Submit store for approval"}</button>
        </div></form>
    </div>;
}
