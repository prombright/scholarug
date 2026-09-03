import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  plugins: [vue()],
  // See scholar/spa/vite.config.js for the full reasoning -- same setup:
  // app_student.php injects a <base href> and window.__SCHOLAR_*__ globals
  // when it serves this build, so relative './assets/...' paths resolve
  // correctly regardless of where app_student.php itself is deployed.
  base: './',
  build: {
    outDir: '../assets/spa-student',
    emptyOutDir: true
  },
  server: {
    port: 5174,
    proxy: {
      '/ScholarUg': { target: 'http://localhost', changeOrigin: true }
    }
  }
})
