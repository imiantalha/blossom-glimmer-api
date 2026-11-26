import { useState } from "react";
import { Link, useNavigate } from "react-router-dom";

import useAuth from "../../hooks/useAuth";

import Alert from "../common/feedback/Alert";
import Button from "../common/form/Button";
import Input from "../common/form/Input";
import PasswordInput from "../common/form/PasswordInput";

const LoginForm = () => {
    const navigate = useNavigate();

    const { login, loading } = useAuth();

    const [form, setForm] = useState({
        email: "",
        password: "",
        remember: false,
    });

    const [errors, setErrors] = useState({});
    const [apiError, setApiError] = useState("");

    const handleChange = (e) => {
        const { name, value, checked, type } = e.target;

        setForm((prev) => ({
            ...prev,
            [name]: type === "checkbox" ? checked : value,
        }));
    };

    const handleSubmit = async (e) => {
        e.preventDefault();

        setErrors({});
        setApiError("");

        try {
            const session = await login(form);
            const roles = (session?.user?.roles ?? []).map((role) => String(role).toLowerCase());
            navigate(roles.includes("vendor") ? "/vendor" : "/dashboard", { replace: true });
        } catch (error) {
            if (error.response?.status === 422) {
                setErrors(error.response.data.errors);

                return;
            }

            setApiError(
                error.response?.data?.message ??
                    "Unable to login."
            );
        }
    };

    return (
        <form onSubmit={handleSubmit}>

            <Alert message={apiError} />

            <Input
                label="Email Address"
                name="email"
                type="email"
                value={form.email}
                onChange={handleChange}
                placeholder="Enter your email"
                error={errors.email}
            />

            <PasswordInput
                label="Password"
                name="password"
                value={form.password}
                onChange={handleChange}
                error={errors.password}
            />

            <div className="d-flex justify-content-between align-items-center mb-4">

                <div className="form-check">

                    <input
                        className="form-check-input"
                        type="checkbox"
                        id="remember"
                        name="remember"
                        checked={form.remember}
                        onChange={handleChange}
                    />

                    <label
                        className="form-check-label"
                        htmlFor="remember"
                    >
                        Remember Me
                    </label>

                </div>

                <Link
                    to="#"
                    className="small text-decoration-none"
                >
                    Forgot Password?
                </Link>

            </div>

            <Button
                type="submit"
                loading={loading}
                className="w-100"
            >
                Sign In
            </Button>

        </form>
    );
};

export default LoginForm;