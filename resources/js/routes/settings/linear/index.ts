import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../wayfinder'
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

const linear = {
    update: Object.assign(update, update),
    disconnect: Object.assign(disconnect, disconnect),
}

export default linear