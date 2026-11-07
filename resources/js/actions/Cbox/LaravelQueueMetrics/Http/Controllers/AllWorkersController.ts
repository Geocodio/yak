import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\AllWorkersController::index
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/AllWorkersController.php:20
* @route '/queue-metrics/workers/all'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/queue-metrics/workers/all',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\AllWorkersController::index
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/AllWorkersController.php:20
* @route '/queue-metrics/workers/all'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\AllWorkersController::index
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/AllWorkersController.php:20
* @route '/queue-metrics/workers/all'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\AllWorkersController::index
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/AllWorkersController.php:20
* @route '/queue-metrics/workers/all'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

const AllWorkersController = { index }

export default AllWorkersController