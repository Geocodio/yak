import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Cli\MeController::__invoke
* @see app/Http/Controllers/Cli/MeController.php:14
* @route '/api/cli/v1/me'
*/
const MeController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: MeController.url(options),
    method: 'get',
})

MeController.definition = {
    methods: ["get","head"],
    url: '/api/cli/v1/me',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Cli\MeController::__invoke
* @see app/Http/Controllers/Cli/MeController.php:14
* @route '/api/cli/v1/me'
*/
MeController.url = (options?: RouteQueryOptions) => {
    return MeController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Cli\MeController::__invoke
* @see app/Http/Controllers/Cli/MeController.php:14
* @route '/api/cli/v1/me'
*/
MeController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: MeController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Cli\MeController::__invoke
* @see app/Http/Controllers/Cli/MeController.php:14
* @route '/api/cli/v1/me'
*/
MeController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: MeController.url(options),
    method: 'head',
})

export default MeController