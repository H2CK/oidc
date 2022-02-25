const path = require('path')

const webpackConfig = require('@nextcloud/webpack-vue-config')

webpackConfig.entry = {
	redirect: path.join(__dirname, 'src', 'redirect.js'),
	main: path.join(__dirname, 'src', 'main.js'),
}

module.exports = webpackConfig
