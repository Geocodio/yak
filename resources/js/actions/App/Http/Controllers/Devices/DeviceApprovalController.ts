import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Devices\DeviceApprovalController::show
* @see app/Http/Controllers/Devices/DeviceApprovalController.php:24
* @route '/device'
*/
export const show = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/device',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Devices\DeviceApprovalController::show
* @see app/Http/Controllers/Devices/DeviceApprovalController.php:24
* @route '/device'
*/
show.url = (options?: RouteQueryOptions) => {
    return show.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Devices\DeviceApprovalController::show
* @see app/Http/Controllers/Devices/DeviceApprovalController.php:24
* @route '/device'
*/
show.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Devices\DeviceApprovalController::show
* @see app/Http/Controllers/Devices/DeviceApprovalController.php:24
* @route '/device'
*/
show.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Devices\DeviceApprovalController::approve
* @see app/Http/Controllers/Devices/DeviceApprovalController.php:62
* @route '/device/approve'
*/
export const approve = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: approve.url(options),
    method: 'post',
})

approve.definition = {
    methods: ["post"],
    url: '/device/approve',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Devices\DeviceApprovalController::approve
* @see app/Http/Controllers/Devices/DeviceApprovalController.php:62
* @route '/device/approve'
*/
approve.url = (options?: RouteQueryOptions) => {
    return approve.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Devices\DeviceApprovalController::approve
* @see app/Http/Controllers/Devices/DeviceApprovalController.php:62
* @route '/device/approve'
*/
approve.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: approve.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Devices\DeviceApprovalController::deny
* @see app/Http/Controllers/Devices/DeviceApprovalController.php:80
* @route '/device/deny'
*/
export const deny = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: deny.url(options),
    method: 'post',
})

deny.definition = {
    methods: ["post"],
    url: '/device/deny',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Devices\DeviceApprovalController::deny
* @see app/Http/Controllers/Devices/DeviceApprovalController.php:80
* @route '/device/deny'
*/
deny.url = (options?: RouteQueryOptions) => {
    return deny.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Devices\DeviceApprovalController::deny
* @see app/Http/Controllers/Devices/DeviceApprovalController.php:80
* @route '/device/deny'
*/
deny.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: deny.url(options),
    method: 'post',
})

const DeviceApprovalController = { show, approve, deny }

export default DeviceApprovalController