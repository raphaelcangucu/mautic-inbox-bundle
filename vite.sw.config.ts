import { defineConfig } from "vite";

/**
 * Build separado do service worker.
 *
 * Nao e uma segunda entrada do vite.config.ts porque o modo biblioteca do Vite nao aceita
 * multiplas entradas em formato iife. E emptyOutDir precisa ser false, senao este build apaga
 * o inbox-app.js que o outro acabou de produzir.
 *
 * Worker classico, nao modulo ES: worker como modulo exigiria { type: "module" } no registro
 * e ainda e problema no Firefox.
 */
export default defineConfig({
  build: {
    emptyOutDir: false,
    outDir: "Assets/dist",
    minify: "oxc",
    sourcemap: true,
    lib: {
      entry: "Frontend/sw/sw.ts",
      name: "MauticInboxServiceWorker",
      formats: ["iife"],
      fileName: () => "inbox-sw.js",
    },
  },
});
