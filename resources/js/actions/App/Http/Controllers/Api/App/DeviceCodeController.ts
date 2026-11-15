import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Api\App\DeviceCodeController::__invoke
* @see app/Http/Controllers/Api/App/DeviceCodeController.php:13
* @route '/api/app/v1/device/code'
*/
const DeviceCodeController = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: DeviceCodeController.url(options),
    method: 'post',
})

DeviceCodeController.definition = {
    methods: ["post"],
    url: '/api/app/v1/device/code',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\App\DeviceCodeController::__invoke
* @see app/Http/Controllers/Api/App/DeviceCodeController.php:13
* @route '/api/app/v1/device/code'
*/
DeviceCodeController.url = (options?: RouteQueryOptions) => {
    return DeviceCodeController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\DeviceCodeController::__invoke
* @see app/Http/Controllers/Api/App/DeviceCodeController.php:13
* @route '/api/app/v1/device/code'
*/
DeviceCodeController.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: DeviceCodeController.url(options),
    method: 'post',
})

export default DeviceCodeController