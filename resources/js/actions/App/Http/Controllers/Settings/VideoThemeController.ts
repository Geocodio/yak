import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\VideoThemeController::edit
* @see app/Http/Controllers/Settings/VideoThemeController.php:21
* @route '/settings/video'
*/
export const edit = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: edit.url(options),
    method: 'get',
})

edit.definition = {
    methods: ["get","head"],
    url: '/settings/video',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Settings\VideoThemeController::edit
* @see app/Http/Controllers/Settings/VideoThemeController.php:21
* @route '/settings/video'
*/
edit.url = (options?: RouteQueryOptions) => {
    return edit.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\VideoThemeController::edit
* @see app/Http/Controllers/Settings/VideoThemeController.php:21
* @route '/settings/video'
*/
edit.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: edit.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\VideoThemeController::edit
* @see app/Http/Controllers/Settings/VideoThemeController.php:21
* @route '/settings/video'
*/
edit.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: edit.url(options),
    method: 'head',
})

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
* @see \App\Http\Controllers\Settings\VideoThemeController::destroyLogo
* @see app/Http/Controllers/Settings/VideoThemeController.php:77
* @route '/settings/video/logo'
*/
export const destroyLogo = (options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroyLogo.url(options),
    method: 'delete',
})

destroyLogo.definition = {
    methods: ["delete"],
    url: '/settings/video/logo',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\Settings\VideoThemeController::destroyLogo
* @see app/Http/Controllers/Settings/VideoThemeController.php:77
* @route '/settings/video/logo'
*/
destroyLogo.url = (options?: RouteQueryOptions) => {
    return destroyLogo.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\VideoThemeController::destroyLogo
* @see app/Http/Controllers/Settings/VideoThemeController.php:77
* @route '/settings/video/logo'
*/
destroyLogo.delete = (options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroyLogo.url(options),
    method: 'delete',
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

const VideoThemeController = { edit, update, reset, destroyLogo, sample }

export default VideoThemeController