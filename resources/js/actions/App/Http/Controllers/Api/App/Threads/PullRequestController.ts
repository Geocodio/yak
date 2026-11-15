import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Api\App\Threads\PullRequestController::store
* @see app/Http/Controllers/Api/App/Threads/PullRequestController.php:22
* @route '/api/app/v1/threads/{thread}/pull-request'
*/
export const store = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/api/app/v1/threads/{thread}/pull-request',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\App\Threads\PullRequestController::store
* @see app/Http/Controllers/Api/App/Threads/PullRequestController.php:22
* @route '/api/app/v1/threads/{thread}/pull-request'
*/
store.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { thread: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'public_id' in args) {
        args = { thread: args.public_id }
    }

    if (Array.isArray(args)) {
        args = {
            thread: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        thread: typeof args.thread === 'object'
        ? args.thread.public_id
        : args.thread,
    }

    return store.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Threads\PullRequestController::store
* @see app/Http/Controllers/Api/App/Threads/PullRequestController.php:22
* @route '/api/app/v1/threads/{thread}/pull-request'
*/
store.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Api\App\Threads\PullRequestController::index
* @see app/Http/Controllers/Api/App/Threads/PullRequestController.php:32
* @route '/api/app/v1/threads/{thread}/pull-requests'
*/
export const index = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(args, options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/api/app/v1/threads/{thread}/pull-requests',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Api\App\Threads\PullRequestController::index
* @see app/Http/Controllers/Api/App/Threads/PullRequestController.php:32
* @route '/api/app/v1/threads/{thread}/pull-requests'
*/
index.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { thread: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'public_id' in args) {
        args = { thread: args.public_id }
    }

    if (Array.isArray(args)) {
        args = {
            thread: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        thread: typeof args.thread === 'object'
        ? args.thread.public_id
        : args.thread,
    }

    return index.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Threads\PullRequestController::index
* @see app/Http/Controllers/Api/App/Threads/PullRequestController.php:32
* @route '/api/app/v1/threads/{thread}/pull-requests'
*/
index.get = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\App\Threads\PullRequestController::index
* @see app/Http/Controllers/Api/App/Threads/PullRequestController.php:32
* @route '/api/app/v1/threads/{thread}/pull-requests'
*/
index.head = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Api\App\Threads\PullRequestController::order
* @see app/Http/Controllers/Api/App/Threads/PullRequestController.php:43
* @route '/api/app/v1/threads/{thread}/pull-requests/order'
*/
export const order = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: order.url(args, options),
    method: 'put',
})

order.definition = {
    methods: ["put"],
    url: '/api/app/v1/threads/{thread}/pull-requests/order',
} satisfies RouteDefinition<["put"]>

/**
* @see \App\Http\Controllers\Api\App\Threads\PullRequestController::order
* @see app/Http/Controllers/Api/App/Threads/PullRequestController.php:43
* @route '/api/app/v1/threads/{thread}/pull-requests/order'
*/
order.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { thread: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'public_id' in args) {
        args = { thread: args.public_id }
    }

    if (Array.isArray(args)) {
        args = {
            thread: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        thread: typeof args.thread === 'object'
        ? args.thread.public_id
        : args.thread,
    }

    return order.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Threads\PullRequestController::order
* @see app/Http/Controllers/Api/App/Threads/PullRequestController.php:43
* @route '/api/app/v1/threads/{thread}/pull-requests/order'
*/
order.put = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: order.url(args, options),
    method: 'put',
})

/**
* @see \App\Http\Controllers\Api\App\Threads\PullRequestController::fix
* @see app/Http/Controllers/Api/App/Threads/PullRequestController.php:55
* @route '/api/app/v1/threads/{thread}/pull-requests/{repository}/fix'
*/
export const fix = (args: { thread: string | { public_id: string }, repository: string | number } | [thread: string | { public_id: string }, repository: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: fix.url(args, options),
    method: 'post',
})

fix.definition = {
    methods: ["post"],
    url: '/api/app/v1/threads/{thread}/pull-requests/{repository}/fix',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\App\Threads\PullRequestController::fix
* @see app/Http/Controllers/Api/App/Threads/PullRequestController.php:55
* @route '/api/app/v1/threads/{thread}/pull-requests/{repository}/fix'
*/
fix.url = (args: { thread: string | { public_id: string }, repository: string | number } | [thread: string | { public_id: string }, repository: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            thread: args[0],
            repository: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        thread: typeof args.thread === 'object'
        ? args.thread.public_id
        : args.thread,
        repository: args.repository,
    }

    return fix.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace('{repository}', parsedArgs.repository.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Threads\PullRequestController::fix
* @see app/Http/Controllers/Api/App/Threads/PullRequestController.php:55
* @route '/api/app/v1/threads/{thread}/pull-requests/{repository}/fix'
*/
fix.post = (args: { thread: string | { public_id: string }, repository: string | number } | [thread: string | { public_id: string }, repository: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: fix.url(args, options),
    method: 'post',
})

const PullRequestController = { store, index, order, fix }

export default PullRequestController