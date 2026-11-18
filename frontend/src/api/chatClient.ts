// src/api/chatClient.ts
// Axios client for chat-server (team chat + website live chat REST, and the /staff socket). Same Laravel JWT as the main client.
import axios from 'axios'
import { getFreshToken } from './client'

export const CHAT_URL = (import.meta.env.VITE_CHAT_URL || 'https://chatserver.teamzo.io').replace(/\/$/, '')

export const chatApi = axios.create({
  baseURL: `${CHAT_URL}/api`,
  headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
  timeout: 15_000,
})

chatApi.interceptors.request.use(async (config) => {
  const token = await getFreshToken()
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})
