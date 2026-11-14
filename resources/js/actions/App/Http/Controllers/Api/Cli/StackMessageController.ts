import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Api\Cli\StackMessageController::index
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Api/Cli/StackMessageController.php:23
* @route '/api/cli/v1/threads/{thread}/stack-messages'
*/
export const index = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(args, options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/api/cli/v1/threads/{thread}/stack-messages',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Api\Cli\StackMessageController::index
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Api/Cli/StackMessageController.php:23
* @route '/api/cli/v1/threads/{thread}/stack-messages'
*/
index.url = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { thread: args }
    }

    if (Array.isArray(args)) {
        args = {
            thread: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        thread: args.thread,
    }

    return index.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\Cli\StackMessageController::index
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Api/Cli/StackMessageController.php:23
* @route '/api/cli/v1/threads/{thread}/stack-messages'
*/
index.get = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\Cli\StackMessageController::index
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Api/Cli/StackMessageController.php:23
* @route '/api/cli/v1/threads/{thread}/stack-messages'
*/
index.head = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Api\Cli\StackMessageController::ack
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Api/Cli/StackMessageController.php:38
* @route '/api/cli/v1/threads/{thread}/stack-messages/ack'
*/
export const ack = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: ack.url(args, options),
    method: 'post',
})

ack.definition = {
    methods: ["post"],
    url: '/api/cli/v1/threads/{thread}/stack-messages/ack',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\Cli\StackMessageController::ack
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Api/Cli/StackMessageController.php:38
* @route '/api/cli/v1/threads/{thread}/stack-messages/ack'
*/
ack.url = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { thread: args }
    }

    if (Array.isArray(args)) {
        args = {
            thread: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        thread: args.thread,
    }

    return ack.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\Cli\StackMessageController::ack
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Api/Cli/StackMessageController.php:38
* @route '/api/cli/v1/threads/{thread}/stack-messages/ack'
*/
ack.post = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: ack.url(args, options),
    method: 'post',
})

const StackMessageController = { index, ack }

export default StackMessageController