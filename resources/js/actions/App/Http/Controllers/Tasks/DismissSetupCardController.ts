import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Tasks\DismissSetupCardController::__invoke
* @see app/Http/Controllers/Tasks/DismissSetupCardController.php:11
* @route '/tasks/setup-card/dismiss'
*/
const DismissSetupCardController = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: DismissSetupCardController.url(options),
    method: 'post',
})

DismissSetupCardController.definition = {
    methods: ["post"],
    url: '/tasks/setup-card/dismiss',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Tasks\DismissSetupCardController::__invoke
* @see app/Http/Controllers/Tasks/DismissSetupCardController.php:11
* @route '/tasks/setup-card/dismiss'
*/
DismissSetupCardController.url = (options?: RouteQueryOptions) => {
    return DismissSetupCardController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Tasks\DismissSetupCardController::__invoke
* @see app/Http/Controllers/Tasks/DismissSetupCardController.php:11
* @route '/tasks/setup-card/dismiss'
*/
DismissSetupCardController.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: DismissSetupCardController.url(options),
    method: 'post',
})

export default DismissSetupCardController