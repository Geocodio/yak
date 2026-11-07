import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\ErrorGroupController::markdown
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/ErrorGroupController.php:66
* @route '/telemetry-ui/api/v2/errors/{group}/markdown'
*/
export const markdown = (args: { group: string | number } | [group: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: markdown.url(args, options),
    method: 'get',
})

markdown.definition = {
    methods: ["get","head"],
    url: '/telemetry-ui/api/v2/errors/{group}/markdown',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\ErrorGroupController::markdown
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/ErrorGroupController.php:66
* @route '/telemetry-ui/api/v2/errors/{group}/markdown'
*/
markdown.url = (args: { group: string | number } | [group: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { group: args }
    }

    if (Array.isArray(args)) {
        args = {
            group: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        group: args.group,
    }

    return markdown.definition.url
            .replace('{group}', parsedArgs.group.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\ErrorGroupController::markdown
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/ErrorGroupController.php:66
* @route '/telemetry-ui/api/v2/errors/{group}/markdown'
*/
markdown.get = (args: { group: string | number } | [group: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: markdown.url(args, options),
    method: 'get',
})

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\ErrorGroupController::markdown
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/ErrorGroupController.php:66
* @route '/telemetry-ui/api/v2/errors/{group}/markdown'
*/
markdown.head = (args: { group: string | number } | [group: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: markdown.url(args, options),
    method: 'head',
})

