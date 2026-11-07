import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../../wayfinder'
/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\ViewStateController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/ViewStateController.php:21
* @route '/telemetry-ui/api/v2/view-state'
*/
const ViewStateController = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: ViewStateController.url(options),
    method: 'post',
})

ViewStateController.definition = {
    methods: ["post"],
    url: '/telemetry-ui/api/v2/view-state',
} satisfies RouteDefinition<["post"]>

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\ViewStateController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/ViewStateController.php:21
* @route '/telemetry-ui/api/v2/view-state'
*/
ViewStateController.url = (options?: RouteQueryOptions) => {
    return ViewStateController.definition.url + queryParams(options)
}

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\ViewStateController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/ViewStateController.php:21
* @route '/telemetry-ui/api/v2/view-state'
*/
ViewStateController.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: ViewStateController.url(options),
    method: 'post',
})

export default ViewStateController