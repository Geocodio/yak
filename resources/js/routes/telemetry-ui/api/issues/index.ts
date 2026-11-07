import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../wayfinder'
/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\IssueController::store
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/IssueController.php:39
* @route '/telemetry-ui/api/v2/issues'
*/
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/telemetry-ui/api/v2/issues',
} satisfies RouteDefinition<["post"]>

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\IssueController::store
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/IssueController.php:39
* @route '/telemetry-ui/api/v2/issues'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\IssueController::store
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/IssueController.php:39
* @route '/telemetry-ui/api/v2/issues'
*/
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

const issues = {
    store: Object.assign(store, store),
}

export default issues