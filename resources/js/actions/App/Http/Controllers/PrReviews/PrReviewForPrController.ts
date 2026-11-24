import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\PrReviews\PrReviewForPrController::show
* @see app/Http/Controllers/PrReviews/PrReviewForPrController.php:25
* @route '/app/embed/pr-reviews/{repoSlug}/{prNumber}'
*/
const show985ae47b97aafc398becb570fe8ff6b0 = (args: { repoSlug: string | number, prNumber: string | number } | [repoSlug: string | number, prNumber: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show985ae47b97aafc398becb570fe8ff6b0.url(args, options),
    method: 'get',
})

show985ae47b97aafc398becb570fe8ff6b0.definition = {
    methods: ["get","head"],
    url: '/app/embed/pr-reviews/{repoSlug}/{prNumber}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\PrReviews\PrReviewForPrController::show
* @see app/Http/Controllers/PrReviews/PrReviewForPrController.php:25
* @route '/app/embed/pr-reviews/{repoSlug}/{prNumber}'
*/
show985ae47b97aafc398becb570fe8ff6b0.url = (args: { repoSlug: string | number, prNumber: string | number } | [repoSlug: string | number, prNumber: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            repoSlug: args[0],
            prNumber: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        repoSlug: args.repoSlug,
        prNumber: args.prNumber,
    }

    return show985ae47b97aafc398becb570fe8ff6b0.definition.url
            .replace('{repoSlug}', parsedArgs.repoSlug.toString())
            .replace('{prNumber}', parsedArgs.prNumber.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\PrReviews\PrReviewForPrController::show
* @see app/Http/Controllers/PrReviews/PrReviewForPrController.php:25
* @route '/app/embed/pr-reviews/{repoSlug}/{prNumber}'
*/
show985ae47b97aafc398becb570fe8ff6b0.get = (args: { repoSlug: string | number, prNumber: string | number } | [repoSlug: string | number, prNumber: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show985ae47b97aafc398becb570fe8ff6b0.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\PrReviews\PrReviewForPrController::show
* @see app/Http/Controllers/PrReviews/PrReviewForPrController.php:25
* @route '/app/embed/pr-reviews/{repoSlug}/{prNumber}'
*/
show985ae47b97aafc398becb570fe8ff6b0.head = (args: { repoSlug: string | number, prNumber: string | number } | [repoSlug: string | number, prNumber: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show985ae47b97aafc398becb570fe8ff6b0.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\PrReviews\PrReviewForPrController::show
* @see app/Http/Controllers/PrReviews/PrReviewForPrController.php:25
* @route '/pr-reviews/for/{repoSlug}/{prNumber}'
*/
const showefbb4b9273f03868efa251b52ecf8ff7 = (args: { repoSlug: string | number, prNumber: string | number } | [repoSlug: string | number, prNumber: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: showefbb4b9273f03868efa251b52ecf8ff7.url(args, options),
    method: 'get',
})

showefbb4b9273f03868efa251b52ecf8ff7.definition = {
    methods: ["get","head"],
    url: '/pr-reviews/for/{repoSlug}/{prNumber}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\PrReviews\PrReviewForPrController::show
* @see app/Http/Controllers/PrReviews/PrReviewForPrController.php:25
* @route '/pr-reviews/for/{repoSlug}/{prNumber}'
*/
showefbb4b9273f03868efa251b52ecf8ff7.url = (args: { repoSlug: string | number, prNumber: string | number } | [repoSlug: string | number, prNumber: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            repoSlug: args[0],
            prNumber: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        repoSlug: args.repoSlug,
        prNumber: args.prNumber,
    }

    return showefbb4b9273f03868efa251b52ecf8ff7.definition.url
            .replace('{repoSlug}', parsedArgs.repoSlug.toString())
            .replace('{prNumber}', parsedArgs.prNumber.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\PrReviews\PrReviewForPrController::show
* @see app/Http/Controllers/PrReviews/PrReviewForPrController.php:25
* @route '/pr-reviews/for/{repoSlug}/{prNumber}'
*/
showefbb4b9273f03868efa251b52ecf8ff7.get = (args: { repoSlug: string | number, prNumber: string | number } | [repoSlug: string | number, prNumber: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: showefbb4b9273f03868efa251b52ecf8ff7.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\PrReviews\PrReviewForPrController::show
* @see app/Http/Controllers/PrReviews/PrReviewForPrController.php:25
* @route '/pr-reviews/for/{repoSlug}/{prNumber}'
*/
showefbb4b9273f03868efa251b52ecf8ff7.head = (args: { repoSlug: string | number, prNumber: string | number } | [repoSlug: string | number, prNumber: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: showefbb4b9273f03868efa251b52ecf8ff7.url(args, options),
    method: 'head',
})

/**
* Multiple routes resolve to \App\Http\Controllers\PrReviews\PrReviewForPrController::show, so this export is a
* dictionary keyed by URI rather than a callable. Call a specific route with `show['<uri>'](...)`,
* or import the route by name from your generated `routes/` directory.
*/
export const show = {
    '/app/embed/pr-reviews/{repoSlug}/{prNumber}': show985ae47b97aafc398becb570fe8ff6b0,
    '/pr-reviews/for/{repoSlug}/{prNumber}': showefbb4b9273f03868efa251b52ecf8ff7,
}

/**
* @see \App\Http\Controllers\PrReviews\PrReviewForPrController::rerun
* @see app/Http/Controllers/PrReviews/PrReviewForPrController.php:73
* @route '/pr-reviews/for/{repoSlug}/{prNumber}/rerun'
*/
export const rerun = (args: { repoSlug: string | number, prNumber: string | number } | [repoSlug: string | number, prNumber: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: rerun.url(args, options),
    method: 'post',
})

rerun.definition = {
    methods: ["post"],
    url: '/pr-reviews/for/{repoSlug}/{prNumber}/rerun',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\PrReviews\PrReviewForPrController::rerun
* @see app/Http/Controllers/PrReviews/PrReviewForPrController.php:73
* @route '/pr-reviews/for/{repoSlug}/{prNumber}/rerun'
*/
rerun.url = (args: { repoSlug: string | number, prNumber: string | number } | [repoSlug: string | number, prNumber: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            repoSlug: args[0],
            prNumber: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        repoSlug: args.repoSlug,
        prNumber: args.prNumber,
    }

    return rerun.definition.url
            .replace('{repoSlug}', parsedArgs.repoSlug.toString())
            .replace('{prNumber}', parsedArgs.prNumber.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\PrReviews\PrReviewForPrController::rerun
* @see app/Http/Controllers/PrReviews/PrReviewForPrController.php:73
* @route '/pr-reviews/for/{repoSlug}/{prNumber}/rerun'
*/
rerun.post = (args: { repoSlug: string | number, prNumber: string | number } | [repoSlug: string | number, prNumber: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: rerun.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\PrReviews\PrReviewForPrController::runLog
* @see app/Http/Controllers/PrReviews/PrReviewForPrController.php:41
* @route '/pr-reviews/for/{repoSlug}/{prNumber}/runs/{task}/log'
*/
export const runLog = (args: { repoSlug: string | number, prNumber: string | number, task: string | { public_id: string } } | [repoSlug: string | number, prNumber: string | number, task: string | { public_id: string } ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: runLog.url(args, options),
    method: 'get',
})

runLog.definition = {
    methods: ["get","head"],
    url: '/pr-reviews/for/{repoSlug}/{prNumber}/runs/{task}/log',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\PrReviews\PrReviewForPrController::runLog
* @see app/Http/Controllers/PrReviews/PrReviewForPrController.php:41
* @route '/pr-reviews/for/{repoSlug}/{prNumber}/runs/{task}/log'
*/
runLog.url = (args: { repoSlug: string | number, prNumber: string | number, task: string | { public_id: string } } | [repoSlug: string | number, prNumber: string | number, task: string | { public_id: string } ], options?: RouteQueryOptions) => {
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

    return runLog.definition.url
            .replace('{repoSlug}', parsedArgs.repoSlug.toString())
            .replace('{prNumber}', parsedArgs.prNumber.toString())
            .replace('{task}', parsedArgs.task.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\PrReviews\PrReviewForPrController::runLog
* @see app/Http/Controllers/PrReviews/PrReviewForPrController.php:41
* @route '/pr-reviews/for/{repoSlug}/{prNumber}/runs/{task}/log'
*/
runLog.get = (args: { repoSlug: string | number, prNumber: string | number, task: string | { public_id: string } } | [repoSlug: string | number, prNumber: string | number, task: string | { public_id: string } ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: runLog.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\PrReviews\PrReviewForPrController::runLog
* @see app/Http/Controllers/PrReviews/PrReviewForPrController.php:41
* @route '/pr-reviews/for/{repoSlug}/{prNumber}/runs/{task}/log'
*/
runLog.head = (args: { repoSlug: string | number, prNumber: string | number, task: string | { public_id: string } } | [repoSlug: string | number, prNumber: string | number, task: string | { public_id: string } ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: runLog.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\PrReviews\PrReviewForPrController::rerunRun
* @see app/Http/Controllers/PrReviews/PrReviewForPrController.php:54
* @route '/pr-reviews/for/{repoSlug}/{prNumber}/runs/{task}/rerun'
*/
export const rerunRun = (args: { repoSlug: string | number, prNumber: string | number, task: string | { public_id: string } } | [repoSlug: string | number, prNumber: string | number, task: string | { public_id: string } ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: rerunRun.url(args, options),
    method: 'post',
})

rerunRun.definition = {
    methods: ["post"],
    url: '/pr-reviews/for/{repoSlug}/{prNumber}/runs/{task}/rerun',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\PrReviews\PrReviewForPrController::rerunRun
* @see app/Http/Controllers/PrReviews/PrReviewForPrController.php:54
* @route '/pr-reviews/for/{repoSlug}/{prNumber}/runs/{task}/rerun'
*/
rerunRun.url = (args: { repoSlug: string | number, prNumber: string | number, task: string | { public_id: string } } | [repoSlug: string | number, prNumber: string | number, task: string | { public_id: string } ], options?: RouteQueryOptions) => {
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

    return rerunRun.definition.url
            .replace('{repoSlug}', parsedArgs.repoSlug.toString())
            .replace('{prNumber}', parsedArgs.prNumber.toString())
            .replace('{task}', parsedArgs.task.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\PrReviews\PrReviewForPrController::rerunRun
* @see app/Http/Controllers/PrReviews/PrReviewForPrController.php:54
* @route '/pr-reviews/for/{repoSlug}/{prNumber}/runs/{task}/rerun'
*/
rerunRun.post = (args: { repoSlug: string | number, prNumber: string | number, task: string | { public_id: string } } | [repoSlug: string | number, prNumber: string | number, task: string | { public_id: string } ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: rerunRun.url(args, options),
    method: 'post',
})

const PrReviewForPrController = { show, rerun, runLog, rerunRun }

export default PrReviewForPrController