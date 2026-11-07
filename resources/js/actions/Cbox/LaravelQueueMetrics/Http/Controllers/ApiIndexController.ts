import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\ApiIndexController::__invoke
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/ApiIndexController.php:15
* @route '/queue-metrics'
*/
const ApiIndexController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ApiIndexController.url(options),
    method: 'get',
})

ApiIndexController.definition = {
    methods: ["get","head"],
    url: '/queue-metrics',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\ApiIndexController::__invoke
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/ApiIndexController.php:15
* @route '/queue-metrics'
*/
ApiIndexController.url = (options?: RouteQueryOptions) => {
    return ApiIndexController.definition.url + queryParams(options)
}

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\ApiIndexController::__invoke
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/ApiIndexController.php:15
* @route '/queue-metrics'
*/
ApiIndexController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ApiIndexController.url(options),
    method: 'get',
})

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\ApiIndexController::__invoke
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/ApiIndexController.php:15
* @route '/queue-metrics'
*/
ApiIndexController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: ApiIndexController.url(options),
    method: 'head',
})

export default ApiIndexController