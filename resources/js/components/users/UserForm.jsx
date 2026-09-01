import Input from "../common/form/Input";
import Button from "../common/form/Button";
import Select from "../common/form/Select"; 

const UserForm = ({
    mode = "create",
    form,
    loading = false,
    errors = {},
    roles = [],
    rolesLoading = false,
    onChange,
    onSubmit,
    onCancel,
}) => {
    const isEdit = mode === "edit";

    return (
        <form onSubmit={onSubmit}>
            <Input
                label="Name"
                name="name"
                value={form.name}
                onChange={onChange}
                placeholder="Enter name"
                error={errors.name}
                iconLeft={<i className="bi bi-person"></i>}
                required
            />

            <Input
                label="Email"
                name="email"
                type="email"
                value={form.email}
                onChange={onChange}
                placeholder="Enter email"
                error={errors.email}
                iconLeft={<i className="bi bi-envelope"></i>}
                required
            />

            <Select
                label="Role"
                name="role"
                value={form.role}
                onChange={onChange}
                placeholder={
                    rolesLoading
                        ? "Loading roles..."
                        : "Select a role"
                }
                disabled={rolesLoading}
                error={errors.role}
                required
                iconLeft={
                    <i className="bi bi-shield-check"></i>
                }
                options={roles.map((role) => ({
                    value: role.name,
                    label: role.name,
                }))}
            />
            
            <Input
                label="Password"
                name="password"
                type="password"
                value={form.password}
                onChange={onChange}
                placeholder="Enter password"
                error={errors.password}
                iconLeft={<i className="bi bi-lock"></i>}
                required={!isEdit}
            />

            <Input 
                label="Confirm Password"
                name="password_confirmation"
                type="password"
                value={form.password_confirmation}
                onChange={onChange}
                placeholder="Confirm password"
                error={errors.password_confirmation}
                iconLeft={<i className="bi bi-lock"></i>}
                required={!isEdit}
            />

            <div className="d-flex justify-content-end gap-2 mt-4">

                <Button
                    type="button"
                    variant="secondary"
                    onClick={onCancel}
                    disabled={loading}
                >
                    Cancel
                </Button>

                <Button
                    type="submit"
                    disabled={loading}
                >
                    {loading && (
                        <span
                            className="spinner-border spinner-border-sm me-2"
                            role="status"
                        />
                    )}

                    {isEdit ? "Update User" : "Create User"}
                </Button>

            </div>
        </form>    
    );
};

export default UserForm;