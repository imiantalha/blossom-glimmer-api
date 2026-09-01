import { useState } from "react";
import { useNavigate } from "react-router-dom";

import PageHeader from "../../components/common/layout/PageHeader";
import Card from "../../components/common/data-display/Card";
import UserForm from "../../components/users/UserForm";

import useApi from "../../hooks/useApi";
import userService from "../../services/user.service";
import { useToast } from "../../contexts/ToastContext";
import useRoles from "../../hooks/useRoles";

const CreateUserPage = () => {
    const navigate = useNavigate();
    const toast = useToast();

    const [form, setForm] = useState({
        name: "",
        email: "",
        password: "",
        password_confirmation: "",
    });

    const [errors, setErrors] = useState({});

    const {
        loading,
        error,
        execute,
    } = useApi(userService.createUser);

    const {
        roles,
        loading: rolesLoading,
        error: rolesError,
    } = useRoles();

    const handleChange = (e) => {
        const { name, value } = e.target;

        setForm((previous) => ({
            ...previous,
            [name]: value,
        }));

        setErrors((previous) => ({
            ...previous,
            [name]: undefined,
        }));
    };

    const handleSubmit = async (e) => {
        e.preventDefault();

        setErrors({});

        try {
            const response = await execute(form);

            toast.success(
                response.data.message ||
                "User created successfully."
            );

            navigate("/users");
        } catch (err) {
            if (err.response?.status === 422) {
                setErrors(
                    err.response.data.errors ?? {}
                );
            }
        }
    };

    const handleCancel = () => {
        navigate("/users");
    };

    return (
        <>
            <PageHeader
                title="Create User"
                subtitle="Create a new system user."
                breadcrumb={[
                    {
                        label: "Dashboard",
                        href: "/dashboard",
                    },
                    {
                        label: "Users",
                        href: "/users",
                    },
                    {
                        label: "Create User",
                    },
                ]}
            />

            <Card className="shadow-sm border-0">

                {error && (
                    <div className="alert alert-danger">
                        {error}
                    </div>
                )}

                <UserForm
                    mode="create"
                    form={form}
                    errors={errors}
                    loading={loading}
                    roles={roles}
                    rolesLoading={rolesLoading}
                    onChange={handleChange}
                    onSubmit={handleSubmit}
                    onCancel={handleCancel}
                />

            </Card>
        </>
    );
};

export default CreateUserPage;