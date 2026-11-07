import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../../wayfinder'
/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\FacetsController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/FacetsController.php:29
* @route '/telemetry-ui/api/v2/facets/{signal}'
*/
const FacetsController = (args: { signal: string | number } | [signal: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: FacetsController.url(args, options),
    method: 'get',
})

FacetsController.definition = {
    methods: ["get","head"],
    url: '/telemetry-ui/api/v2/facets/{signal}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\FacetsController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/FacetsController.php:29
* @route '/telemetry-ui/api/v2/facets/{signal}'
*/
FacetsController.url = (args: { signal: string | number } | [signal: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { signal: args }
    }

    if (Array.isArray(args)) {
        args = {
            signal: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        signal: args.signal,
    }

    return FacetsController.definition.url
            .replace('{signal}', parsedArgs.signal.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\FacetsController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/FacetsController.php:29
* @route '/telemetry-ui/api/v2/facets/{signal}'
*/
FacetsController.get = (args: { signal: string | number } | [signal: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: FacetsController.url(args, options),
    method: 'get',
})

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\FacetsController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/FacetsController.php:29
* @route '/telemetry-ui/api/v2/facets/{signal}'
*/
FacetsController.head = (args: { signal: string | number } | [signal: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: FacetsController.url(args, options),
    method: 'head',
})

export default FacetsController