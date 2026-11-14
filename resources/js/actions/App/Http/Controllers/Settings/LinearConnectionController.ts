import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\LinearConnectionController::edit
* @see app/Http/Controllers/Settings/LinearConnectionController.php:16
* @route '/settings/linear'
*/
export const edit = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: edit.url(options),
    method: 'get',
})

edit.definition = {
    methods: ["get","head"],
    url: '/settings/linear',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Settings\LinearConnectionController::edit
* @see app/Http/Controllers/Settings/LinearConnectionController.php:16
* @route '/settings/linear'
*/
edit.url = (options?: RouteQueryOptions) => {
    return edit.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\LinearConnectionController::edit
* @see app/Http/Controllers/Settings/LinearConnectionController.php:16
* @route '/settings/linear'
*/
edit.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: edit.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\LinearConnectionController::edit
* @see app/Http/Controllers/Settings/LinearConnectionController.php:16
* @route '/settings/linear'
*/
edit.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: edit.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Settings\LinearConnectionController::update
* @see app/Http/Controllers/Settings/LinearConnectionController.php:23
* @route '/settings/linear'
*/
export const update = (options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(options),
    method: 'patch',
})

update.definition = {
    methods: ["patch"],
    url: '/settings/linear',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\Settings\LinearConnectionController::update
* @see app/Http/Controllers/Settings/LinearConnectionController.php:23
* @route '/settings/linear'
*/
update.url = (options?: RouteQueryOptions) => {
    return update.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\LinearConnectionController::update
* @see app/Http/Controllers/Settings/LinearConnectionController.php:23
* @route '/settings/linear'
*/
update.patch = (options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(options),
    method: 'patch',
})

/**
* @see \App\Http\Controllers\Settings\LinearConnectionController::disconnect
* @see app/Http/Controllers/Settings/LinearConnectionController.php:36
* @route '/settings/linear'
*/
export const disconnect = (options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: disconnect.url(options),
    method: 'delete',
})

disconnect.definition = {
    methods: ["delete"],
    url: '/settings/linear',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\Settings\LinearConnectionController::disconnect
* @see app/Http/Controllers/Settings/LinearConnectionController.php:36
* @route '/settings/linear'
*/
disconnect.url = (options?: RouteQueryOptions) => {
    return disconnect.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\LinearConnectionController::disconnect
* @see app/Http/Controllers/Settings/LinearConnectionController.php:36
* @route '/settings/linear'
*/
disconnect.delete = (options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: disconnect.url(options),
    method: 'delete',
})

const LinearConnectionController = { edit, update, disconnect }

export default LinearConnectionController