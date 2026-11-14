import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Tasks\EarlyWarningController::tag
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Tasks/EarlyWarningController.php:23
* @route '/tasks/{task}/co-owner/tag'
*/
export const tag = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: tag.url(args, options),
    method: 'post',
})

tag.definition = {
    methods: ["post"],
    url: '/tasks/{task}/co-owner/tag',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Tasks\EarlyWarningController::tag
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Tasks/EarlyWarningController.php:23
* @route '/tasks/{task}/co-owner/tag'
*/
tag.url = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { task: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'public_id' in args) {
        args = { task: args.public_id }
    }

    if (Array.isArray(args)) {
        args = {
            task: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        task: typeof args.task === 'object'
        ? args.task.public_id
        : args.task,
    }

    return tag.definition.url
            .replace('{task}', parsedArgs.task.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Tasks\EarlyWarningController::tag
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Tasks/EarlyWarningController.php:23
* @route '/tasks/{task}/co-owner/tag'
*/
tag.post = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: tag.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Tasks\EarlyWarningController::decline
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Tasks/EarlyWarningController.php:30
* @route '/tasks/{task}/co-owner/decline'
*/
export const decline = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: decline.url(args, options),
    method: 'post',
})

decline.definition = {
    methods: ["post"],
    url: '/tasks/{task}/co-owner/decline',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Tasks\EarlyWarningController::decline
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Tasks/EarlyWarningController.php:30
* @route '/tasks/{task}/co-owner/decline'
*/
decline.url = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { task: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'public_id' in args) {
        args = { task: args.public_id }
    }

    if (Array.isArray(args)) {
        args = {
            task: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        task: typeof args.task === 'object'
        ? args.task.public_id
        : args.task,
    }

    return decline.definition.url
            .replace('{task}', parsedArgs.task.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Tasks\EarlyWarningController::decline
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Tasks/EarlyWarningController.php:30
* @route '/tasks/{task}/co-owner/decline'
*/
decline.post = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: decline.url(args, options),
    method: 'post',
})

const EarlyWarningController = { tag, decline }

export default EarlyWarningController