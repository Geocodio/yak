import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\VideoThemeController::destroy
* @see app/Http/Controllers/Settings/VideoThemeController.php:77
* @route '/settings/video/logo'
*/
export const destroy = (options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/settings/video/logo',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\Settings\VideoThemeController::destroy
* @see app/Http/Controllers/Settings/VideoThemeController.php:77
* @route '/settings/video/logo'
*/
destroy.url = (options?: RouteQueryOptions) => {
    return destroy.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\VideoThemeController::destroy
* @see app/Http/Controllers/Settings/VideoThemeController.php:77
* @route '/settings/video/logo'
*/
destroy.delete = (options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(options),
    method: 'delete',
})

const logo = {
    destroy: Object.assign(destroy, destroy),
}

export default logo