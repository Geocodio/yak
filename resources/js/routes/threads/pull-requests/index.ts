import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Threads\PullRequests\PullRequestsShowController::__invoke
* @see app/Http/Controllers/Threads/PullRequests/PullRequestsShowController.php:17
* @route '/threads/{thread}/pull-requests'
*/
export const show = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/threads/{thread}/pull-requests',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Threads\PullRequests\PullRequestsShowController::__invoke
* @see app/Http/Controllers/Threads/PullRequests/PullRequestsShowController.php:17
* @route '/threads/{thread}/pull-requests'
*/
show.url = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { thread: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { thread: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            thread: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        thread: typeof args.thread === 'object'
        ? args.thread.id
        : args.thread,
    }

    return show.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\PullRequests\PullRequestsShowController::__invoke
* @see app/Http/Controllers/Threads/PullRequests/PullRequestsShowController.php:17
* @route '/threads/{thread}/pull-requests'
*/
show.get = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Threads\PullRequests\PullRequestsShowController::__invoke
* @see app/Http/Controllers/Threads/PullRequests/PullRequestsShowController.php:17
* @route '/threads/{thread}/pull-requests'
*/
show.head = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Threads\PullRequests\MergeOrderController::__invoke
* @see app/Http/Controllers/Threads/PullRequests/MergeOrderController.php:17
* @route '/threads/{thread}/pull-requests/order'
*/
export const order = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: order.url(args, options),
    method: 'put',
})

order.definition = {
    methods: ["put"],
    url: '/threads/{thread}/pull-requests/order',
} satisfies RouteDefinition<["put"]>

/**
* @see \App\Http\Controllers\Threads\PullRequests\MergeOrderController::__invoke
* @see app/Http/Controllers/Threads/PullRequests/MergeOrderController.php:17
* @route '/threads/{thread}/pull-requests/order'
*/
order.url = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { thread: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { thread: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            thread: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        thread: typeof args.thread === 'object'
        ? args.thread.id
        : args.thread,
    }

    return order.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\PullRequests\MergeOrderController::__invoke
* @see app/Http/Controllers/Threads/PullRequests/MergeOrderController.php:17
* @route '/threads/{thread}/pull-requests/order'
*/
order.put = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: order.url(args, options),
    method: 'put',
})

/**
* @see \App\Http\Controllers\Threads\PullRequests\AskToFixController::__invoke
* @see app/Http/Controllers/Threads/PullRequests/AskToFixController.php:19
* @route '/threads/{thread}/pull-requests/{repository}/fix'
*/
export const fix = (args: { thread: number | { id: number }, repository: string | number } | [thread: number | { id: number }, repository: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: fix.url(args, options),
    method: 'post',
})

fix.definition = {
    methods: ["post"],
    url: '/threads/{thread}/pull-requests/{repository}/fix',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Threads\PullRequests\AskToFixController::__invoke
* @see app/Http/Controllers/Threads/PullRequests/AskToFixController.php:19
* @route '/threads/{thread}/pull-requests/{repository}/fix'
*/
fix.url = (args: { thread: number | { id: number }, repository: string | number } | [thread: number | { id: number }, repository: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            thread: args[0],
            repository: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        thread: typeof args.thread === 'object'
        ? args.thread.id
        : args.thread,
        repository: args.repository,
    }

    return fix.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace('{repository}', parsedArgs.repository.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\PullRequests\AskToFixController::__invoke
* @see app/Http/Controllers/Threads/PullRequests/AskToFixController.php:19
* @route '/threads/{thread}/pull-requests/{repository}/fix'
*/
fix.post = (args: { thread: number | { id: number }, repository: string | number } | [thread: number | { id: number }, repository: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: fix.url(args, options),
    method: 'post',
})

const pullRequests = {
    show: Object.assign(show, show),
    order: Object.assign(order, order),
    fix: Object.assign(fix, fix),
}

export default pullRequests