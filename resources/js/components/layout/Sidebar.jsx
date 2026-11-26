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
                    <li className="nav-item"><NavLink to="/vendor/products" className={linkClass}>My Products</NavLink></li>
                    <li className="nav-item"><NavLink to="/vendor/orders" className={linkClass}>My Orders</NavLink></li>
                    <li className="nav-item"><NavLink to="/vendor/store" className={linkClass}>Store Profile</NavLink></li>
                    <li className="nav-item"><NavLink to="/vendor/finance" className={linkClass}>Earnings & Payouts</NavLink></li>
                    <li className="nav-item"><NavLink to="/vendor/support" className={linkClass}>Support</NavLink></li>
                    <li className="nav-item"><NavLink to="/vendor/reviews" className={linkClass}>Product Reviews</NavLink></li>
                    <li className="nav-item"><NavLink to="/notifications" className={linkClass}>Notifications</NavLink></li>
                ) : null}
                {isAdmin ? (
                    <>
                        <li className="nav-item"><NavLink to="/dashboard" className={linkClass}>Dashboard</NavLink></li>
                        <li className="nav-item"><NavLink to="/users" className={linkClass}>Users</NavLink></li>
                        <li className="nav-item"><NavLink to="/customers" className={linkClass}>Customers</NavLink></li>
                        <li className="nav-item"><NavLink to="/audit-logs" className={linkClass}>Audit Activity</NavLink></li>
                        <li className="nav-item"><NavLink to="/payouts" className={linkClass}>Vendor Payouts</NavLink></li>
                        <li className="nav-item"><NavLink to="/refunds" className={linkClass}>Returns & Refunds</NavLink></li>
                        <li className="nav-item"><NavLink to="/coupons" className={linkClass}>Coupons & Promotions</NavLink></li>
                        <li className="nav-item"><NavLink to="/orders" className={linkClass}>Marketplace Orders</NavLink></li>
                        <li className="nav-item"><NavLink to="/products" className={linkClass}>Product Moderation</NavLink></li>
                        <li className="nav-item"><NavLink to="/stores" className={linkClass}>Vendor Stores</NavLink></li>
                        <li className="nav-item"><NavLink to="/support" className={linkClass}>Support Tickets</NavLink></li>
                        <li className="nav-item"><NavLink to="/notifications" className={linkClass}>Notifications</NavLink></li>
                        {roles.includes("super admin") ? (
                            <>
                                <li className="nav-item"><NavLink to="/roles" className={linkClass}>Roles</NavLink></li>
                                <li className="nav-item"><NavLink to="/permissions" className={linkClass}>Permissions</NavLink></li>
                            </>
                        ) : null}

                    </>
                ) : null}
            </ul>
        </aside>
    );
};

export default Sidebar;
