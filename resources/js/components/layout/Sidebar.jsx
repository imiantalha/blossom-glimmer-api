import { NavLink } from "react-router-dom";
import useAuth from "../../hooks/useAuth";

const Sidebar = () => {
    const { user } = useAuth();
    const roles = (user?.roles ?? (user?.role ? [user.role] : []))
        .map((role) => String(typeof role === "object" ? role.name : role).toLowerCase());
    const isVendor = roles.includes("vendor");
    const isAdmin = roles.includes("admin") || roles.includes("super admin");

    const linkClass = ({ isActive }) => `nav-link ${isActive ? "active text-white fw-bold" : "text-light"}`;

    return (
        <aside className="col-md-2 bg-dark text-white min-vh-100">
            <h4 className="py-3 text-center">Blossom</h4>
            <ul className="nav flex-column">
                {isVendor ? (
                    <li className="nav-item"><NavLink to="/vendor" end className={linkClass}>Vendor Dashboard</NavLink></li>
                ) : null}
                {isAdmin ? (
                    <>
                        <li className="nav-item"><NavLink to="/dashboard" className={linkClass}>Dashboard</NavLink></li>
                        <li className="nav-item"><NavLink to="/users" className={linkClass}>Users</NavLink></li>
                        <li className="nav-item"><NavLink to="/customers" className={linkClass}>Customers</NavLink></li>
                        <li className="nav-item"><NavLink to="/audit-logs" className={linkClass}>Audit Activity</NavLink></li>
                        {roles.includes("super admin") ? (
                            <>
                                <li className="nav-item"><NavLink to="/roles" className={linkClass}>Roles</NavLink></li>
                                <li className="nav-item"><NavLink to="/permissions" className={linkClass}>Permissions</NavLink></li>
                            </>
                        ) : null}
                        <li className="nav-item"><NavLink to="/request-logs" className={linkClass}>Request Logs</NavLink></li>
                    </>
                ) : null}
            </ul>
        </aside>
    );
};

export default Sidebar;
