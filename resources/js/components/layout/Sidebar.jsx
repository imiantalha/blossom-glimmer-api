import { NavLink } from "react-router-dom";

const Sidebar = () => {

    return (

        <aside className="col-md-2 bg-dark text-white min-vh-100">

            <h4 className="py-3 text-center">
                Blossom
            </h4>

            <ul className="nav flex-column">

                <li className="nav-item">

                    <NavLink
                        to="/"
                        end
                        className={({ isActive }) =>
                            `nav-link ${isActive ? "active text-white fw-bold" : "text-light"}`
                        }
                    >
                        Dashboard
                    </NavLink>

                </li>

                <li className="nav-item">

                    <NavLink
                        to="/users"
                        className="nav-link text-light"
                    >
                        Users
                    </NavLink>

                </li>

                <li className="nav-item">
                    <NavLink
                        to="/customers"
                        className={({ isActive }) =>
                            `nav-link ${isActive ? "active text-white fw-bold" : "text-light"}`
                        }
                    >
                        Customers
                    </NavLink>
                </li>

                <li className="nav-item">

                    <NavLink
                        to="/roles"
                        className="nav-link text-light"
                    >
                        Roles
                    </NavLink>

                </li>

                <li className="nav-item">

                    <NavLink
                        to="/permissions"
                        className="nav-link text-light"
                    >
                        Permissions
                    </NavLink>

                </li>

                <li className="nav-item">

                    <NavLink
                        to="/request-logs"
                        className="nav-link text-light"
                    >
                        Request Logs
                    </NavLink>

                </li>

            </ul>

        </aside>

    );
};

export default Sidebar;