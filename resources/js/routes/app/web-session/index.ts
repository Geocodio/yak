import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Api\App\WebSessionController::__invoke
* @see app/Http/Controllers/Api/App/WebSessionController.php:28
* @route '/api/app/v1/web-session'
*/
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/api/app/v1/web-session',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\App\WebSessionController::__invoke
* @see app/Http/Controllers/Api/App/WebSessionController.php:28
* @route '/api/app/v1/web-session'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\WebSessionController::__invoke
* @see app/Http/Controllers/Api/App/WebSessionController.php:28
* @route '/api/app/v1/web-session'
*/
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

const webSession = {
    store: Object.assign(store, store),
}

export default webSession