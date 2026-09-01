import { useState, useEffect } from "react";

import useApi from "./useApi";
import roleService from "../services/role.service";

const useRoles = () => {
    const [roles, setRoles] = useState([]);

    const {
        loading,
        error,
        execute,
    } = useApi(roleService.getRoles);

    const fetchRoles = async () => {
        try {
            const response = await execute();

            setRoles(response.data.data);
        } catch (err) {
        }
    };

    useEffect(() => {
        fetchRoles();
    }, []);

    return {
        roles,
        loading,
        error,
        refresh: fetchRoles
    };
};

export default useRoles;