import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\OverviewController::__invoke
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/OverviewController.php:21
* @route '/queue-metrics/overview'
*/
const OverviewController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: OverviewController.url(options),
    method: 'get',
})

OverviewController.definition = {
    methods: ["get","head"],
    url: '/queue-metrics/overview',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\OverviewController::__invoke
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/OverviewController.php:21
* @route '/queue-metrics/overview'
*/
OverviewController.url = (options?: RouteQueryOptions) => {
    return OverviewController.definition.url + queryParams(options)
}

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\OverviewController::__invoke
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/OverviewController.php:21
* @route '/queue-metrics/overview'
*/
OverviewController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: OverviewController.url(options),
    method: 'get',
})

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\OverviewController::__invoke
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/OverviewController.php:21
* @route '/queue-metrics/overview'
*/
OverviewController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: OverviewController.url(options),
    method: 'head',
})

export default OverviewController