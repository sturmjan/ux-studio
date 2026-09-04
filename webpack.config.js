/**
 * Extends the default @wordpress/scripts webpack config to put a content hash
 * in every output filename (main bundle, lazy chunks, CSS). WordPress versions
 * assets via a ?ver query string, but some sites/optimizers strip query strings
 * from static assets, which would then serve a STALE bundle forever after an
 * update. A hash in the FILENAME survives that: every build is a new URL.
 *
 * The plugin resolves the actual hashed filenames at enqueue time
 * (Core\Admin::enqueue) and the i18n merge (bin/merge-json-translations.php)
 * targets the real filename's md5, so translations keep loading.
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const MiniCssExtractPlugin = require( 'mini-css-extract-plugin' );

module.exports = {
	...defaultConfig,
	output: {
		...defaultConfig.output,
		filename: '[name].[contenthash].js',
		chunkFilename: '[name].[contenthash].js',
	},
	plugins: defaultConfig.plugins.map( ( plugin ) =>
		plugin && plugin.constructor && plugin.constructor.name === 'MiniCssExtractPlugin'
			? new MiniCssExtractPlugin( {
					filename: '[name].[contenthash].css',
					chunkFilename: '[name].[contenthash].css',
			  } )
			: plugin
	),
};
