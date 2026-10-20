import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\GitHubAccountController::connect
* @see app/Http/Controllers/Settings/GitHubAccountController.php:21
* @route '/settings/github/connect'
*/
export const connect = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: connect.url(options),
    method: 'get',
})

connect.definition = {
    methods: ["get","head"],
    url: '/settings/github/connect',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Settings\GitHubAccountController::connect
* @see app/Http/Controllers/Settings/GitHubAccountController.php:21
* @route '/settings/github/connect'
*/
connect.url = (options?: RouteQueryOptions) => {
    return connect.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\GitHubAccountController::connect
* @see app/Http/Controllers/Settings/GitHubAccountController.php:21
* @route '/settings/github/connect'
*/
connect.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: connect.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\GitHubAccountController::connect
* @see app/Http/Controllers/Settings/GitHubAccountController.php:21
* @route '/settings/github/connect'
*/
connect.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: connect.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Settings\GitHubAccountController::callback
* @see app/Http/Controllers/Settings/GitHubAccountController.php:34
* @route '/settings/github/callback'
*/
export const callback = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: callback.url(options),
    method: 'get',
})

callback.definition = {
    methods: ["get","head"],
    url: '/settings/github/callback',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Settings\GitHubAccountController::callback
* @see app/Http/Controllers/Settings/GitHubAccountController.php:34
* @route '/settings/github/callback'
*/
callback.url = (options?: RouteQueryOptions) => {
    return callback.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\GitHubAccountController::callback
* @see app/Http/Controllers/Settings/GitHubAccountController.php:34
* @route '/settings/github/callback'
*/
callback.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: callback.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\GitHubAccountController::callback
* @see app/Http/Controllers/Settings/GitHubAccountController.php:34
* @route '/settings/github/callback'
*/
callback.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: callback.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Settings\GitHubAccountController::disconnect
* @see app/Http/Controllers/Settings/GitHubAccountController.php:63
* @route '/settings/github'
*/
export const disconnect = (options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: disconnect.url(options),
    method: 'delete',
})

disconnect.definition = {
    methods: ["delete"],
    url: '/settings/github',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\Settings\GitHubAccountController::disconnect
* @see app/Http/Controllers/Settings/GitHubAccountController.php:63
* @route '/settings/github'
*/
disconnect.url = (options?: RouteQueryOptions) => {
    return disconnect.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\GitHubAccountController::disconnect
* @see app/Http/Controllers/Settings/GitHubAccountController.php:63
* @route '/settings/github'
*/
disconnect.delete = (options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: disconnect.url(options),
    method: 'delete',
})

const github = {
    connect: Object.assign(connect, connect),
    callback: Object.assign(callback, callback),
    disconnect: Object.assign(disconnect, disconnect),
}

export default github