import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Threads\StoreThreadController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Threads/StoreThreadController.php:16
* @route '/threads'
*/
const StoreThreadController = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: StoreThreadController.url(options),
    method: 'post',
})

StoreThreadController.definition = {
    methods: ["post"],
    url: '/threads',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Threads\StoreThreadController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Threads/StoreThreadController.php:16
* @route '/threads'
*/
StoreThreadController.url = (options?: RouteQueryOptions) => {
    return StoreThreadController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\StoreThreadController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Threads/StoreThreadController.php:16
* @route '/threads'
*/
StoreThreadController.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: StoreThreadController.url(options),
    method: 'post',
})

export default StoreThreadController