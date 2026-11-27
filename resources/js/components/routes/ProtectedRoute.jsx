import { Navigate } from "react-router-dom";
import useAuth from "../../hooks/useAuth";
import Loader from "../common/feedback/Loader";

const ProtectedRoute = ({ children, roles = [] }) => {
    const { initializing, isAuthenticated, user } = useAuth();

    if (initializing) return <Loader />;
    if (!isAuthenticated) return <Navigate to="/login" replace />;

    const userRoles = (user?.roles ?? (user?.role ? [user.role] : []))
        .map((role) => String(typeof role === "object" ? role.name : role).toLowerCase());
    const allowed = roles.length === 0 || roles.some((role) => userRoles.includes(String(role).toLowerCase()));

    if (!allowed) {
        return <main className="container py-5" role="alert">
            <div className="alert alert-danger">
                <h1 className="h4">Access denied</h1>
                <p className="mb-0">Your account does not have permission to access this dashboard area.</p>
            </div>
        </main>;
    }
    return children;
};

export default ProtectedRoute;
