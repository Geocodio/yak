import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../../wayfinder'
/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\BootstrapController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/BootstrapController.php:40
* @route '/telemetry-ui/api/v2/bootstrap'
*/
const BootstrapController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: BootstrapController.url(options),
    method: 'get',
})

BootstrapController.definition = {
    methods: ["get","head"],
    url: '/telemetry-ui/api/v2/bootstrap',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\BootstrapController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/BootstrapController.php:40
* @route '/telemetry-ui/api/v2/bootstrap'
*/
BootstrapController.url = (options?: RouteQueryOptions) => {
    return BootstrapController.definition.url + queryParams(options)
}

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\BootstrapController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/BootstrapController.php:40
* @route '/telemetry-ui/api/v2/bootstrap'
*/
BootstrapController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: BootstrapController.url(options),
    method: 'get',
})

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\BootstrapController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/BootstrapController.php:40
* @route '/telemetry-ui/api/v2/bootstrap'
*/
BootstrapController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: BootstrapController.url(options),
    method: 'head',
})

export default BootstrapController