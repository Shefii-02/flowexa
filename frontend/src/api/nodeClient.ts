// src/api/nodeClient.ts
// Axios client for backend-node (REST + the /staff socket live there). Same Laravel JWT as the main client.
import axios from 'axios'
import { getFreshToken } from './client'

export const NODE_URL = (import.meta.env.VITE_NODE_URL || 'http://localhost:3000').replace(/\/$/, '')

export const nodeApi = axios.create({
  baseURL: `${NODE_URL}/api`,
  headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
  timeout: 15_000,
})

nodeApi.interceptors.request.use(async (config) => {
  const token = await getFreshToken()
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})
