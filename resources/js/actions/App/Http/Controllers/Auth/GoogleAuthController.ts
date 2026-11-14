import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Auth\GoogleAuthController::redirect
* @see app/Http/Controllers/Auth/GoogleAuthController.php:13
* @route '/auth/google'
*/
export const redirect = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: redirect.url(options),
    method: 'get',
})

redirect.definition = {
    methods: ["get","head"],
    url: '/auth/google',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Auth\GoogleAuthController::redirect
* @see app/Http/Controllers/Auth/GoogleAuthController.php:13
* @route '/auth/google'
*/
redirect.url = (options?: RouteQueryOptions) => {
    return redirect.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Auth\GoogleAuthController::redirect
* @see app/Http/Controllers/Auth/GoogleAuthController.php:13
* @route '/auth/google'
*/
redirect.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: redirect.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Auth\GoogleAuthController::redirect
* @see app/Http/Controllers/Auth/GoogleAuthController.php:13
* @route '/auth/google'
*/
redirect.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: redirect.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Auth\GoogleAuthController::callback
* @see app/Http/Controllers/Auth/GoogleAuthController.php:19
* @route '/auth/google/callback'
*/
export const callback = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: callback.url(options),
    method: 'get',
})

callback.definition = {
    methods: ["get","head"],
    url: '/auth/google/callback',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Auth\GoogleAuthController::callback
* @see app/Http/Controllers/Auth/GoogleAuthController.php:19
* @route '/auth/google/callback'
*/
callback.url = (options?: RouteQueryOptions) => {
    return callback.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Auth\GoogleAuthController::callback
* @see app/Http/Controllers/Auth/GoogleAuthController.php:19
* @route '/auth/google/callback'
*/
callback.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: callback.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Auth\GoogleAuthController::callback
* @see app/Http/Controllers/Auth/GoogleAuthController.php:19
* @route '/auth/google/callback'
*/
callback.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: callback.url(options),
    method: 'head',
})

const GoogleAuthController = { redirect, callback }

export default GoogleAuthController