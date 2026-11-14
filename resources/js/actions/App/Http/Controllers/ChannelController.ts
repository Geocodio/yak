import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\ChannelController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/ChannelController.php:73
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
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/ChannelController.php:73
* @route '/channels'
*/
ChannelController.url = (options?: RouteQueryOptions) => {
    return ChannelController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\ChannelController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/ChannelController.php:73
* @route '/channels'
*/
ChannelController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ChannelController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\ChannelController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/ChannelController.php:73
* @route '/channels'
*/
ChannelController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: ChannelController.url(options),
    method: 'head',
})

export default ChannelController