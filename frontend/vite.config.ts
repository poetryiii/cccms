import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'

export default defineConfig({
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
