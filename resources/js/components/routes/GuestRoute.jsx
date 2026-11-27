import { Navigate } from "react-router-dom";
import useAuth from "../../hooks/useAuth";
import Loader from "../common/feedback/Loader";

const GuestRoute = ({ children }) => {
    const { initializing, isAuthenticated, user } = useAuth();

    if (initializing) return <Loader />;
    if (isAuthenticated) {
        const roles = (user?.roles ?? []).map((role) => String(typeof role === "object" ? role.name : role).toLowerCase());
        return <Navigate to={roles.includes("vendor") ? "/vendor" : "/dashboard"} replace />;
    }
    return children;
};

export default GuestRoute;
