import { defineConfig } from "vite";
import { svelte } from "@sveltejs/vite-plugin-svelte";

export default defineConfig({
  plugins: [svelte({ emitCss: false })],
  build: {
    emptyOutDir: true,
    outDir: "Assets/dist",
    minify: "oxc",
    sourcemap: true,
    lib: {
      entry: "Frontend/main.ts",
      name: "MauticInboxFrontend",
      formats: ["iife"],
      fileName: () => "inbox-app.js",
    },
  },
});
