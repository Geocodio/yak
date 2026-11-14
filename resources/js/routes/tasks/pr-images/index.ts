import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Tasks\PullRequestImageController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Tasks/PullRequestImageController.php:26
* @route '/tasks/{task}/pr-images/{asset}'
*/
export const show = (args: { task: string | { public_id: string }, asset: string | number } | [task: string | { public_id: string }, asset: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/tasks/{task}/pr-images/{asset}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Tasks\PullRequestImageController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Tasks/PullRequestImageController.php:26
* @route '/tasks/{task}/pr-images/{asset}'
*/
show.url = (args: { task: string | { public_id: string }, asset: string | number } | [task: string | { public_id: string }, asset: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            task: args[0],
            asset: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        task: typeof args.task === 'object'
        ? args.task.public_id
        : args.task,
        asset: args.asset,
    }

    return show.definition.url
            .replace('{task}', parsedArgs.task.toString())
            .replace('{asset}', parsedArgs.asset.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Tasks\PullRequestImageController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Tasks/PullRequestImageController.php:26
* @route '/tasks/{task}/pr-images/{asset}'
*/
show.get = (args: { task: string | { public_id: string }, asset: string | number } | [task: string | { public_id: string }, asset: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Tasks\PullRequestImageController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Tasks/PullRequestImageController.php:26
* @route '/tasks/{task}/pr-images/{asset}'
*/
show.head = (args: { task: string | { public_id: string }, asset: string | number } | [task: string | { public_id: string }, asset: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

const prImages = {
    show: Object.assign(show, show),
}

export default prImages