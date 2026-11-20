import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Api\App\WebSessionController::__invoke
* @see app/Http/Controllers/Api/App/WebSessionController.php:28
* @route '/api/app/v1/web-session'
*/
const WebSessionController = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: WebSessionController.url(options),
    method: 'post',
})

WebSessionController.definition = {
    methods: ["post"],
    url: '/api/app/v1/web-session',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\App\WebSessionController::__invoke
* @see app/Http/Controllers/Api/App/WebSessionController.php:28
* @route '/api/app/v1/web-session'
*/
WebSessionController.url = (options?: RouteQueryOptions) => {
    return WebSessionController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\WebSessionController::__invoke
* @see app/Http/Controllers/Api/App/WebSessionController.php:28
* @route '/api/app/v1/web-session'
*/
WebSessionController.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: WebSessionController.url(options),
    method: 'post',
})

export default WebSessionController