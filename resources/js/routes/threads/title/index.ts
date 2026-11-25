import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Threads\ThreadTitleController::__invoke
* @see app/Http/Controllers/Threads/ThreadTitleController.php:15
* @route '/threads/{thread}/title'
*/
export const update = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

update.definition = {
    methods: ["patch"],
    url: '/threads/{thread}/title',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\Threads\ThreadTitleController::__invoke
* @see app/Http/Controllers/Threads/ThreadTitleController.php:15
* @route '/threads/{thread}/title'
*/
update.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return update.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\ThreadTitleController::__invoke
* @see app/Http/Controllers/Threads/ThreadTitleController.php:15
* @route '/threads/{thread}/title'
*/
update.patch = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

const title = {
    update: Object.assign(update, update),
}

export default title