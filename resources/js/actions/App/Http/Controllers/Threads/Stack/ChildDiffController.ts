import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Threads\Stack\ChildDiffController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Threads/Stack/ChildDiffController.php:16
* @route '/threads/{thread}/stack/children/{child}/diff'
*/
const ChildDiffController = (args: { thread: string | { public_id: string }, child: string | { public_id: string } } | [thread: string | { public_id: string }, child: string | { public_id: string } ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ChildDiffController.url(args, options),
    method: 'get',
})

ChildDiffController.definition = {
    methods: ["get","head"],
    url: '/threads/{thread}/stack/children/{child}/diff',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Threads\Stack\ChildDiffController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Threads/Stack/ChildDiffController.php:16
* @route '/threads/{thread}/stack/children/{child}/diff'
*/
ChildDiffController.url = (args: { thread: string | { public_id: string }, child: string | { public_id: string } } | [thread: string | { public_id: string }, child: string | { public_id: string } ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            thread: args[0],
            child: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        thread: typeof args.thread === 'object'
        ? args.thread.public_id
        : args.thread,
        child: typeof args.child === 'object'
        ? args.child.public_id
        : args.child,
    }

    return ChildDiffController.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace('{child}', parsedArgs.child.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\Stack\ChildDiffController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Threads/Stack/ChildDiffController.php:16
* @route '/threads/{thread}/stack/children/{child}/diff'
*/
ChildDiffController.get = (args: { thread: string | { public_id: string }, child: string | { public_id: string } } | [thread: string | { public_id: string }, child: string | { public_id: string } ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ChildDiffController.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Threads\Stack\ChildDiffController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Threads/Stack/ChildDiffController.php:16
* @route '/threads/{thread}/stack/children/{child}/diff'
*/
ChildDiffController.head = (args: { thread: string | { public_id: string }, child: string | { public_id: string } } | [thread: string | { public_id: string }, child: string | { public_id: string } ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: ChildDiffController.url(args, options),
    method: 'head',
})

export default ChildDiffController