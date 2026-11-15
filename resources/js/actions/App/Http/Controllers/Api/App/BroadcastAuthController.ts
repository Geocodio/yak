import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Api\App\BroadcastAuthController::__invoke
* @see app/Http/Controllers/Api/App/BroadcastAuthController.php:17
* @route '/api/app/v1/broadcasting/auth'
*/
const BroadcastAuthController = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: BroadcastAuthController.url(options),
    method: 'post',
})

BroadcastAuthController.definition = {
    methods: ["post"],
    url: '/api/app/v1/broadcasting/auth',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\App\BroadcastAuthController::__invoke
* @see app/Http/Controllers/Api/App/BroadcastAuthController.php:17
* @route '/api/app/v1/broadcasting/auth'
*/
BroadcastAuthController.url = (options?: RouteQueryOptions) => {
    return BroadcastAuthController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\BroadcastAuthController::__invoke
* @see app/Http/Controllers/Api/App/BroadcastAuthController.php:17
* @route '/api/app/v1/broadcasting/auth'
*/
BroadcastAuthController.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: BroadcastAuthController.url(options),
    method: 'post',
})

export default BroadcastAuthController