import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\PrometheusController::__invoke
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/PrometheusController.php:23
* @route '/queue-metrics/prometheus'
*/
const PrometheusController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: PrometheusController.url(options),
    method: 'get',
})

PrometheusController.definition = {
    methods: ["get","head"],
    url: '/queue-metrics/prometheus',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\PrometheusController::__invoke
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/PrometheusController.php:23
* @route '/queue-metrics/prometheus'
*/
PrometheusController.url = (options?: RouteQueryOptions) => {
    return PrometheusController.definition.url + queryParams(options)
}

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\PrometheusController::__invoke
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/PrometheusController.php:23
* @route '/queue-metrics/prometheus'
*/
PrometheusController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: PrometheusController.url(options),
    method: 'get',
})

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\PrometheusController::__invoke
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/PrometheusController.php:23
* @route '/queue-metrics/prometheus'
*/
PrometheusController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: PrometheusController.url(options),
    method: 'head',
})

export default PrometheusController