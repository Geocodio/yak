import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Deployments\AuthBounceController::__invoke
* @see app/Http/Controllers/Deployments/AuthBounceController.php:22
* @route '/deployments/auth-bounce'
*/
const AuthBounceController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: AuthBounceController.url(options),
    method: 'get',
})

AuthBounceController.definition = {
    methods: ["get","head"],
    url: '/deployments/auth-bounce',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Deployments\AuthBounceController::__invoke
* @see app/Http/Controllers/Deployments/AuthBounceController.php:22
* @route '/deployments/auth-bounce'
*/
AuthBounceController.url = (options?: RouteQueryOptions) => {
    return AuthBounceController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Deployments\AuthBounceController::__invoke
* @see app/Http/Controllers/Deployments/AuthBounceController.php:22
* @route '/deployments/auth-bounce'
*/
AuthBounceController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: AuthBounceController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Deployments\AuthBounceController::__invoke
* @see app/Http/Controllers/Deployments/AuthBounceController.php:22
* @route '/deployments/auth-bounce'
*/
AuthBounceController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: AuthBounceController.url(options),
    method: 'head',
})

export default AuthBounceController