import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../wayfinder'
import device from './device'
import repos from './repos'
/**
* @see \App\Http\Controllers\Cli\MeController::__invoke
* @see app/Http/Controllers/Cli/MeController.php:14
* @route '/api/cli/v1/me'
*/
export const me = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: me.url(options),
    method: 'get',
})

me.definition = {
    methods: ["get","head"],
    url: '/api/cli/v1/me',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Cli\MeController::__invoke
* @see app/Http/Controllers/Cli/MeController.php:14
* @route '/api/cli/v1/me'
*/
me.url = (options?: RouteQueryOptions) => {
    return me.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Cli\MeController::__invoke
* @see app/Http/Controllers/Cli/MeController.php:14
* @route '/api/cli/v1/me'
*/
me.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: me.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Cli\MeController::__invoke
* @see app/Http/Controllers/Cli/MeController.php:14
* @route '/api/cli/v1/me'
*/
me.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: me.url(options),
    method: 'head',
})

const cli = {
    device: Object.assign(device, device),
    me: Object.assign(me, me),
    repos: Object.assign(repos, repos),
}

export default cli