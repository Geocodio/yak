import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Threads\Preview\ThreadPreviewStatusController::__invoke
* @see app/Http/Controllers/Threads/Preview/ThreadPreviewStatusController.php:14
* @route '/threads/{thread}/preview/status'
*/
export const status = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: status.url(args, options),
    method: 'get',
})

status.definition = {
    methods: ["get","head"],
    url: '/threads/{thread}/preview/status',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Threads\Preview\ThreadPreviewStatusController::__invoke
* @see app/Http/Controllers/Threads/Preview/ThreadPreviewStatusController.php:14
* @route '/threads/{thread}/preview/status'
*/
status.url = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return status.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\Preview\ThreadPreviewStatusController::__invoke
* @see app/Http/Controllers/Threads/Preview/ThreadPreviewStatusController.php:14
* @route '/threads/{thread}/preview/status'
*/
status.get = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: status.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Threads\Preview\ThreadPreviewStatusController::__invoke
* @see app/Http/Controllers/Threads/Preview/ThreadPreviewStatusController.php:14
* @route '/threads/{thread}/preview/status'
*/
status.head = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: status.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Threads\Preview\ThreadPreviewHeartbeatController::__invoke
* @see app/Http/Controllers/Threads/Preview/ThreadPreviewHeartbeatController.php:15
* @route '/threads/{thread}/preview/heartbeat'
*/
export const heartbeat = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: heartbeat.url(args, options),
    method: 'post',
})

heartbeat.definition = {
    methods: ["post"],
    url: '/threads/{thread}/preview/heartbeat',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Threads\Preview\ThreadPreviewHeartbeatController::__invoke
* @see app/Http/Controllers/Threads/Preview/ThreadPreviewHeartbeatController.php:15
* @route '/threads/{thread}/preview/heartbeat'
*/
heartbeat.url = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return heartbeat.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\Preview\ThreadPreviewHeartbeatController::__invoke
* @see app/Http/Controllers/Threads/Preview/ThreadPreviewHeartbeatController.php:15
* @route '/threads/{thread}/preview/heartbeat'
*/
heartbeat.post = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: heartbeat.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Threads\Preview\ThreadPreviewWarmController::__invoke
* @see app/Http/Controllers/Threads/Preview/ThreadPreviewWarmController.php:19
* @route '/threads/{thread}/preview/warm'
*/
export const warm = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: warm.url(args, options),
    method: 'post',
})

warm.definition = {
    methods: ["post"],
    url: '/threads/{thread}/preview/warm',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Threads\Preview\ThreadPreviewWarmController::__invoke
* @see app/Http/Controllers/Threads/Preview/ThreadPreviewWarmController.php:19
* @route '/threads/{thread}/preview/warm'
*/
warm.url = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return warm.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\Preview\ThreadPreviewWarmController::__invoke
* @see app/Http/Controllers/Threads/Preview/ThreadPreviewWarmController.php:19
* @route '/threads/{thread}/preview/warm'
*/
warm.post = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: warm.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Threads\Preview\ThreadPreviewRestoreController::__invoke
* @see app/Http/Controllers/Threads/Preview/ThreadPreviewRestoreController.php:16
* @route '/threads/{thread}/preview/restore'
*/
export const restore = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: restore.url(args, options),
    method: 'post',
})

restore.definition = {
    methods: ["post"],
    url: '/threads/{thread}/preview/restore',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Threads\Preview\ThreadPreviewRestoreController::__invoke
* @see app/Http/Controllers/Threads/Preview/ThreadPreviewRestoreController.php:16
* @route '/threads/{thread}/preview/restore'
*/
restore.url = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return restore.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\Preview\ThreadPreviewRestoreController::__invoke
* @see app/Http/Controllers/Threads/Preview/ThreadPreviewRestoreController.php:16
* @route '/threads/{thread}/preview/restore'
*/
restore.post = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: restore.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Internal\ThreadPreviewWakeController::__invoke
* @see app/Http/Controllers/Internal/ThreadPreviewWakeController.php:27
* @route '/internal/threads/preview/wake'
*/
export const wake = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: wake.url(options),
    method: 'get',
})

wake.definition = {
    methods: ["get","head"],
    url: '/internal/threads/preview/wake',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Internal\ThreadPreviewWakeController::__invoke
* @see app/Http/Controllers/Internal/ThreadPreviewWakeController.php:27
* @route '/internal/threads/preview/wake'
*/
wake.url = (options?: RouteQueryOptions) => {
    return wake.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Internal\ThreadPreviewWakeController::__invoke
* @see app/Http/Controllers/Internal/ThreadPreviewWakeController.php:27
* @route '/internal/threads/preview/wake'
*/
wake.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: wake.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Internal\ThreadPreviewWakeController::__invoke
* @see app/Http/Controllers/Internal/ThreadPreviewWakeController.php:27
* @route '/internal/threads/preview/wake'
*/
wake.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: wake.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Internal\ThreadPreviewHostStatusController::__invoke
* @see app/Http/Controllers/Internal/ThreadPreviewHostStatusController.php:14
* @route '/internal/threads/preview/status'
*/
export const hostStatus = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: hostStatus.url(options),
    method: 'get',
})

hostStatus.definition = {
    methods: ["get","head"],
    url: '/internal/threads/preview/status',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Internal\ThreadPreviewHostStatusController::__invoke
* @see app/Http/Controllers/Internal/ThreadPreviewHostStatusController.php:14
* @route '/internal/threads/preview/status'
*/
hostStatus.url = (options?: RouteQueryOptions) => {
    return hostStatus.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Internal\ThreadPreviewHostStatusController::__invoke
* @see app/Http/Controllers/Internal/ThreadPreviewHostStatusController.php:14
* @route '/internal/threads/preview/status'
*/
hostStatus.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: hostStatus.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Internal\ThreadPreviewHostStatusController::__invoke
* @see app/Http/Controllers/Internal/ThreadPreviewHostStatusController.php:14
* @route '/internal/threads/preview/status'
*/
hostStatus.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: hostStatus.url(options),
    method: 'head',
})

const preview = {
    status: Object.assign(status, status),
    heartbeat: Object.assign(heartbeat, heartbeat),
    warm: Object.assign(warm, warm),
    restore: Object.assign(restore, restore),
    wake: Object.assign(wake, wake),
    hostStatus: Object.assign(hostStatus, hostStatus),
}

export default preview