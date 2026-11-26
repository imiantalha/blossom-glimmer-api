import { BrowserRouter, Routes, Route } from "react-router-dom";
import GuestRoute from "../components/routes/GuestRoute";
import ProtectedRoute from "../components/routes/ProtectedRoute";
import GuestLayout from "../layouts/GuestLayout";
import DashboardLayout from "../layouts/DashboardLayout";
import Login from "../pages/auth/Login";
import Dashboard from "../pages/dashboard/Dashboard";
import ErrorPage from "../pages/errors/ErrorPage";
import UsersPage from "../pages/users/UsersPage";
import CreateUserPage from "../pages/users/CreateUserPage";
import EditUserPage from "../pages/users/EditUserPage";
import CustomersPage from "../pages/customers/CustomersPage";
import AuditLogsPage from "../pages/audit/AuditLogsPage";
import VendorDashboard from "../pages/vendor/VendorDashboard";
import VendorProductsPage from "../pages/vendor/VendorProductsPage";
import VendorOrdersPage from "../pages/vendor/VendorOrdersPage";
import VendorStorePage from "../pages/vendor/VendorStorePage";
import RolesPage from "../pages/roles/RolesPage";
import PermissionsPage from "../pages/permissions/PermissionsPage";

export default function Router() {
    return (
        <BrowserRouter>
            <Routes>
                <Route element={<GuestRoute><GuestLayout /></GuestRoute>}>
                    <Route path="/login" element={<Login />} />
                </Route>

                <Route element={<ProtectedRoute roles={["Admin", "Super Admin"]}><DashboardLayout /></ProtectedRoute>}>
                    <Route index element={<Dashboard />} />
                    <Route path="/dashboard" element={<Dashboard />} />
                    <Route path="/users" element={<UsersPage />} />
                    <Route path="/customers" element={<CustomersPage />} />
                    <Route path="/users/create" element={<CreateUserPage />} />
                    <Route path="/users/:id/edit" element={<EditUserPage />} />
                    <Route path="/audit-logs" element={<AuditLogsPage />} />
                    <Route path="/roles" element={<ProtectedRoute roles={["Super Admin"]}><RolesPage /></ProtectedRoute>} />
                    <Route path="/permissions" element={<ProtectedRoute roles={["Super Admin"]}><PermissionsPage /></ProtectedRoute>} />
                </Route>

                <Route element={<ProtectedRoute roles={["Vendor"]}><DashboardLayout /></ProtectedRoute>}>
                    <Route path="/vendor" element={<VendorDashboard />} />
                    <Route path="/vendor/products" element={<VendorProductsPage />} />
                    <Route path="/vendor/orders" element={<VendorOrdersPage />} />
                    <Route path="/vendor/store" element={<VendorStorePage />} />
                </Route>

                <Route path="*" element={<ErrorPage code="404" title="Page Not Found" description="The page you are looking for does not exist." />} />
            </Routes>
        </BrowserRouter>
    );
}
