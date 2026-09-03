import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  plugins: [vue()],
  // See scholar/spa/vite.config.js for the full reasoning.
  base: './',
  build: {
    outDir: '../assets/spa-hr',
    emptyOutDir: true
  },
  server: {
    port: 5176,
    proxy: {
      '/ScholarUg': { target: 'http://localhost', changeOrigin: true }
    }
  }
})
