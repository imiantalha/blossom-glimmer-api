const ConfirmModal = ({
    show = false,
    title = "Confirm Action",
    message = "Are you sure you want to continue?",
    confirmText = "Confirm",
    cancelText = "Cancel",
    loading = false,
    onConfirm,
    onCancel,
}) => {
    if (!show) {
        return null;
    }

    return (
        <>
            <div
                className="modal fade show d-block"
                tabIndex="-1"
                role="dialog"
            >
                <div
                    className="modal-dialog modal-dialog-centered"
                    role="document"
                >
                    <div className="modal-content">

                        <div className="modal-header">
                            <h5 className="modal-title">
                                {title}
                            </h5>

                            <button
                                type="button"
                                className="btn-close"
                                onClick={onCancel}
                                disabled={loading}
                            />
                        </div>

                        <div className="modal-body">
                            <p className="mb-0">
                                {message}
                            </p>
                        </div>

                        <div className="modal-footer">

                            <button
                                type="button"
                                className="btn btn-secondary"
                                onClick={onCancel}
                                disabled={loading}
                            >
                                {cancelText}
                            </button>

                            <button
                                type="button"
                                className="btn btn-danger"
                                onClick={onConfirm}
                                disabled={loading}
                            >
                                {loading && (
                                    <span
                                        className="spinner-border spinner-border-sm me-2"
                                        role="status"
                                    />
                                )}

                                {confirmText}
                            </button>

                        </div>

                    </div>
                </div>
            </div>

            <div className="modal-backdrop fade show" />
        </>
    );
};

export default ConfirmModal;