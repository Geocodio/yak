import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\AllJobsController::index
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/AllJobsController.php:20
* @route '/queue-metrics/jobs'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/queue-metrics/jobs',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\AllJobsController::index
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/AllJobsController.php:20
* @route '/queue-metrics/jobs'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\AllJobsController::index
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/AllJobsController.php:20
* @route '/queue-metrics/jobs'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\AllJobsController::index
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/AllJobsController.php:20
* @route '/queue-metrics/jobs'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

const AllJobsController = { index }

export default AllJobsController