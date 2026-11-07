import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\WorkerController::index
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/WorkerController.php:21
* @route '/queue-metrics/workers'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/queue-metrics/workers',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\WorkerController::index
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/WorkerController.php:21
* @route '/queue-metrics/workers'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\WorkerController::index
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/WorkerController.php:21
* @route '/queue-metrics/workers'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\WorkerController::index
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/WorkerController.php:21
* @route '/queue-metrics/workers'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

const WorkerController = { index }

export default WorkerController