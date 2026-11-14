import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Threads\ThreadListController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Threads/ThreadListController.php:14
* @route '/threads/list'
*/
const ThreadListController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ThreadListController.url(options),
    method: 'get',
})

ThreadListController.definition = {
    methods: ["get","head"],
    url: '/threads/list',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Threads\ThreadListController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Threads/ThreadListController.php:14
* @route '/threads/list'
*/
ThreadListController.url = (options?: RouteQueryOptions) => {
    return ThreadListController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\ThreadListController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Threads/ThreadListController.php:14
* @route '/threads/list'
*/
ThreadListController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ThreadListController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Threads\ThreadListController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Threads/ThreadListController.php:14
* @route '/threads/list'
*/
ThreadListController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: ThreadListController.url(options),
    method: 'head',
})

export default ThreadListController