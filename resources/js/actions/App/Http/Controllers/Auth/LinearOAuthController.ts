import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Auth\LinearOAuthController::redirect
* @see app/Http/Controllers/Auth/LinearOAuthController.php:16
* @route '/auth/linear'
*/
export const redirect = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: redirect.url(options),
    method: 'get',
})

redirect.definition = {
    methods: ["get","head"],
    url: '/auth/linear',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Auth\LinearOAuthController::redirect
* @see app/Http/Controllers/Auth/LinearOAuthController.php:16
* @route '/auth/linear'
*/
redirect.url = (options?: RouteQueryOptions) => {
    return redirect.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Auth\LinearOAuthController::redirect
* @see app/Http/Controllers/Auth/LinearOAuthController.php:16
* @route '/auth/linear'
*/
redirect.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: redirect.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Auth\LinearOAuthController::redirect
* @see app/Http/Controllers/Auth/LinearOAuthController.php:16
* @route '/auth/linear'
*/
redirect.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: redirect.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Auth\LinearOAuthController::callback
* @see app/Http/Controllers/Auth/LinearOAuthController.php:28
* @route '/auth/linear/callback'
*/
export const callback = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: callback.url(options),
    method: 'get',
})

callback.definition = {
    methods: ["get","head"],
    url: '/auth/linear/callback',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Auth\LinearOAuthController::callback
* @see app/Http/Controllers/Auth/LinearOAuthController.php:28
* @route '/auth/linear/callback'
*/
callback.url = (options?: RouteQueryOptions) => {
    return callback.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Auth\LinearOAuthController::callback
* @see app/Http/Controllers/Auth/LinearOAuthController.php:28
* @route '/auth/linear/callback'
*/
callback.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: callback.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Auth\LinearOAuthController::callback
* @see app/Http/Controllers/Auth/LinearOAuthController.php:28
* @route '/auth/linear/callback'
*/
callback.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: callback.url(options),
    method: 'head',
})

const LinearOAuthController = { redirect, callback }

export default LinearOAuthController