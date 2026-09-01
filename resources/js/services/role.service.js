import api from "../api/axios";

const getRoles = () => {
    return api.get("/roles");
};

export default {
    getRoles
};