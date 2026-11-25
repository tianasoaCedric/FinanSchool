import { API_BASE } from '@/config/api';
import axios from "axios";

const API_URL = `${API_BASE}/simulations`;

export const getSimulations = () => axios.get(API_URL);
export const getSimulation = (id) => axios.get(`${API_URL}/${id}`);
export const createSimulation = (data) => axios.post(API_URL, data);
export const updateSimulation = (id, data) => axios.put(`${API_URL}/${id}`, data);
export const deleteSimulation = (id) => axios.delete(`${API_URL}/${id}`);
export const getHistoricalData = (idExercice) =>
  axios.get(`${API_URL}/historical`, { params: { id_exercice: idExercice } });
