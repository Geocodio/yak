import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Internal\SshListenersController::__invoke
* @see app/Http/Controllers/Internal/SshListenersController.php:14
* @route '/internal/ssh/listeners'
*/
const SshListenersController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: SshListenersController.url(options),
    method: 'get',
})

SshListenersController.definition = {
    methods: ["get","head"],
    url: '/internal/ssh/listeners',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Internal\SshListenersController::__invoke
* @see app/Http/Controllers/Internal/SshListenersController.php:14
* @route '/internal/ssh/listeners'
*/
SshListenersController.url = (options?: RouteQueryOptions) => {
    return SshListenersController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Internal\SshListenersController::__invoke
* @see app/Http/Controllers/Internal/SshListenersController.php:14
* @route '/internal/ssh/listeners'
*/
SshListenersController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: SshListenersController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Internal\SshListenersController::__invoke
* @see app/Http/Controllers/Internal/SshListenersController.php:14
* @route '/internal/ssh/listeners'
*/
SshListenersController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: SshListenersController.url(options),
    method: 'head',
})

export default SshListenersController