import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../wayfinder'
import logo from './logo'
/**
* @see \App\Http\Controllers\Settings\VideoThemeController::update
* @see app/Http/Controllers/Settings/VideoThemeController.php:31
* @route '/settings/video'
*/
export const update = (options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(options),
    method: 'put',
})

update.definition = {
    methods: ["put"],
    url: '/settings/video',
} satisfies RouteDefinition<["put"]>

/**
* @see \App\Http\Controllers\Settings\VideoThemeController::update
* @see app/Http/Controllers/Settings/VideoThemeController.php:31
* @route '/settings/video'
*/
update.url = (options?: RouteQueryOptions) => {
    return update.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\VideoThemeController::update
* @see app/Http/Controllers/Settings/VideoThemeController.php:31
* @route '/settings/video'
*/
update.put = (options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(options),
    method: 'put',
})

/**
* @see \App\Http\Controllers\Settings\VideoThemeController::reset
* @see app/Http/Controllers/Settings/VideoThemeController.php:70
* @route '/settings/video/reset'
*/
export const reset = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: reset.url(options),
    method: 'post',
})

reset.definition = {
    methods: ["post"],
    url: '/settings/video/reset',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Settings\VideoThemeController::reset
* @see app/Http/Controllers/Settings/VideoThemeController.php:70
* @route '/settings/video/reset'
*/
reset.url = (options?: RouteQueryOptions) => {
    return reset.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\VideoThemeController::reset
* @see app/Http/Controllers/Settings/VideoThemeController.php:70
* @route '/settings/video/reset'
*/
reset.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: reset.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\VideoThemeController::sample
* @see app/Http/Controllers/Settings/VideoThemeController.php:89
* @route '/settings/video/sample'
*/
export const sample = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: sample.url(options),
    method: 'post',
})

sample.definition = {
    methods: ["post"],
    url: '/settings/video/sample',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Settings\VideoThemeController::sample
* @see app/Http/Controllers/Settings/VideoThemeController.php:89
* @route '/settings/video/sample'
*/
sample.url = (options?: RouteQueryOptions) => {
    return sample.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\VideoThemeController::sample
* @see app/Http/Controllers/Settings/VideoThemeController.php:89
* @route '/settings/video/sample'
*/
sample.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: sample.url(options),
    method: 'post',
})

const video = {
    update: Object.assign(update, update),
    reset: Object.assign(reset, reset),
    logo: Object.assign(logo, logo),
    sample: Object.assign(sample, sample),
}

export default video