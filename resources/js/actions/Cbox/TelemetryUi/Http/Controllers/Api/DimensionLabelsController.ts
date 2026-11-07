import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../../wayfinder'
/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\DimensionLabelsController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/DimensionLabelsController.php:27
* @route '/telemetry-ui/api/v2/dimensions/labels'
*/
const DimensionLabelsController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: DimensionLabelsController.url(options),
    method: 'get',
})

DimensionLabelsController.definition = {
    methods: ["get","head"],
    url: '/telemetry-ui/api/v2/dimensions/labels',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\DimensionLabelsController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/DimensionLabelsController.php:27
* @route '/telemetry-ui/api/v2/dimensions/labels'
*/
DimensionLabelsController.url = (options?: RouteQueryOptions) => {
    return DimensionLabelsController.definition.url + queryParams(options)
}

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\DimensionLabelsController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/DimensionLabelsController.php:27
* @route '/telemetry-ui/api/v2/dimensions/labels'
*/
DimensionLabelsController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: DimensionLabelsController.url(options),
    method: 'get',
})

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\DimensionLabelsController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/DimensionLabelsController.php:27
* @route '/telemetry-ui/api/v2/dimensions/labels'
*/
DimensionLabelsController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: DimensionLabelsController.url(options),
    method: 'head',
})

export default DimensionLabelsController