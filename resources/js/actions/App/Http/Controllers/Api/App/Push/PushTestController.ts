import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Api\App\Push\PushTestController::__invoke
* @see app/Http/Controllers/Api/App/Push/PushTestController.php:24
* @route '/api/app/v1/push-tokens/alert/test'
*/
const PushTestController = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: PushTestController.url(options),
    method: 'post',
})

PushTestController.definition = {
    methods: ["post"],
    url: '/api/app/v1/push-tokens/alert/test',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\App\Push\PushTestController::__invoke
* @see app/Http/Controllers/Api/App/Push/PushTestController.php:24
* @route '/api/app/v1/push-tokens/alert/test'
*/
PushTestController.url = (options?: RouteQueryOptions) => {
    return PushTestController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Push\PushTestController::__invoke
* @see app/Http/Controllers/Api/App/Push/PushTestController.php:24
* @route '/api/app/v1/push-tokens/alert/test'
*/
PushTestController.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: PushTestController.url(options),
    method: 'post',
})

export default PushTestController