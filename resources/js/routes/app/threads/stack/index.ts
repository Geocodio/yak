import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../wayfinder'
import children from './children'
/**
* @see \App\Http\Controllers\Api\App\Threads\StackController::show
* @see app/Http/Controllers/Api/App/Threads/StackController.php:22
* @route '/api/app/v1/threads/{thread}/stack'
*/
export const show = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/api/app/v1/threads/{thread}/stack',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Api\App\Threads\StackController::show
* @see app/Http/Controllers/Api/App/Threads/StackController.php:22
* @route '/api/app/v1/threads/{thread}/stack'
*/
show.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return show.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Threads\StackController::show
* @see app/Http/Controllers/Api/App/Threads/StackController.php:22
* @route '/api/app/v1/threads/{thread}/stack'
*/
show.get = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\App\Threads\StackController::show
* @see app/Http/Controllers/Api/App/Threads/StackController.php:22
* @route '/api/app/v1/threads/{thread}/stack'
*/
show.head = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Api\App\Threads\StackController::stop
* @see app/Http/Controllers/Api/App/Threads/StackController.php:52
* @route '/api/app/v1/threads/{thread}/stack/stop'
*/
export const stop = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: stop.url(args, options),
    method: 'post',
})

stop.definition = {
    methods: ["post"],
    url: '/api/app/v1/threads/{thread}/stack/stop',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\App\Threads\StackController::stop
* @see app/Http/Controllers/Api/App/Threads/StackController.php:52
* @route '/api/app/v1/threads/{thread}/stack/stop'
*/
stop.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return stop.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Threads\StackController::stop
* @see app/Http/Controllers/Api/App/Threads/StackController.php:52
* @route '/api/app/v1/threads/{thread}/stack/stop'
*/
stop.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: stop.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Api\App\Threads\StackController::limit
* @see app/Http/Controllers/Api/App/Threads/StackController.php:63
* @route '/api/app/v1/threads/{thread}/stack/limit'
*/
export const limit = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: limit.url(args, options),
    method: 'patch',
})

limit.definition = {
    methods: ["patch"],
    url: '/api/app/v1/threads/{thread}/stack/limit',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\Api\App\Threads\StackController::limit
* @see app/Http/Controllers/Api/App/Threads/StackController.php:63
* @route '/api/app/v1/threads/{thread}/stack/limit'
*/
limit.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return limit.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Threads\StackController::limit
* @see app/Http/Controllers/Api/App/Threads/StackController.php:63
* @route '/api/app/v1/threads/{thread}/stack/limit'
*/
limit.patch = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: limit.url(args, options),
    method: 'patch',
})

/**
* @see \App\Http\Controllers\Api\App\Threads\StackController::takeOver
* @see app/Http/Controllers/Api/App/Threads/StackController.php:70
* @route '/api/app/v1/threads/{thread}/stack/take-over'
*/
export const takeOver = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: takeOver.url(args, options),
    method: 'post',
})

takeOver.definition = {
    methods: ["post"],
    url: '/api/app/v1/threads/{thread}/stack/take-over',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\App\Threads\StackController::takeOver
* @see app/Http/Controllers/Api/App/Threads/StackController.php:70
* @route '/api/app/v1/threads/{thread}/stack/take-over'
*/
takeOver.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return takeOver.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Threads\StackController::takeOver
* @see app/Http/Controllers/Api/App/Threads/StackController.php:70
* @route '/api/app/v1/threads/{thread}/stack/take-over'
*/
takeOver.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: takeOver.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Api\App\Threads\StackController::split
* @see app/Http/Controllers/Api/App/Threads/StackController.php:78
* @route '/api/app/v1/threads/{thread}/stack/split'
*/
export const split = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: split.url(args, options),
    method: 'post',
})

split.definition = {
    methods: ["post"],
    url: '/api/app/v1/threads/{thread}/stack/split',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\App\Threads\StackController::split
* @see app/Http/Controllers/Api/App/Threads/StackController.php:78
* @route '/api/app/v1/threads/{thread}/stack/split'
*/
split.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return split.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Threads\StackController::split
* @see app/Http/Controllers/Api/App/Threads/StackController.php:78
* @route '/api/app/v1/threads/{thread}/stack/split'
*/
split.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: split.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Api\App\Threads\StackController::drop
* @see app/Http/Controllers/Api/App/Threads/StackController.php:86
* @route '/api/app/v1/threads/{thread}/stack/drop'
*/
export const drop = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: drop.url(args, options),
    method: 'post',
})

drop.definition = {
    methods: ["post"],
    url: '/api/app/v1/threads/{thread}/stack/drop',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\App\Threads\StackController::drop
* @see app/Http/Controllers/Api/App/Threads/StackController.php:86
* @route '/api/app/v1/threads/{thread}/stack/drop'
*/
drop.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return drop.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Threads\StackController::drop
* @see app/Http/Controllers/Api/App/Threads/StackController.php:86
* @route '/api/app/v1/threads/{thread}/stack/drop'
*/
drop.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: drop.url(args, options),
    method: 'post',
})

const stack = {
    show: Object.assign(show, show),
    children: Object.assign(children, children),
    stop: Object.assign(stop, stop),
    limit: Object.assign(limit, limit),
    takeOver: Object.assign(takeOver, takeOver),
    split: Object.assign(split, split),
    drop: Object.assign(drop, drop),
}

export default stack