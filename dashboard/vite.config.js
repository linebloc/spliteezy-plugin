import { resolve } from 'path';
import { fileURLToPath } from 'url';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';

const __dirname = fileURLToPath(new URL('.', import.meta.url));

export default defineConfig({
  plugins: [tailwindcss(), react()],
  build: {
    // Output to plugin/assets — JS lands in js/, CSS in css/
    outDir: resolve(__dirname, '../assets'),
    emptyOutDir: false,
    // The IIFE output would otherwise inline all CSS into the JS bundle;
    // WordPress enqueues assets/css/dashboard.css as a real stylesheet.
    cssCodeSplit: false,
    // Keep __/sprintf names and translators comments in the bundle so
    // `wp i18n make-pot` can extract strings from the compiled file — the
    // translation JSON is keyed to assets/js/dashboard.js, not the source.
    minify: 'terser',
    terserOptions: {
      mangle: { reserved: ['__', 'sprintf'] },
      format: { comments: /translators:/i },
    },
    rollupOptions: {
      input: resolve(__dirname, 'src/main.jsx'),
      // Translations come from WordPress core's wp.i18n global
      // (wp_set_script_translations), not from the bundle.
      external: ['@wordpress/i18n'],
      output: {
        // IIFE so the external import compiles to a wp.i18n global read —
        // WordPress loads this file as a classic script, not a module.
        format: 'iife',
        globals: { '@wordpress/i18n': 'wp.i18n' },
        entryFileNames: 'js/dashboard.js',
        chunkFileNames: 'js/dashboard-[name].js',
        assetFileNames: (info) => {
          if (info.name?.endsWith('.css')) {
            return 'css/dashboard.css';
          }

          return 'js/[name][extname]';
        },
        inlineDynamicImports: true,
      },
    },
  },
});
