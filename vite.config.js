import { defineConfig } from 'vite';
import { resolve } from 'path';

export default defineConfig({
	base: './',
	build: {
		outDir: 'assets/js/dist',
		emptyDir: true,
		target: 'es2020',
		rollupOptions: {
			input: resolve(__dirname, 'src/main.js'),
			output: {
				entryFileNames: 'menagerie-optimizer.js',
				chunkFileNames: 'menagerie-[name]-[hash].js',
				assetFileNames: 'menagerie-assets-[name]-[hash][extname]',
				format: 'es',
			},
		},
	},
	worker: {
		format: 'es',
	},
});
