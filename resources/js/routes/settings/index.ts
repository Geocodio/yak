import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../wayfinder'
/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/settings/linear'
*/
export const linear = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: linear.url(options),
    method: 'get',
})

linear.definition = {
    methods: ["get","head"],
    url: '/settings/linear',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/settings/linear'
*/
linear.url = (options?: RouteQueryOptions) => {
    return linear.definition.url + queryParams(options)
}

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/settings/linear'
*/
linear.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: linear.url(options),
    method: 'get',
})

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/settings/linear'
*/
linear.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: linear.url(options),
    method: 'head',
})

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/settings/video'
*/
export const video = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: video.url(options),
    method: 'get',
})

video.definition = {
    methods: ["get","head"],
    url: '/settings/video',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/settings/video'
*/
video.url = (options?: RouteQueryOptions) => {
    return video.definition.url + queryParams(options)
}

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/settings/video'
*/
video.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: video.url(options),
    method: 'get',
})

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/settings/video'
*/
video.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: video.url(options),
    method: 'head',
})

const settings = {
    linear: Object.assign(linear, linear),
    video: Object.assign(video, video),
}

export default settings