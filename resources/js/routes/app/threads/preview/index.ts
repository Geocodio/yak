import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\Api\App\Threads\Preview\PreviewStatusController::__invoke
* @see app/Http/Controllers/Api/App/Threads/Preview/PreviewStatusController.php:13
* @route '/api/app/v1/threads/{thread}/preview/status'
*/
export const status = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: status.url(args, options),
    method: 'get',
})

status.definition = {
    methods: ["get","head"],
    url: '/api/app/v1/threads/{thread}/preview/status',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Api\App\Threads\Preview\PreviewStatusController::__invoke
* @see app/Http/Controllers/Api/App/Threads/Preview/PreviewStatusController.php:13
* @route '/api/app/v1/threads/{thread}/preview/status'
*/
status.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return status.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Threads\Preview\PreviewStatusController::__invoke
* @see app/Http/Controllers/Api/App/Threads/Preview/PreviewStatusController.php:13
* @route '/api/app/v1/threads/{thread}/preview/status'
*/
status.get = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: status.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\App\Threads\Preview\PreviewStatusController::__invoke
* @see app/Http/Controllers/Api/App/Threads/Preview/PreviewStatusController.php:13
* @route '/api/app/v1/threads/{thread}/preview/status'
*/
status.head = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: status.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Api\App\Threads\Preview\PreviewWarmController::__invoke
* @see app/Http/Controllers/Api/App/Threads/Preview/PreviewWarmController.php:24
* @route '/api/app/v1/threads/{thread}/preview/warm'
*/
export const warm = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: warm.url(args, options),
    method: 'post',
})

warm.definition = {
    methods: ["post"],
    url: '/api/app/v1/threads/{thread}/preview/warm',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\App\Threads\Preview\PreviewWarmController::__invoke
* @see app/Http/Controllers/Api/App/Threads/Preview/PreviewWarmController.php:24
* @route '/api/app/v1/threads/{thread}/preview/warm'
*/
warm.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return warm.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Threads\Preview\PreviewWarmController::__invoke
* @see app/Http/Controllers/Api/App/Threads/Preview/PreviewWarmController.php:24
* @route '/api/app/v1/threads/{thread}/preview/warm'
*/
warm.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: warm.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Api\App\Threads\Preview\PreviewHeartbeatController::__invoke
* @see app/Http/Controllers/Api/App/Threads/Preview/PreviewHeartbeatController.php:15
* @route '/api/app/v1/threads/{thread}/preview/heartbeat'
*/
export const heartbeat = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: heartbeat.url(args, options),
    method: 'post',
})

heartbeat.definition = {
    methods: ["post"],
    url: '/api/app/v1/threads/{thread}/preview/heartbeat',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\App\Threads\Preview\PreviewHeartbeatController::__invoke
* @see app/Http/Controllers/Api/App/Threads/Preview/PreviewHeartbeatController.php:15
* @route '/api/app/v1/threads/{thread}/preview/heartbeat'
*/
heartbeat.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return heartbeat.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Threads\Preview\PreviewHeartbeatController::__invoke
* @see app/Http/Controllers/Api/App/Threads/Preview/PreviewHeartbeatController.php:15
* @route '/api/app/v1/threads/{thread}/preview/heartbeat'
*/
heartbeat.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: heartbeat.url(args, options),
    method: 'post',
})

const preview = {
    status: Object.assign(status, status),
    warm: Object.assign(warm, warm),
    heartbeat: Object.assign(heartbeat, heartbeat),
}

export default preview