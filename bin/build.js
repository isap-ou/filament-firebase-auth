import * as esbuild from 'esbuild'

// `npm run build` writes the minified bundle that the repository commits.
// `npm run dev` rebuilds an unminified bundle on each change.
const isDev = process.argv.includes('--dev')

const options = {
    entryPoints: ['resources/js/index.js'],
    outfile: 'resources/dist/filament-firebase-auth.js',
    bundle: true,
    format: 'iife',
    platform: 'browser',
    target: ['es2020'],
    minify: !isDev,
    sourcemap: isDev ? 'inline' : false,
    // Keeps the license notices of the Firebase JS SDK at the end of the bundle.
    legalComments: 'eof',
    logLevel: 'info',
}

if (isDev) {
    const context = await esbuild.context(options)

    await context.watch()
} else {
    await esbuild.build(options)
}
