const Select = ({
    label,
    name,
    value,
    onChange,
    options = [],
    placeholder = "Select an option",
    error,
    required = false,
    disabled = false,
    iconLeft,
}) => {
    return (
        <div className="mb-3">

            {label && (
                <label
                    htmlFor={name}
                    className="form-label"
                >
                    {label}
                </label>
            )}

            <div className="input-group">

                {iconLeft && (
                    <span className="input-group-text">
                        {iconLeft}
                    </span>
                )}

                <select
                    id={name}
                    name={name}
                    value={value}
                    onChange={onChange}
                    required={required}
                    disabled={disabled}
                    className={`form-select ${
                        error ? "is-invalid" : ""
                    }`}
                >
                    <option value="">
                        {placeholder}
                    </option>

                    {options.map((option) => (
                        <option
                            key={option.value}
                            value={option.value}
                        >
                            {option.label}
                        </option>
                    ))}
                </select>

            </div>

            {error && (
                <div className="invalid-feedback d-block">
                    {Array.isArray(error)
                        ? error[0]
                        : error}
                </div>
            )}

        </div>
    );
};

export default Select;