import api from "../api/axios";

const getUsers = (params = {}) => {
    return api.get("/users", { params });
}

const getUser = (id) => {
    return api.get(`/users/${id}`);
}

const createUser = (data) => {
    console.log("Creating user with data:", data);
    return api.post("/users", data);
}

const updateUser = (id, data) => {
    return api.put(`/users/${id}`, data);
}

const deleteUser = (id) => {
    return api.delete(`/users/${id}`);
}

export default {
    getUsers,
    getUser,
    createUser,
    updateUser,
    deleteUser,
}