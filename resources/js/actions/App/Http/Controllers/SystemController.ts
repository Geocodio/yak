import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\SystemController::__invoke
* @see app/Http/Controllers/SystemController.php:16
* @route '/system'
*/
const SystemController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: SystemController.url(options),
    method: 'get',
})

SystemController.definition = {
    methods: ["get","head"],
    url: '/system',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\SystemController::__invoke
* @see app/Http/Controllers/SystemController.php:16
* @route '/system'
*/
SystemController.url = (options?: RouteQueryOptions) => {
    return SystemController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\SystemController::__invoke
* @see app/Http/Controllers/SystemController.php:16
* @route '/system'
*/
SystemController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: SystemController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\SystemController::__invoke
* @see app/Http/Controllers/SystemController.php:16
* @route '/system'
*/
SystemController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: SystemController.url(options),
    method: 'head',
})

export default SystemController