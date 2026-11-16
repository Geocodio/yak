import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Api\App\DeviceTokenController::__invoke
* @see app/Http/Controllers/Api/App/DeviceTokenController.php:15
* @route '/api/app/v1/device/token'
*/
const DeviceTokenController = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: DeviceTokenController.url(options),
    method: 'post',
})

DeviceTokenController.definition = {
    methods: ["post"],
    url: '/api/app/v1/device/token',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\App\DeviceTokenController::__invoke
* @see app/Http/Controllers/Api/App/DeviceTokenController.php:15
* @route '/api/app/v1/device/token'
*/
DeviceTokenController.url = (options?: RouteQueryOptions) => {
    return DeviceTokenController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\DeviceTokenController::__invoke
* @see app/Http/Controllers/Api/App/DeviceTokenController.php:15
* @route '/api/app/v1/device/token'
*/
DeviceTokenController.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: DeviceTokenController.url(options),
    method: 'post',
})

export default DeviceTokenController