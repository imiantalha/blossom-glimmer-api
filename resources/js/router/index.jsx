import { BrowserRouter, Routes, Route } from "react-router-dom";
import GuestRoute from "../components/routes/GuestRoute";
import ProtectedRoute from "../components/routes/ProtectedRoute";

import GuestLayout from '../layouts/GuestLayout';
import DashboardLayout from '../layouts/DashboardLayout';

import Login from '../pages/auth/Login';
import Dashboard from '../pages/dashboard/Dashboard';
import ErrorPage from '../pages/errors/ErrorPage';
import UsersPage from "../pages/users/UsersPage";
import CreateUserPage from "../pages/users/CreateUserPage";
import EditUserPage from "../pages/users/EditUserPage";

export default function Router() {
    return (
        <BrowserRouter>
            <Routes>
                <Route element={
                    <GuestRoute>
                        <GuestLayout />
                    </GuestRoute>
                }>
                    <Route path="/login" element={<Login />} />
                </Route>

                <Route element={
                    <ProtectedRoute>
                        <DashboardLayout />
                    </ProtectedRoute>
                }>
                    <Route index element={<Dashboard />} />
                    <Route path="dashboard" index element={<Dashboard />} />
                    <Route path="/users" element={<UsersPage />} />
                    <Route path="/users/create" element={<CreateUserPage />} />
                    <Route path="/users/:id/edit" element={<EditUserPage />} />
                </Route>
                <Route path="*" element={<ErrorPage 
                    code="404" 
                    title="Page Not Found" 
                    description="The page you are looking for does not exist." />} />
            </Routes>    
        </BrowserRouter>
    );
}