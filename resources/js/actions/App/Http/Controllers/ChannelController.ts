import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\ChannelController::__invoke
* @see app/Http/Controllers/ChannelController.php:70
* @route '/channels'
*/
const ChannelController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ChannelController.url(options),
    method: 'get',
})

ChannelController.definition = {
    methods: ["get","head"],
    url: '/channels',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\ChannelController::__invoke
* @see app/Http/Controllers/ChannelController.php:70
* @route '/channels'
*/
ChannelController.url = (options?: RouteQueryOptions) => {
    return ChannelController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\ChannelController::__invoke
* @see app/Http/Controllers/ChannelController.php:70
* @route '/channels'
*/
ChannelController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ChannelController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\ChannelController::__invoke
* @see app/Http/Controllers/ChannelController.php:70
* @route '/channels'
*/
ChannelController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: ChannelController.url(options),
    method: 'head',
})

export default ChannelController