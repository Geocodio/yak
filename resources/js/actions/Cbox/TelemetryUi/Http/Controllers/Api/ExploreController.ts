import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../../wayfinder'
/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\ExploreController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/ExploreController.php:34
* @route '/telemetry-ui/api/v2/explore/{signal}'
*/
const ExploreController = (args: { signal: string | number } | [signal: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ExploreController.url(args, options),
    method: 'get',
})

ExploreController.definition = {
    methods: ["get","head"],
    url: '/telemetry-ui/api/v2/explore/{signal}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\ExploreController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/ExploreController.php:34
* @route '/telemetry-ui/api/v2/explore/{signal}'
*/
ExploreController.url = (args: { signal: string | number } | [signal: string | number ] | string | number, options?: RouteQueryOptions) => {
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

    return ExploreController.definition.url
            .replace('{signal}', parsedArgs.signal.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\ExploreController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/ExploreController.php:34
* @route '/telemetry-ui/api/v2/explore/{signal}'
*/
ExploreController.get = (args: { signal: string | number } | [signal: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ExploreController.url(args, options),
    method: 'get',
})

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\ExploreController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/ExploreController.php:34
* @route '/telemetry-ui/api/v2/explore/{signal}'
*/
ExploreController.head = (args: { signal: string | number } | [signal: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: ExploreController.url(args, options),
    method: 'head',
})

export default ExploreController