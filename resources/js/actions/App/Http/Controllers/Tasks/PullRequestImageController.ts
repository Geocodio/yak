import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Tasks\PullRequestImageController::__invoke
* @see app/Http/Controllers/Tasks/PullRequestImageController.php:26
* @route '/tasks/{task}/pr-images/{asset}'
*/
const PullRequestImageController = (args: { task: string | { public_id: string }, asset: string | number } | [task: string | { public_id: string }, asset: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: PullRequestImageController.url(args, options),
    method: 'get',
})

PullRequestImageController.definition = {
    methods: ["get","head"],
    url: '/tasks/{task}/pr-images/{asset}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Tasks\PullRequestImageController::__invoke
* @see app/Http/Controllers/Tasks/PullRequestImageController.php:26
* @route '/tasks/{task}/pr-images/{asset}'
*/
PullRequestImageController.url = (args: { task: string | { public_id: string }, asset: string | number } | [task: string | { public_id: string }, asset: string | number ], options?: RouteQueryOptions) => {
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

    return PullRequestImageController.definition.url
            .replace('{task}', parsedArgs.task.toString())
            .replace('{asset}', parsedArgs.asset.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Tasks\PullRequestImageController::__invoke
* @see app/Http/Controllers/Tasks/PullRequestImageController.php:26
* @route '/tasks/{task}/pr-images/{asset}'
*/
PullRequestImageController.get = (args: { task: string | { public_id: string }, asset: string | number } | [task: string | { public_id: string }, asset: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: PullRequestImageController.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Tasks\PullRequestImageController::__invoke
* @see app/Http/Controllers/Tasks/PullRequestImageController.php:26
* @route '/tasks/{task}/pr-images/{asset}'
*/
PullRequestImageController.head = (args: { task: string | { public_id: string }, asset: string | number } | [task: string | { public_id: string }, asset: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: PullRequestImageController.url(args, options),
    method: 'head',
})

export default PullRequestImageController