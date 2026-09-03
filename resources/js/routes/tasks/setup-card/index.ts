import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Tasks\DismissSetupCardController::__invoke
* @see app/Http/Controllers/Tasks/DismissSetupCardController.php:11
* @route '/tasks/setup-card/dismiss'
*/
export const dismiss = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: dismiss.url(options),
    method: 'post',
})

dismiss.definition = {
    methods: ["post"],
    url: '/tasks/setup-card/dismiss',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Tasks\DismissSetupCardController::__invoke
* @see app/Http/Controllers/Tasks/DismissSetupCardController.php:11
* @route '/tasks/setup-card/dismiss'
*/
dismiss.url = (options?: RouteQueryOptions) => {
    return dismiss.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Tasks\DismissSetupCardController::__invoke
* @see app/Http/Controllers/Tasks/DismissSetupCardController.php:11
* @route '/tasks/setup-card/dismiss'
*/
dismiss.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: dismiss.url(options),
    method: 'post',
})

const setupCard = {
    dismiss: Object.assign(dismiss, dismiss),
}

export default setupCard