import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// In development the SPA and the API share one origin through this proxy, so the Sanctum session cookie works
// without CORS. Start the backend with `php artisan serve` (port 8000) or set KARIVA_API.
const api = process.env.KARIVA_API ?? 'http://127.0.0.1:8000'

export default defineConfig({
  plugins: [vue()],
  server: { proxy: { '/api': api, '/sanctum': api, '/storage': api } },
})
