import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Cli\DeviceCodeController::__invoke
* @see app/Http/Controllers/Cli/DeviceCodeController.php:13
* @route '/api/cli/v1/device/code'
*/
export const code = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: code.url(options),
    method: 'post',
})

code.definition = {
    methods: ["post"],
    url: '/api/cli/v1/device/code',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Cli\DeviceCodeController::__invoke
* @see app/Http/Controllers/Cli/DeviceCodeController.php:13
* @route '/api/cli/v1/device/code'
*/
code.url = (options?: RouteQueryOptions) => {
    return code.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Cli\DeviceCodeController::__invoke
* @see app/Http/Controllers/Cli/DeviceCodeController.php:13
* @route '/api/cli/v1/device/code'
*/
code.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: code.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Cli\DeviceTokenController::__invoke
* @see app/Http/Controllers/Cli/DeviceTokenController.php:13
* @route '/api/cli/v1/device/token'
*/
export const token = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: token.url(options),
    method: 'post',
})

token.definition = {
    methods: ["post"],
    url: '/api/cli/v1/device/token',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Cli\DeviceTokenController::__invoke
* @see app/Http/Controllers/Cli/DeviceTokenController.php:13
* @route '/api/cli/v1/device/token'
*/
token.url = (options?: RouteQueryOptions) => {
    return token.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Cli\DeviceTokenController::__invoke
* @see app/Http/Controllers/Cli/DeviceTokenController.php:13
* @route '/api/cli/v1/device/token'
*/
token.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: token.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Cli\DeviceController::destroy
* @see app/Http/Controllers/Cli/DeviceController.php:13
* @route '/api/cli/v1/device'
*/
export const destroy = (options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/api/cli/v1/device',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\Cli\DeviceController::destroy
* @see app/Http/Controllers/Cli/DeviceController.php:13
* @route '/api/cli/v1/device'
*/
destroy.url = (options?: RouteQueryOptions) => {
    return destroy.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Cli\DeviceController::destroy
* @see app/Http/Controllers/Cli/DeviceController.php:13
* @route '/api/cli/v1/device'
*/
destroy.delete = (options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(options),
    method: 'delete',
})

const device = {
    code: Object.assign(code, code),
    token: Object.assign(token, token),
    destroy: Object.assign(destroy, destroy),
}

export default device