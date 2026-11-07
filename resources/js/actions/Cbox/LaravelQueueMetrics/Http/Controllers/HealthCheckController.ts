import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\HealthCheckController::__invoke
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/HealthCheckController.php:20
* @route '/queue-metrics/health'
*/
const HealthCheckController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: HealthCheckController.url(options),
    method: 'get',
})

HealthCheckController.definition = {
    methods: ["get","head"],
    url: '/queue-metrics/health',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\HealthCheckController::__invoke
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/HealthCheckController.php:20
* @route '/queue-metrics/health'
*/
HealthCheckController.url = (options?: RouteQueryOptions) => {
    return HealthCheckController.definition.url + queryParams(options)
}

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\HealthCheckController::__invoke
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/HealthCheckController.php:20
* @route '/queue-metrics/health'
*/
HealthCheckController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: HealthCheckController.url(options),
    method: 'get',
})

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\HealthCheckController::__invoke
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/HealthCheckController.php:20
* @route '/queue-metrics/health'
*/
HealthCheckController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: HealthCheckController.url(options),
    method: 'head',
})

export default HealthCheckController