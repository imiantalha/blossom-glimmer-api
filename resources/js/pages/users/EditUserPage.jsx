import { useEffect, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";

import PageHeader from "../../components/common/layout/PageHeader";
import Card from "../../components/common/data-display/Card";
import UserForm from "../../components/users/UserForm";
import Alert from "../../components/common/feedback/Alert";

import useApi from "../../hooks/useApi";
import useRoles from "../../hooks/useRoles";

import userService from "../../services/user.service";
import { useToast } from "../../contexts/ToastContext";

const EditUserPage = () => {
    const { id } = useParams();
    const navigate = useNavigate();
    const toast = useToast();

    const [form, setForm] = useState({
        name: "",
        email: "",
        role: "",
        password: "",
        password_confirmation: "",
    });

    const [errors, setErrors] = useState({});

    /*
    |--------------------------------------------------------------------------
    | Fetch User
    |--------------------------------------------------------------------------
    */

    const {
        loading,
        error,
        execute,
    } = useApi(() => userService.getUser(id));

    /*
    |--------------------------------------------------------------------------
    | Update User
    |--------------------------------------------------------------------------
    */

    const {
        loading: updateLoading,
        error: updateError,
        execute: executeUpdate,
    } = useApi((data) =>
        userService.updateUser(
            data.id,
            data.data
        )
    );

    /*
    |--------------------------------------------------------------------------
    | Fetch Roles
    |--------------------------------------------------------------------------
    */

    const {
        roles,
        loading: rolesLoading,
        error: rolesError,
    } = useRoles();

    /*
    |--------------------------------------------------------------------------
    | Load User
    |--------------------------------------------------------------------------
    */

    useEffect(() => {
        const fetchUser = async () => {
            try {
                const response = await execute();

                const user = response.data.data;

                setForm({
                    name: user.name ?? "",
                    email: user.email ?? "",
                    role: user.roles?.[0] ?? "",
                    password: "",
                    password_confirmation: "",
                });
            } catch (err) {
                // useApi handles the error state
            }
        };

        fetchUser();
    }, [id]);

    /*
    |--------------------------------------------------------------------------
    | Handle Input Changes
    |--------------------------------------------------------------------------
    */

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

    /*
    |--------------------------------------------------------------------------
    | Submit
    |--------------------------------------------------------------------------
    */

    const handleSubmit = async (e) => {
        e.preventDefault();

        setErrors({});

        const updateData = {
            name: form.name,
            email: form.email,
            role: form.role,
        };

        /*
        |--------------------------------------------------------------------------
        | Password is optional during edit
        |--------------------------------------------------------------------------
        */

        if (form.password) {
            updateData.password = form.password;
            updateData.password_confirmation =
                form.password_confirmation;
        }

        try {
            const response = await executeUpdate({
                id,
                data: updateData,
            });

            toast.success(
                response.data.message ||
                    "User updated successfully."
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

    /*
    |--------------------------------------------------------------------------
    | Cancel
    |--------------------------------------------------------------------------
    */

    const handleCancel = () => {
        navigate("/users");
    };

    /*
    |--------------------------------------------------------------------------
    | Render
    |--------------------------------------------------------------------------
    */

    return (
        <>
            <PageHeader
                title="Edit User"
                subtitle="Update system user information."
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
                        label: "Edit User",
                    },
                ]}
            />

            <Card className="shadow-sm border-0">

                {/* Fetch user error */}
                {error && (
                    <Alert
                        variant="danger"
                        message={error}
                    />
                )}

                {/* Update error */}
                {updateError && (
                    <Alert
                        variant="danger"
                        message={updateError}
                    />
                )}

                {/* Roles error */}
                {rolesError && (
                    <Alert
                        variant="danger"
                        message={rolesError}
                    />
                )}

                <UserForm
                    mode="edit"
                    form={form}
                    errors={errors}
                    loading={
                        loading ||
                        updateLoading
                    }
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

export default EditUserPage;