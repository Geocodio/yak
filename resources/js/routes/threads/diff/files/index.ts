import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\Threads\ThreadDiffController::show
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Threads/ThreadDiffController.php:22
* @route '/threads/{thread}/diff/files/{file}'
*/
export const show = (args: { thread: string | { public_id: string }, file: string | number } | [thread: string | { public_id: string }, file: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/threads/{thread}/diff/files/{file}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Threads\ThreadDiffController::show
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Threads/ThreadDiffController.php:22
* @route '/threads/{thread}/diff/files/{file}'
*/
show.url = (args: { thread: string | { public_id: string }, file: string | number } | [thread: string | { public_id: string }, file: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            thread: args[0],
            file: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        thread: typeof args.thread === 'object'
        ? args.thread.public_id
        : args.thread,
        file: args.file,
    }

    return show.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace('{file}', parsedArgs.file.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\ThreadDiffController::show
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Threads/ThreadDiffController.php:22
* @route '/threads/{thread}/diff/files/{file}'
*/
show.get = (args: { thread: string | { public_id: string }, file: string | number } | [thread: string | { public_id: string }, file: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Threads\ThreadDiffController::show
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Threads/ThreadDiffController.php:22
* @route '/threads/{thread}/diff/files/{file}'
*/
show.head = (args: { thread: string | { public_id: string }, file: string | number } | [thread: string | { public_id: string }, file: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

const files = {
    show: Object.assign(show, show),
}

export default files