import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Api\Cli\SshLookupController::show
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Api/Cli/SshLookupController.php:23
* @route '/api/cli/v1/threads/{thread}/ssh'
*/
export const show = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/api/cli/v1/threads/{thread}/ssh',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Api\Cli\SshLookupController::show
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Api/Cli/SshLookupController.php:23
* @route '/api/cli/v1/threads/{thread}/ssh'
*/
show.url = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions) => {
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

    return show.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\Cli\SshLookupController::show
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Api/Cli/SshLookupController.php:23
* @route '/api/cli/v1/threads/{thread}/ssh'
*/
show.get = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\Cli\SshLookupController::show
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Api/Cli/SshLookupController.php:23
* @route '/api/cli/v1/threads/{thread}/ssh'
*/
show.head = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

const SshLookupController = { show }

export default SshLookupController