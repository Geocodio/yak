import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\PrReviews\PrReviewForPrController::log
* @see app/Http/Controllers/PrReviews/PrReviewForPrController.php:35
* @route '/pr-reviews/for/{repoSlug}/{prNumber}/runs/{task}/log'
*/
export const log = (args: { repoSlug: string | number, prNumber: string | number, task: string | { public_id: string } } | [repoSlug: string | number, prNumber: string | number, task: string | { public_id: string } ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: log.url(args, options),
    method: 'get',
})

log.definition = {
    methods: ["get","head"],
    url: '/pr-reviews/for/{repoSlug}/{prNumber}/runs/{task}/log',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\PrReviews\PrReviewForPrController::log
* @see app/Http/Controllers/PrReviews/PrReviewForPrController.php:35
* @route '/pr-reviews/for/{repoSlug}/{prNumber}/runs/{task}/log'
*/
log.url = (args: { repoSlug: string | number, prNumber: string | number, task: string | { public_id: string } } | [repoSlug: string | number, prNumber: string | number, task: string | { public_id: string } ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            repoSlug: args[0],
            prNumber: args[1],
            task: args[2],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        repoSlug: args.repoSlug,
        prNumber: args.prNumber,
        task: typeof args.task === 'object'
        ? args.task.public_id
        : args.task,
    }

    return log.definition.url
            .replace('{repoSlug}', parsedArgs.repoSlug.toString())
            .replace('{prNumber}', parsedArgs.prNumber.toString())
            .replace('{task}', parsedArgs.task.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\PrReviews\PrReviewForPrController::log
* @see app/Http/Controllers/PrReviews/PrReviewForPrController.php:35
* @route '/pr-reviews/for/{repoSlug}/{prNumber}/runs/{task}/log'
*/
log.get = (args: { repoSlug: string | number, prNumber: string | number, task: string | { public_id: string } } | [repoSlug: string | number, prNumber: string | number, task: string | { public_id: string } ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: log.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\PrReviews\PrReviewForPrController::log
* @see app/Http/Controllers/PrReviews/PrReviewForPrController.php:35
* @route '/pr-reviews/for/{repoSlug}/{prNumber}/runs/{task}/log'
*/
log.head = (args: { repoSlug: string | number, prNumber: string | number, task: string | { public_id: string } } | [repoSlug: string | number, prNumber: string | number, task: string | { public_id: string } ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: log.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\PrReviews\PrReviewForPrController::rerun
* @see app/Http/Controllers/PrReviews/PrReviewForPrController.php:48
* @route '/pr-reviews/for/{repoSlug}/{prNumber}/runs/{task}/rerun'
*/
export const rerun = (args: { repoSlug: string | number, prNumber: string | number, task: string | { public_id: string } } | [repoSlug: string | number, prNumber: string | number, task: string | { public_id: string } ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: rerun.url(args, options),
    method: 'post',
})

rerun.definition = {
    methods: ["post"],
    url: '/pr-reviews/for/{repoSlug}/{prNumber}/runs/{task}/rerun',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\PrReviews\PrReviewForPrController::rerun
* @see app/Http/Controllers/PrReviews/PrReviewForPrController.php:48
* @route '/pr-reviews/for/{repoSlug}/{prNumber}/runs/{task}/rerun'
*/
rerun.url = (args: { repoSlug: string | number, prNumber: string | number, task: string | { public_id: string } } | [repoSlug: string | number, prNumber: string | number, task: string | { public_id: string } ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            repoSlug: args[0],
            prNumber: args[1],
            task: args[2],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        repoSlug: args.repoSlug,
        prNumber: args.prNumber,
        task: typeof args.task === 'object'
        ? args.task.public_id
        : args.task,
    }

    return rerun.definition.url
            .replace('{repoSlug}', parsedArgs.repoSlug.toString())
            .replace('{prNumber}', parsedArgs.prNumber.toString())
            .replace('{task}', parsedArgs.task.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\PrReviews\PrReviewForPrController::rerun
* @see app/Http/Controllers/PrReviews/PrReviewForPrController.php:48
* @route '/pr-reviews/for/{repoSlug}/{prNumber}/runs/{task}/rerun'
*/
rerun.post = (args: { repoSlug: string | number, prNumber: string | number, task: string | { public_id: string } } | [repoSlug: string | number, prNumber: string | number, task: string | { public_id: string } ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: rerun.url(args, options),
    method: 'post',
})

const runs = {
    log: Object.assign(log, log),
    rerun: Object.assign(rerun, rerun),
}

export default runs