import { useCallback, useEffect, useState } from "react";
import api from "../../api/axios";

export default function PermissionsPage() {
    const [permissions, setPermissions] = useState([]);
    const [name, setName] = useState("");
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState("");
    const [message, setMessage] = useState("");

    const load = useCallback(async () => {
        setLoading(true); setError("");
        try {
            const response = await api.get("/permissions");
            if (!response?.data?.success || !Array.isArray(response?.data?.data)) throw new Error("The server returned an unexpected permissions format.");
            setPermissions(response.data.data);
        } catch (exception) { setError(exception?.response?.data?.message || exception?.message || "Unable to load permissions."); }
        finally { setLoading(false); }
    }, []);

    useEffect(() => { void load(); }, [load]);

    const create = async (event) => {
        event.preventDefault(); setSaving(true); setError(""); setMessage("");
        try {
            if (!name.trim()) throw new Error("Enter a permission name.");
            await api.post("/permissions", { name: name.trim() });
            setName(""); setMessage("Permission created."); await load();
        } catch (exception) { setError(exception?.response?.data?.message || exception?.message || "Unable to create permission."); }
        finally { setSaving(false); }
    };

    const remove = async (permission) => {
        if (!window.confirm(`Delete permission "${permission.name}"? It may be protected if assigned to a core role.`)) return;
        setError(""); setMessage("");
        try { await api.delete(`/permissions/${permission.id}`); setMessage("Permission deleted."); await load(); }
        catch (exception) { setError(exception?.response?.data?.message || "Unable to delete this permission."); }
    };

    return <div className="container-fluid py-4">
        <h1 className="h3">Permission management</h1>
        <p className="text-muted">Manage custom permissions. Permissions assigned to core marketplace roles cannot be removed.</p>
        {error ? <div className="alert alert-danger" role="alert">{error}</div> : null}
        {message ? <div className="alert alert-success" role="status">{message}</div> : null}
        <form className="card border-0 shadow-sm mb-4" onSubmit={create}><div className="card-body d-flex gap-2">
            <input className="form-control" value={name} onChange={(event) => setName(event.target.value)} placeholder="e.g. reports.export" maxLength={255} required />
            <button className="btn btn-primary text-nowrap" disabled={saving}>{saving ? "Adding…" : "Add permission"}</button>
        </div></form>
        <div className="card border-0 shadow-sm"><div className="table-responsive"><table className="table align-middle mb-0">
            <thead><tr><th>Permission</th><th>Assigned roles</th><th>Actions</th></tr></thead>
            <tbody>{loading ? <tr><td colSpan="3" className="text-center py-4">Loading permissions…</td></tr> : permissions.length ? permissions.map((permission) => <tr key={permission.id}><td className="fw-semibold">{permission.name}</td><td>{permission.roles_count ?? permission.roles?.length ?? 0}</td><td><button className="btn btn-sm btn-outline-danger" onClick={() => void remove(permission)}>Delete</button></td></tr>) : <tr><td colSpan="3" className="text-center text-muted py-4">No permissions found.</td></tr>}</tbody>
        </table></div></div>
    </div>;
}
