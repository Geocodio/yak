import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../wayfinder'
/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\ServerMetricsController::index
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/ServerMetricsController.php:22
* @route '/queue-metrics/server'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/queue-metrics/server',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\ServerMetricsController::index
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/ServerMetricsController.php:22
* @route '/queue-metrics/server'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\ServerMetricsController::index
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/ServerMetricsController.php:22
* @route '/queue-metrics/server'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\ServerMetricsController::index
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/ServerMetricsController.php:22
* @route '/queue-metrics/server'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\ServerMetricsController::health
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/ServerMetricsController.php:34
* @route '/queue-metrics/server/health'
*/
export const health = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: health.url(options),
    method: 'get',
})

health.definition = {
    methods: ["get","head"],
    url: '/queue-metrics/server/health',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\ServerMetricsController::health
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/ServerMetricsController.php:34
* @route '/queue-metrics/server/health'
*/
health.url = (options?: RouteQueryOptions) => {
    return health.definition.url + queryParams(options)
}

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\ServerMetricsController::health
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/ServerMetricsController.php:34
* @route '/queue-metrics/server/health'
*/
health.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: health.url(options),
    method: 'get',
})

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\ServerMetricsController::health
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/ServerMetricsController.php:34
* @route '/queue-metrics/server/health'
*/
health.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: health.url(options),
    method: 'head',
})

const server = {
    index: Object.assign(index, index),
    health: Object.assign(health, health),
}

export default server