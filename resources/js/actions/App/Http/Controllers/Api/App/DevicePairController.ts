import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Api\App\DevicePairController::__invoke
* @see app/Http/Controllers/Api/App/DevicePairController.php:14
* @route '/api/app/v1/device/pair'
*/
const DevicePairController = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: DevicePairController.url(options),
    method: 'post',
})

DevicePairController.definition = {
    methods: ["post"],
    url: '/api/app/v1/device/pair',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\App\DevicePairController::__invoke
* @see app/Http/Controllers/Api/App/DevicePairController.php:14
* @route '/api/app/v1/device/pair'
*/
DevicePairController.url = (options?: RouteQueryOptions) => {
    return DevicePairController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\DevicePairController::__invoke
* @see app/Http/Controllers/Api/App/DevicePairController.php:14
* @route '/api/app/v1/device/pair'
*/
DevicePairController.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: DevicePairController.url(options),
    method: 'post',
})

export default DevicePairController