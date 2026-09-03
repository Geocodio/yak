import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\VideoThemeAssetController::logo
* @see app/Http/Controllers/VideoThemeAssetController.php:17
* @route '/video-theme/logo'
*/
export const logo = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: logo.url(options),
    method: 'get',
})

logo.definition = {
    methods: ["get","head"],
    url: '/video-theme/logo',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\VideoThemeAssetController::logo
* @see app/Http/Controllers/VideoThemeAssetController.php:17
* @route '/video-theme/logo'
*/
logo.url = (options?: RouteQueryOptions) => {
    return logo.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\VideoThemeAssetController::logo
* @see app/Http/Controllers/VideoThemeAssetController.php:17
* @route '/video-theme/logo'
*/
logo.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: logo.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\VideoThemeAssetController::logo
* @see app/Http/Controllers/VideoThemeAssetController.php:17
* @route '/video-theme/logo'
*/
logo.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: logo.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\VideoThemeAssetController::sample
* @see app/Http/Controllers/VideoThemeAssetController.php:44
* @route '/video-theme/sample'
*/
export const sample = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: sample.url(options),
    method: 'get',
})

sample.definition = {
    methods: ["get","head"],
    url: '/video-theme/sample',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\VideoThemeAssetController::sample
* @see app/Http/Controllers/VideoThemeAssetController.php:44
* @route '/video-theme/sample'
*/
sample.url = (options?: RouteQueryOptions) => {
    return sample.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\VideoThemeAssetController::sample
* @see app/Http/Controllers/VideoThemeAssetController.php:44
* @route '/video-theme/sample'
*/
sample.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: sample.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\VideoThemeAssetController::sample
* @see app/Http/Controllers/VideoThemeAssetController.php:44
* @route '/video-theme/sample'
*/
sample.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: sample.url(options),
    method: 'head',
})

const VideoThemeAssetController = { logo, sample }

export default VideoThemeAssetController