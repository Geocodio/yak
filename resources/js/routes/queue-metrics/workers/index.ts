import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../wayfinder'
import status14a815 from './status'
/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\AllWorkersController::all
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/AllWorkersController.php:20
* @route '/queue-metrics/workers/all'
*/
export const all = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: all.url(options),
    method: 'get',
})

all.definition = {
    methods: ["get","head"],
    url: '/queue-metrics/workers/all',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\AllWorkersController::all
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/AllWorkersController.php:20
* @route '/queue-metrics/workers/all'
*/
all.url = (options?: RouteQueryOptions) => {
    return all.definition.url + queryParams(options)
}

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\AllWorkersController::all
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/AllWorkersController.php:20
* @route '/queue-metrics/workers/all'
*/
all.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: all.url(options),
    method: 'get',
})

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\AllWorkersController::all
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/AllWorkersController.php:20
* @route '/queue-metrics/workers/all'
*/
all.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: all.url(options),
    method: 'head',
})

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

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\WorkerStatusController::status
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/WorkerStatusController.php:23
* @route '/queue-metrics/workers/status'
*/
export const status = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: status.url(options),
    method: 'get',
})

status.definition = {
    methods: ["get","head"],
    url: '/queue-metrics/workers/status',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\WorkerStatusController::status
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/WorkerStatusController.php:23
* @route '/queue-metrics/workers/status'
*/
status.url = (options?: RouteQueryOptions) => {
    return status.definition.url + queryParams(options)
}

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\WorkerStatusController::status
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/WorkerStatusController.php:23
* @route '/queue-metrics/workers/status'
*/
status.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: status.url(options),
    method: 'get',
})

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\WorkerStatusController::status
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/WorkerStatusController.php:23
* @route '/queue-metrics/workers/status'
*/
status.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: status.url(options),
    method: 'head',
})

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\WorkerStatusController::detectStale
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/WorkerStatusController.php:91
* @route '/queue-metrics/workers/detect-stale'
*/
export const detectStale = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: detectStale.url(options),
    method: 'post',
})

detectStale.definition = {
    methods: ["post"],
    url: '/queue-metrics/workers/detect-stale',
} satisfies RouteDefinition<["post"]>

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\WorkerStatusController::detectStale
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/WorkerStatusController.php:91
* @route '/queue-metrics/workers/detect-stale'
*/
detectStale.url = (options?: RouteQueryOptions) => {
    return detectStale.definition.url + queryParams(options)
}

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\WorkerStatusController::detectStale
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/WorkerStatusController.php:91
* @route '/queue-metrics/workers/detect-stale'
*/
detectStale.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: detectStale.url(options),
    method: 'post',
})

const workers = {
    all: Object.assign(all, all),
    index: Object.assign(index, index),
    status: Object.assign(status, status14a815),
    detectStale: Object.assign(detectStale, detectStale),
}

export default workers