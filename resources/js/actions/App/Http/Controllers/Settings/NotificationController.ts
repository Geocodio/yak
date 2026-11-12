import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\NotificationController::edit
* @see app/Http/Controllers/Settings/NotificationController.php:16
* @route '/settings/notifications'
*/
export const edit = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: edit.url(options),
    method: 'get',
})

edit.definition = {
    methods: ["get","head"],
    url: '/settings/notifications',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Settings\NotificationController::edit
* @see app/Http/Controllers/Settings/NotificationController.php:16
* @route '/settings/notifications'
*/
edit.url = (options?: RouteQueryOptions) => {
    return edit.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\NotificationController::edit
* @see app/Http/Controllers/Settings/NotificationController.php:16
* @route '/settings/notifications'
*/
edit.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: edit.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\NotificationController::edit
* @see app/Http/Controllers/Settings/NotificationController.php:16
* @route '/settings/notifications'
*/
edit.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: edit.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Settings\NotificationController::updatePreferences
* @see app/Http/Controllers/Settings/NotificationController.php:24
* @route '/settings/notifications'
*/
export const updatePreferences = (options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: updatePreferences.url(options),
    method: 'put',
})

updatePreferences.definition = {
    methods: ["put"],
    url: '/settings/notifications',
} satisfies RouteDefinition<["put"]>

/**
* @see \App\Http\Controllers\Settings\NotificationController::updatePreferences
* @see app/Http/Controllers/Settings/NotificationController.php:24
* @route '/settings/notifications'
*/
updatePreferences.url = (options?: RouteQueryOptions) => {
    return updatePreferences.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\NotificationController::updatePreferences
* @see app/Http/Controllers/Settings/NotificationController.php:24
* @route '/settings/notifications'
*/
updatePreferences.put = (options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: updatePreferences.url(options),
    method: 'put',
})

const NotificationController = { edit, updatePreferences }

export default NotificationController