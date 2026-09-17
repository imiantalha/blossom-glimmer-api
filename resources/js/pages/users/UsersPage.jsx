import { useState } from "react";
import { useNavigate } from "react-router-dom";

import PageHeader from "../../components/common/layout/PageHeader";
import Card from "../../components/common/data-display/Card";
import Button from "../../components/common/form/Button";
import Input from "../../components/common/form/Input";
import DataTable from "../../components/common/data-display/DataTable";
import Pagination from "../../components/common/navigation/Pagination";
import ConfirmModal from "../../components/common/feedback/ConfirmModal";
import Alert from "../../components/common/feedback/Alert";

import { useToast } from "../../contexts/ToastContext";
import useUsers from "../../hooks/useUsers";
import useApi from "../../hooks/useApi";
import userService from "../../services/user.service";

const UsersPage = () => {
    const navigate = useNavigate();
    const toast = useToast();

    const [selectedUser, setSelectedUser] = useState(null);
    const [showConfirmModal, setShowConfirmModal] = useState(false);

    const {
        users,
        pagination,
        setPage,
        loading,
        error,
        search,
        setSearch,
        refresh,
    } = useUsers();

    const {
        loading: deleteLoading,
        error: deleteError,
        execute: executeDelete,
    } = useApi(userService.deleteUser);

    const handleEdit = (user) => {
        navigate(`/users/${user.id}/edit`);
    };

    const handleDeleteClick = (user) => {
        setSelectedUser(user);
        setShowConfirmModal(true);
    };

    const handleDeleteCancel = () => {
        if (deleteLoading) return;

        setSelectedUser(null);
        setShowConfirmModal(false);
    };

    const handleDeleteConfirm = async () => {
        if (!selectedUser) return;

        try {
            const response = await executeDelete(selectedUser.id);

            toast.success(
                response.data.message || "User deleted successfully."
            );

            setSelectedUser(null);
            setShowConfirmModal(false);

            const updatedPagination = await refresh();

            // Current page no longer exists
            if (updatedPagination && page > updatedPagination.last_page) {
                setPage(Math.max(1, updatedPagination.last_page));
            }

        } catch (err) {
            // Error is already handled by useApi
        }
    };

    const columns = [
        {
            key: "name",
            label: "Name",
        },
        {
            key: "email",
            label: "Email",
        },
        {
            key: "roles",
            label: "Roles",
            render: (user) => user.roles.join(", "),
        },
        {
            key: "created_at",
            label: "Created At",
        },
        {
            key: "actions",
            label: "Actions",
            render: (user) => (
                <div className="d-flex gap-2">
                    <Button
                        size="sm"
                        onClick={() => handleEdit(user)}
                        title="Edit User"
                    >
                        <i className="bi bi-pencil-square"></i>
                    </Button>

                    <Button
                        size="sm"
                        variant="danger"
                        onClick={() => handleDeleteClick(user)}
                        title="Delete User"
                    >
                        <i className="bi bi-trash"></i>
                    </Button>
                </div>
            ),
        },
    ];

    return (
        <>
            <PageHeader
                title="Users"
                subtitle="Manage all system users."
                breadcrumb={[
                    {
                        label: "Dashboard",
                        href: "/dashboard",
                    },
                    {
                        label: "Users",
                    },
                ]}
            />

            <Card className="shadow-sm border-0">

                <div className="d-flex justify-content-between align-items-center mb-4">

                    <div
                        className="d-flex align-items-center gap-2"
                        style={{ width: "350px" }}
                    >
                        <div className="flex-grow-1">
                            <Input
                                type="search"
                                placeholder="Search users..."
                                value={search}
                                onChange={(e) =>
                                    setSearch(e.target.value)
                                }
                                iconLeft={
                                    <i className="bi bi-search"></i>
                                }
                            />
                        </div>

                        {loading && (
                            <div
                                className="spinner-border spinner-border-sm text-primary"
                                role="status"
                            >
                                <span className="visually-hidden">
                                    Loading...
                                </span>
                            </div>
                        )}
                    </div>

                    <Button
                        onClick={() => navigate("/users/create")}
                    >
                        <i className="bi bi-plus-lg me-2"></i>
                        Create User
                    </Button>

                </div>

                {error && (
                    <Alert
                        variant="danger"
                        message={error}
                    />
                )}

                {deleteError && (
                    <Alert
                        variant="danger"
                        message={deleteError}
                    />
                )}

                <DataTable
                    columns={columns}
                    data={users}
                />

                {pagination && (
                    <Pagination
                        currentPage={pagination.current_page}
                        lastPage={pagination.last_page}
                        onPageChange={setPage}
                    />
                )}

            </Card>

            <ConfirmModal
                show={showConfirmModal}
                title="Delete User"
                message={
                    selectedUser
                        ? `Are you sure you want to delete "${selectedUser.name}"?`
                        : "Are you sure you want to delete this user?"
                }
                confirmText="Delete"
                cancelText="Cancel"
                loading={deleteLoading}
                onConfirm={handleDeleteConfirm}
                onCancel={handleDeleteCancel}
            />
        </>
    );
};

export default UsersPage;