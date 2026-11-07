import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../../wayfinder'
/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\AnnotationsController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/AnnotationsController.php:22
* @route '/telemetry-ui/api/v2/annotations'
*/
const AnnotationsController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: AnnotationsController.url(options),
    method: 'get',
})

AnnotationsController.definition = {
    methods: ["get","head"],
    url: '/telemetry-ui/api/v2/annotations',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\AnnotationsController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/AnnotationsController.php:22
* @route '/telemetry-ui/api/v2/annotations'
*/
AnnotationsController.url = (options?: RouteQueryOptions) => {
    return AnnotationsController.definition.url + queryParams(options)
}

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\AnnotationsController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/AnnotationsController.php:22
* @route '/telemetry-ui/api/v2/annotations'
*/
AnnotationsController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: AnnotationsController.url(options),
    method: 'get',
})

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\AnnotationsController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/AnnotationsController.php:22
* @route '/telemetry-ui/api/v2/annotations'
*/
AnnotationsController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: AnnotationsController.url(options),
    method: 'head',
})

export default AnnotationsController