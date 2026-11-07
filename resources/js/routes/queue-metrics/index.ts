import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../wayfinder'
import jobs from './jobs'
import queues from './queues'
import servers from './servers'
import workers from './workers'
import server from './server'
/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\ApiIndexController::__invoke
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/ApiIndexController.php:15
* @route '/queue-metrics'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/queue-metrics',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\ApiIndexController::__invoke
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/ApiIndexController.php:15
* @route '/queue-metrics'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\ApiIndexController::__invoke
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/ApiIndexController.php:15
* @route '/queue-metrics'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\ApiIndexController::__invoke
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/ApiIndexController.php:15
* @route '/queue-metrics'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\HealthCheckController::__invoke
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/HealthCheckController.php:20
* @route '/queue-metrics/health'
*/
export const health = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: health.url(options),
    method: 'get',
})

health.definition = {
    methods: ["get","head"],
    url: '/queue-metrics/health',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\HealthCheckController::__invoke
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/HealthCheckController.php:20
* @route '/queue-metrics/health'
*/
health.url = (options?: RouteQueryOptions) => {
    return health.definition.url + queryParams(options)
}

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\HealthCheckController::__invoke
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/HealthCheckController.php:20
* @route '/queue-metrics/health'
*/
health.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: health.url(options),
    method: 'get',
})

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\HealthCheckController::__invoke
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/HealthCheckController.php:20
* @route '/queue-metrics/health'
*/
health.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: health.url(options),
    method: 'head',
})

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\OverviewController::__invoke
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/OverviewController.php:21
* @route '/queue-metrics/overview'
*/
export const overview = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: overview.url(options),
    method: 'get',
})

overview.definition = {
    methods: ["get","head"],
    url: '/queue-metrics/overview',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\OverviewController::__invoke
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/OverviewController.php:21
* @route '/queue-metrics/overview'
*/
overview.url = (options?: RouteQueryOptions) => {
    return overview.definition.url + queryParams(options)
}

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\OverviewController::__invoke
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/OverviewController.php:21
* @route '/queue-metrics/overview'
*/
overview.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: overview.url(options),
    method: 'get',
})

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\OverviewController::__invoke
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/OverviewController.php:21
* @route '/queue-metrics/overview'
*/
overview.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: overview.url(options),
    method: 'head',
})

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\PrometheusController::__invoke
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/PrometheusController.php:23
* @route '/queue-metrics/prometheus'
*/
export const prometheus = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: prometheus.url(options),
    method: 'get',
})

prometheus.definition = {
    methods: ["get","head"],
    url: '/queue-metrics/prometheus',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\PrometheusController::__invoke
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/PrometheusController.php:23
* @route '/queue-metrics/prometheus'
*/
prometheus.url = (options?: RouteQueryOptions) => {
    return prometheus.definition.url + queryParams(options)
}

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\PrometheusController::__invoke
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/PrometheusController.php:23
* @route '/queue-metrics/prometheus'
*/
prometheus.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: prometheus.url(options),
    method: 'get',
})

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\PrometheusController::__invoke
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/PrometheusController.php:23
* @route '/queue-metrics/prometheus'
*/
prometheus.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: prometheus.url(options),
    method: 'head',
})

const queueMetrics = {
    index: Object.assign(index, index),
    health: Object.assign(health, health),
    overview: Object.assign(overview, overview),
    jobs: Object.assign(jobs, jobs),
    queues: Object.assign(queues, queues),
    servers: Object.assign(servers, servers),
    workers: Object.assign(workers, workers),
    server: Object.assign(server, server),
    prometheus: Object.assign(prometheus, prometheus),
}

export default queueMetrics