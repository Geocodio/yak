import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../wayfinder'
import google723582 from './google'
import linear from './linear'
/**
* @see \App\Http\Controllers\Auth\GoogleAuthController::google
* @see app/Http/Controllers/Auth/GoogleAuthController.php:13
* @route '/auth/google'
*/
export const google = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: google.url(options),
    method: 'get',
})

google.definition = {
    methods: ["get","head"],
    url: '/auth/google',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Auth\GoogleAuthController::google
* @see app/Http/Controllers/Auth/GoogleAuthController.php:13
* @route '/auth/google'
*/
google.url = (options?: RouteQueryOptions) => {
    return google.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Auth\GoogleAuthController::google
* @see app/Http/Controllers/Auth/GoogleAuthController.php:13
* @route '/auth/google'
*/
google.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: google.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Auth\GoogleAuthController::google
* @see app/Http/Controllers/Auth/GoogleAuthController.php:13
* @route '/auth/google'
*/
google.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: google.url(options),
    method: 'head',
})

const auth = {
    google: Object.assign(google, google723582),
    linear: Object.assign(linear, linear),
}

export default auth