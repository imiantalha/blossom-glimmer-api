import { useCallback, useEffect, useState } from "react";
import api from "../../api/axios";
import Pagination from "../../components/common/navigation/Pagination";

export default function RolesPage() {
    const [roles, setRoles] = useState([]);
    const [page, setPage] = useState(1);
    const [pagination, setPagination] = useState(null);
    const [permissions, setPermissions] = useState([]);
    const [editing, setEditing] = useState(null);
    const [name, setName] = useState("");
    const [selectedPermissions, setSelectedPermissions] = useState([]);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState("");
    const [message, setMessage] = useState("");

    const load = useCallback(async () => {
        setLoading(true); setError("");
        try {
            const [roleResponse, permissionResponse] = await Promise.all([api.get("/roles", { params: { page, per_page: 25 } }), api.get("/permissions/options")]);
            if (!roleResponse?.data?.success || !Array.isArray(roleResponse?.data?.data) || !permissionResponse?.data?.success || !Array.isArray(permissionResponse?.data?.data)) {
                throw new Error("The server returned an unexpected roles or permissions format.");
            }
            setRoles(roleResponse.data.data);
            setPagination(roleResponse.data.pagination ?? null);
            setPermissions(permissionResponse.data.data);
        } catch (exception) {
            setError(exception?.response?.data?.message || exception?.message || "Unable to load roles.");
        } finally { setLoading(false); }
    }, [page]);

    useEffect(() => { void load(); }, [load]);

    const resetForm = () => { setEditing(null); setName(""); setSelectedPermissions([]); };
    const save = async (event) => {
        event.preventDefault(); setSaving(true); setError(""); setMessage("");
        try {
            const payload = { name: name.trim(), permissions: selectedPermissions };
            if (!payload.name) throw new Error("Enter a role name.");
            if (editing) await api.patch(`/roles/${editing.id}`, payload);
            else await api.post("/roles", payload);
            setMessage(editing ? "Role updated." : "Role created.");
            resetForm(); await load();
        } catch (exception) {
            setError(exception?.response?.data?.message || exception?.message || "Unable to save role.");
        } finally { setSaving(false); }
    };

    const edit = (role) => {
        setEditing(role); setName(role.name);
        setSelectedPermissions(Array.isArray(role.permissions) ? role.permissions.map((permission) => permission.name).filter(Boolean) : []);
        setMessage(""); setError("");
    };

    const remove = async (role) => {
        if (!window.confirm(`Delete custom role "${role.name}"?`)) return;
        setError(""); setMessage("");
        try { await api.delete(`/roles/${role.id}`); setMessage("Role deleted."); await load(); }
        catch (exception) { setError(exception?.response?.data?.message || "Unable to delete this role."); }
    };

    const protectedNames = ["admin", "super admin", "vendor", "customer"];
    return <div className="container-fluid py-4">
        <h1 className="h3">Role management</h1>
        <p className="text-muted">Create custom roles and assign permissions. Core marketplace roles are protected.</p>
        {error ? <div className="alert alert-danger" role="alert">{error}</div> : null}
        {message ? <div className="alert alert-success" role="status">{message}</div> : null}
        <div className="row g-4">
            <div className="col-lg-5">
                <form className="card border-0 shadow-sm" onSubmit={save}>
                    <div className="card-body">
                        <h2 className="h5">{editing ? "Edit role" : "Create role"}</h2>
                        <label className="form-label" htmlFor="role-name">Role name</label>
                        <input id="role-name" className="form-control mb-3" value={name} onChange={(event) => setName(event.target.value)} maxLength={255} required />
                        <label className="form-label">Permissions</label>
                        <div className="border rounded p-3 mb-3" style={{ maxHeight: 300, overflowY: "auto" }}>
                            {permissions.map((permission) => <label className="d-flex gap-2 align-items-center mb-2" key={permission.id}>
                                <input type="checkbox" checked={selectedPermissions.includes(permission.name)} onChange={(event) => setSelectedPermissions((current) => event.target.checked ? [...current, permission.name] : current.filter((item) => item !== permission.name))} />
                                <span>{permission.name}</span>
                            </label>)}
                            {!permissions.length ? <p className="text-muted mb-0">No permissions available.</p> : null}
                        </div>
                        <div className="d-flex gap-2">
                            <button className="btn btn-primary" disabled={saving}>{saving ? "Saving…" : editing ? "Save changes" : "Create role"}</button>
                            {editing ? <button type="button" className="btn btn-outline-secondary" onClick={resetForm}>Cancel</button> : null}
                        </div>
                    </div>
                </form>
            </div>
            <div className="col-lg-7">
                <div className="card border-0 shadow-sm">
                    <div className="table-responsive"><table className="table align-middle mb-0">
                        <thead><tr><th>Role</th><th>Permissions</th><th>Actions</th></tr></thead>
                        <tbody>{loading ? <tr><td colSpan="3" className="text-center py-4">Loading roles…</td></tr> : roles.length ? roles.map((role) => {
                            const isCore = protectedNames.includes(String(role.name).toLowerCase());
                            return <tr key={role.id}><td className="fw-semibold">{role.name}</td><td>{role.permissions_count ?? role.permissions?.length ?? 0}</td><td>{isCore ? <span className="badge text-bg-secondary">Protected</span> : <div className="d-flex gap-2"><button className="btn btn-sm btn-outline-primary" onClick={() => edit(role)}>Edit</button><button className="btn btn-sm btn-outline-danger" onClick={() => void remove(role)}>Delete</button></div>}</td></tr>;
                        }) : <tr><td colSpan="3" className="text-center text-muted py-4">No roles found.</td></tr>}</tbody>
                    </table></div>
                </div>
                {pagination && <Pagination currentPage={pagination.current_page} lastPage={pagination.last_page} onPageChange={setPage} />}
            </div>
        </div>
    </div>;
}
