import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../../wayfinder'
/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\PanelController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/PanelController.php:24
* @route '/telemetry-ui/api/v2/panels/{panel}'
*/
const PanelController = (args: { panel: string | number } | [panel: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: PanelController.url(args, options),
    method: 'get',
})

PanelController.definition = {
    methods: ["get","head"],
    url: '/telemetry-ui/api/v2/panels/{panel}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\PanelController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/PanelController.php:24
* @route '/telemetry-ui/api/v2/panels/{panel}'
*/
PanelController.url = (args: { panel: string | number } | [panel: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { panel: args }
    }

    if (Array.isArray(args)) {
        args = {
            panel: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        panel: args.panel,
    }

    return PanelController.definition.url
            .replace('{panel}', parsedArgs.panel.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\PanelController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/PanelController.php:24
* @route '/telemetry-ui/api/v2/panels/{panel}'
*/
PanelController.get = (args: { panel: string | number } | [panel: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: PanelController.url(args, options),
    method: 'get',
})

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\PanelController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/PanelController.php:24
* @route '/telemetry-ui/api/v2/panels/{panel}'
*/
PanelController.head = (args: { panel: string | number } | [panel: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: PanelController.url(args, options),
    method: 'head',
})

export default PanelController