import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  plugins: [vue()],
  // Vite's `base` only accepts an absolute URL, the literal "./", or "" --
  // a relative subpath like "./assets/spa-teacher/" isn't valid here, so
  // the actual redirect-to-the-right-folder trick is a <base href> tag
  // injected by app_teacher.php when it serves this build (see there).
  base: './',
  build: {
    // Ships straight into the folder scholar/app_teacher.php reads from.
    // Committed to git like any other static asset -- the production host
    // has no shell access to run `npm run build`, so the compiled output
    // has to be built locally and deployed as-is.
    outDir: '../assets/spa-teacher',
    emptyOutDir: true
  },
  server: {
    port: 5173,
    proxy: {
      // Only useful for `npm run dev` against the local XAMPP Apache --
      // the real verification path is the built output served by PHP
      // directly, where the session cookie just works with no CORS story.
      '/ScholarUg': {
        target: 'http://localhost',
        changeOrigin: true
      }
    }
  }
})
