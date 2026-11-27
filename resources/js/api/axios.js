import axios from "axios";
import token from "../utils/token";

const api = axios.create({
    baseURL: "/api/v1",
    "headers": {
        Accept: "application/json",
        "Content-Type": "application/json",
    } ,
});

api.interceptors.request.use(
    (config) => {
        const accessToken = token.get();

        if (accessToken) {
            config.headers.Authorization = `Bearer ${accessToken}`;
        }

        return config;
    },
    (error) => {
        return Promise.reject(error);
    }
);

export default api;