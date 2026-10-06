import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'

export default defineConfig({
  base: '',
  plugins: [vue(), tailwindcss()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    port: 5173,
    proxy: {
      // 前端统一以 /api 前缀调用，转发到 Webman（去掉 /api）
      '/api': {
        target: 'http://127.0.0.1:8787',
        changeOrigin: true,
        rewrite: (path) => path.replace(/^\/api/, ''),
      },
      // 附件（本地存储）也代理到后端：缩略图 <img src="/storage/..."> 与「查看」新窗口
      // 都从 5173 发请求，若不代理，Vite 会把 /storage 当 SPA 路由回退到 index.html，
      // 于是「查看」跳到 #/dashboard。生产由 Nginx 托管/反代（见 02-安装部署 §5）。
      '/storage': {
        target: 'http://127.0.0.1:8787',
        changeOrigin: true,
      },
    },
  },
  build: {
    chunkSizeWarningLimit: 2000,
    rollupOptions: {
      output: {
        // 用函数式分包：echarts 是按需引入（echarts/core），写死模块名会匹配不到
        manualChunks(id) {
          if (!id.includes('node_modules')) {
            return undefined
          }
          if (id.includes('echarts') || id.includes('zrender')) {
            return 'echarts'
          }
          if (id.includes('element-plus')) {
            return 'element-plus'
          }
          if (id.includes('vue-router') || id.includes('pinia') || /node_modules[\\/]@?vue[\\/]/.test(id)) {
            return 'vue'
          }
          return undefined
        },
      },
    },
  },
})
