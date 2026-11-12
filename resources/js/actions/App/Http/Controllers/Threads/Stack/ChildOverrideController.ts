import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../../wayfinder'
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

const ChildOverrideController = { takeOver, split, drop }

export default ChildOverrideController