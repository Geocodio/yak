import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Tasks\StoreTaskController::__invoke
* @see app/Http/Controllers/Tasks/StoreTaskController.php:17
* @route '/tasks'
*/
const StoreTaskController = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: StoreTaskController.url(options),
    method: 'post',
})

StoreTaskController.definition = {
    methods: ["post"],
    url: '/tasks',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Tasks\StoreTaskController::__invoke
* @see app/Http/Controllers/Tasks/StoreTaskController.php:17
* @route '/tasks'
*/
StoreTaskController.url = (options?: RouteQueryOptions) => {
    return StoreTaskController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Tasks\StoreTaskController::__invoke
* @see app/Http/Controllers/Tasks/StoreTaskController.php:17
* @route '/tasks'
*/
StoreTaskController.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: StoreTaskController.url(options),
    method: 'post',
})

export default StoreTaskController