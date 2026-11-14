import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Threads\ThreadSharedFileController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Threads/ThreadSharedFileController.php:18
* @route '/threads/{thread}/files/{sharedFile}/team-share'
*/
export const teamShare = (args: { thread: string | { public_id: string }, sharedFile: string | number } | [thread: string | { public_id: string }, sharedFile: string | number ], options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: teamShare.url(args, options),
    method: 'put',
})

teamShare.definition = {
    methods: ["put"],
    url: '/threads/{thread}/files/{sharedFile}/team-share',
} satisfies RouteDefinition<["put"]>

/**
* @see \App\Http\Controllers\Threads\ThreadSharedFileController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Threads/ThreadSharedFileController.php:18
* @route '/threads/{thread}/files/{sharedFile}/team-share'
*/
teamShare.url = (args: { thread: string | { public_id: string }, sharedFile: string | number } | [thread: string | { public_id: string }, sharedFile: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            thread: args[0],
            sharedFile: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        thread: typeof args.thread === 'object'
        ? args.thread.public_id
        : args.thread,
        sharedFile: args.sharedFile,
    }

    return teamShare.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace('{sharedFile}', parsedArgs.sharedFile.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\ThreadSharedFileController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Threads/ThreadSharedFileController.php:18
* @route '/threads/{thread}/files/{sharedFile}/team-share'
*/
teamShare.put = (args: { thread: string | { public_id: string }, sharedFile: string | number } | [thread: string | { public_id: string }, sharedFile: string | number ], options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: teamShare.url(args, options),
    method: 'put',
})

const sharedFiles = {
    teamShare: Object.assign(teamShare, teamShare),
}

export default sharedFiles