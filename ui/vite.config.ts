import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'

/**
 * 构建产物直接输出到 PHP 侧的静态目录 public/webui/。
 *
 * 为什么不输出到 ui/dist：部署时只需要 public/webui 一处资源，
 * 后端 Core\Http\AppRoutes 与 Webui\Routes 都在这个目录找 index.html，
 * 少一层拷贝就少一类「本地能跑、部署 404」的问题。
 */
/**
 * PHP 工程里的静态目录。
 *
 * 默认用相对路径（ui/ 的上一级 + public/webui），这样在 CI、别人的机器、
 * 容器里都能构建；本机想指到别处时设 WEBUI_OUT_DIR 覆盖。
 * （之前写死成 /storage/... 导致 GitHub Actions 里 mkdir /storage 失败）
 */
const PHP_PUBLIC_WEBUI = process.env.WEBUI_OUT_DIR
  ? process.env.WEBUI_OUT_DIR
  : fileURLToPath(new URL('../public/webui', import.meta.url))

export default defineConfig({
  plugins: [vue()],
  base: '/webui/',
  resolve: {
    alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
  },
  build: {
    outDir: PHP_PUBLIC_WEBUI,
    emptyOutDir: true,
    sourcemap: false,
    chunkSizeWarningLimit: 900,
    rollupOptions: {
      output: {
        manualChunks: {
          vendor: ['vue', 'vue-router', 'pinia'],
        },
      },
    },
  },
  server: {
    port: 5173,
    proxy: {
      '/webui/api': { target: 'http://127.0.0.1:8008', changeOrigin: true },
      '/api': { target: 'http://127.0.0.1:8008', changeOrigin: true },
    },
  },
})