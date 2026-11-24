import { API_BASE } from '@/config/api';
import axios from 'axios';

const API_URL = `${API_BASE}`;

export const login = (email, password) => {
    return axios.post(`${API_URL}/login`, { email, password });
};

export const getUser = (token) => {
    return axios.get(`${API_URL}/user`, {
        headers: { Authorization: `Bearer ${token}` }
    });
};

export const logout = (token) => {
    return axios.post(`${API_URL}/logout`, {}, {
        headers: { Authorization: `Bearer ${token}` }
    });
};
