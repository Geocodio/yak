import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../wayfinder'
import children from './children'
/**
* @see \App\Http\Controllers\Threads\Stack\ChildOverrideController::takeOver
* @see app/Http/Controllers/Threads/Stack/ChildOverrideController.php:19
* @route '/threads/{thread}/stack/take-over'
*/
export const takeOver = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: takeOver.url(args, options),
    method: 'post',
})

takeOver.definition = {
    methods: ["post"],
    url: '/threads/{thread}/stack/take-over',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Threads\Stack\ChildOverrideController::takeOver
* @see app/Http/Controllers/Threads/Stack/ChildOverrideController.php:19
* @route '/threads/{thread}/stack/take-over'
*/
takeOver.url = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return takeOver.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\Stack\ChildOverrideController::takeOver
* @see app/Http/Controllers/Threads/Stack/ChildOverrideController.php:19
* @route '/threads/{thread}/stack/take-over'
*/
takeOver.post = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: takeOver.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Threads\Stack\ChildOverrideController::split
* @see app/Http/Controllers/Threads/Stack/ChildOverrideController.php:27
* @route '/threads/{thread}/stack/split'
*/
export const split = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: split.url(args, options),
    method: 'post',
})

split.definition = {
    methods: ["post"],
    url: '/threads/{thread}/stack/split',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Threads\Stack\ChildOverrideController::split
* @see app/Http/Controllers/Threads/Stack/ChildOverrideController.php:27
* @route '/threads/{thread}/stack/split'
*/
split.url = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return split.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\Stack\ChildOverrideController::split
* @see app/Http/Controllers/Threads/Stack/ChildOverrideController.php:27
* @route '/threads/{thread}/stack/split'
*/
split.post = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: split.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Threads\Stack\ChildOverrideController::drop
* @see app/Http/Controllers/Threads/Stack/ChildOverrideController.php:40
* @route '/threads/{thread}/stack/drop'
*/
export const drop = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: drop.url(args, options),
    method: 'post',
})

drop.definition = {
    methods: ["post"],
    url: '/threads/{thread}/stack/drop',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Threads\Stack\ChildOverrideController::drop
* @see app/Http/Controllers/Threads/Stack/ChildOverrideController.php:40
* @route '/threads/{thread}/stack/drop'
*/
drop.url = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return drop.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\Stack\ChildOverrideController::drop
* @see app/Http/Controllers/Threads/Stack/ChildOverrideController.php:40
* @route '/threads/{thread}/stack/drop'
*/
drop.post = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: drop.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Threads\Stack\StackLimitController::__invoke
* @see app/Http/Controllers/Threads/Stack/StackLimitController.php:17
* @route '/threads/{thread}/stack/limit'
*/
export const limit = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: limit.url(args, options),
    method: 'patch',
})

limit.definition = {
    methods: ["patch"],
    url: '/threads/{thread}/stack/limit',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\Threads\Stack\StackLimitController::__invoke
* @see app/Http/Controllers/Threads/Stack/StackLimitController.php:17
* @route '/threads/{thread}/stack/limit'
*/
limit.url = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return limit.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\Stack\StackLimitController::__invoke
* @see app/Http/Controllers/Threads/Stack/StackLimitController.php:17
* @route '/threads/{thread}/stack/limit'
*/
limit.patch = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: limit.url(args, options),
    method: 'patch',
})

/**
* @see \App\Http\Controllers\Threads\Stack\StackStopController::__invoke
* @see app/Http/Controllers/Threads/Stack/StackStopController.php:16
* @route '/threads/{thread}/stack/stop'
*/
export const stop = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: stop.url(args, options),
    method: 'post',
})

stop.definition = {
    methods: ["post"],
    url: '/threads/{thread}/stack/stop',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Threads\Stack\StackStopController::__invoke
* @see app/Http/Controllers/Threads/Stack/StackStopController.php:16
* @route '/threads/{thread}/stack/stop'
*/
stop.url = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return stop.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\Stack\StackStopController::__invoke
* @see app/Http/Controllers/Threads/Stack/StackStopController.php:16
* @route '/threads/{thread}/stack/stop'
*/
stop.post = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: stop.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Threads\Stack\StackShowController::__invoke
* @see app/Http/Controllers/Threads/Stack/StackShowController.php:17
* @route '/threads/{thread}/stack'
*/
export const show = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/threads/{thread}/stack',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Threads\Stack\StackShowController::__invoke
* @see app/Http/Controllers/Threads/Stack/StackShowController.php:17
* @route '/threads/{thread}/stack'
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
* @see \App\Http\Controllers\Threads\Stack\StackShowController::__invoke
* @see app/Http/Controllers/Threads/Stack/StackShowController.php:17
* @route '/threads/{thread}/stack'
*/
show.get = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Threads\Stack\StackShowController::__invoke
* @see app/Http/Controllers/Threads/Stack/StackShowController.php:17
* @route '/threads/{thread}/stack'
*/
show.head = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

const stack = {
    takeOver: Object.assign(takeOver, takeOver),
    split: Object.assign(split, split),
    drop: Object.assign(drop, drop),
    limit: Object.assign(limit, limit),
    stop: Object.assign(stop, stop),
    show: Object.assign(show, show),
    children: Object.assign(children, children),
}

export default stack