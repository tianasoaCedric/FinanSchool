import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// https://vite.dev/config/
export default defineConfig(({ mode }) => ({
  plugins: [
    vue(),
  ],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url))
    },
  },
  css: {
    preprocessorOptions: {
      scss: {
        // Injecté dans chaque <style lang="scss"> : variables et mixins uniquement.
        additionalData: `@use "@/assets/styles/index" as *;`
      }
    }
  },
  esbuild: {
    // En production, on retire les console.log/debug (on garde warn/error).
    pure: mode === 'production' ? ['console.log', 'console.debug', 'console.info'] : [],
  },
  build: {
    rollupOptions: {
      output: {
        // Les grosses librairies dans des fichiers séparés, mis en cache par le
        // navigateur et chargés seulement par les pages qui en ont besoin.
        manualChunks(id) {
          if (!id.includes('node_modules')) return
          if (id.includes('apexcharts')) return 'vendor-apexcharts'
          if (id.includes('chart.js') || id.includes('vue-chartjs')) return 'vendor-chartjs'
          // xlsx / jspdf / html2pdf : laissés au découpage automatique de Rollup
          // (un chunk manuel y aspirait du code partagé et le chargeait au démarrage).
          if (/node_modules\/(vue|@vue|vue-router|pinia)\//.test(id)) return 'vendor-vue'
        },
      },
    },
  },
}))
