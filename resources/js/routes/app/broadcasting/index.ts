import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Api\App\BroadcastAuthController::__invoke
* @see app/Http/Controllers/Api/App/BroadcastAuthController.php:17
* @route '/api/app/v1/broadcasting/auth'
*/
export const auth = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: auth.url(options),
    method: 'post',
})

auth.definition = {
    methods: ["post"],
    url: '/api/app/v1/broadcasting/auth',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\App\BroadcastAuthController::__invoke
* @see app/Http/Controllers/Api/App/BroadcastAuthController.php:17
* @route '/api/app/v1/broadcasting/auth'
*/
auth.url = (options?: RouteQueryOptions) => {
    return auth.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\BroadcastAuthController::__invoke
* @see app/Http/Controllers/Api/App/BroadcastAuthController.php:17
* @route '/api/app/v1/broadcasting/auth'
*/
auth.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: auth.url(options),
    method: 'post',
})

const broadcasting = {
    auth: Object.assign(auth, auth),
}

export default broadcasting