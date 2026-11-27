import api from "../api/axios";

const getUsers = (params = {}) => api.get("/admin/users", { params });
const getUser = (id) => api.get(`/admin/users/${encodeURIComponent(id)}`);
const createUser = (data) => api.post("/admin/users", data);
const updateUser = (id, data) => api.patch(`/admin/users/${encodeURIComponent(id)}`, data);
const deleteUser = (id) => api.delete(`/admin/users/${encodeURIComponent(id)}`);

export default { getUsers, getUser, createUser, updateUser, deleteUser };
