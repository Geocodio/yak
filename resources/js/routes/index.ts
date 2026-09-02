import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../wayfinder'
/**
* @see \Laravel\Fortify\Http\Controllers\AuthenticatedSessionController::logout
* @see vendor/laravel/fortify/src/Http/Controllers/AuthenticatedSessionController.php:100
* @route '/logout'
*/
export const logout = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: logout.url(options),
    method: 'post',
})

logout.definition = {
    methods: ["post"],
    url: '/logout',
} satisfies RouteDefinition<["post"]>

/**
* @see \Laravel\Fortify\Http\Controllers\AuthenticatedSessionController::logout
* @see vendor/laravel/fortify/src/Http/Controllers/AuthenticatedSessionController.php:100
* @route '/logout'
*/
logout.url = (options?: RouteQueryOptions) => {
    return logout.definition.url + queryParams(options)
}

/**
* @see \Laravel\Fortify\Http\Controllers\AuthenticatedSessionController::logout
* @see vendor/laravel/fortify/src/Http/Controllers/AuthenticatedSessionController.php:100
* @route '/logout'
*/
logout.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: logout.url(options),
    method: 'post',
})

/**
* @see routes/web.php:34
* @route '/'
*/
export const home = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: home.url(options),
    method: 'get',
})

home.definition = {
    methods: ["get","head"],
    url: '/',
} satisfies RouteDefinition<["get","head"]>

/**
* @see routes/web.php:34
* @route '/'
*/
home.url = (options?: RouteQueryOptions) => {
    return home.definition.url + queryParams(options)
}

/**
* @see routes/web.php:34
* @route '/'
*/
home.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: home.url(options),
    method: 'get',
})

/**
* @see routes/web.php:34
* @route '/'
*/
home.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: home.url(options),
    method: 'head',
})

/**
* @see routes/web.php:42
* @route '/letmein'
*/
export const letmein = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: letmein.url(options),
    method: 'get',
})

letmein.definition = {
    methods: ["get","head"],
    url: '/letmein',
} satisfies RouteDefinition<["get","head"]>

/**
* @see routes/web.php:42
* @route '/letmein'
*/
letmein.url = (options?: RouteQueryOptions) => {
    return letmein.definition.url + queryParams(options)
}

/**
* @see routes/web.php:42
* @route '/letmein'
*/
letmein.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: letmein.url(options),
    method: 'get',
})

/**
* @see routes/web.php:42
* @route '/letmein'
*/
letmein.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: letmein.url(options),
    method: 'head',
})

/**
* @see \Illuminate\Routing\ViewController::__invoke
* @see vendor/laravel/framework/src/Illuminate/Routing/ViewController.php:32
* @route '/login'
*/
export const login = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: login.url(options),
    method: 'get',
})

login.definition = {
    methods: ["get","head"],
    url: '/login',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Illuminate\Routing\ViewController::__invoke
* @see vendor/laravel/framework/src/Illuminate/Routing/ViewController.php:32
* @route '/login'
*/
login.url = (options?: RouteQueryOptions) => {
    return login.definition.url + queryParams(options)
}

/**
* @see \Illuminate\Routing\ViewController::__invoke
* @see vendor/laravel/framework/src/Illuminate/Routing/ViewController.php:32
* @route '/login'
*/
login.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: login.url(options),
    method: 'get',
})

/**
* @see \Illuminate\Routing\ViewController::__invoke
* @see vendor/laravel/framework/src/Illuminate/Routing/ViewController.php:32
* @route '/login'
*/
login.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: login.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Tasks\TaskListController::__invoke
* @see app/Http/Controllers/Tasks/TaskListController.php:30
* @route '/tasks'
*/
export const tasks = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: tasks.url(options),
    method: 'get',
})

tasks.definition = {
    methods: ["get","head"],
    url: '/tasks',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Tasks\TaskListController::__invoke
* @see app/Http/Controllers/Tasks/TaskListController.php:30
* @route '/tasks'
*/
tasks.url = (options?: RouteQueryOptions) => {
    return tasks.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Tasks\TaskListController::__invoke
* @see app/Http/Controllers/Tasks/TaskListController.php:30
* @route '/tasks'
*/
tasks.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: tasks.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Tasks\TaskListController::__invoke
* @see app/Http/Controllers/Tasks/TaskListController.php:30
* @route '/tasks'
*/
tasks.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: tasks.url(options),
    method: 'head',
})

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/costs'
*/
export const costs = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: costs.url(options),
    method: 'get',
})

costs.definition = {
    methods: ["get","head"],
    url: '/costs',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/costs'
*/
costs.url = (options?: RouteQueryOptions) => {
    return costs.definition.url + queryParams(options)
}

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/costs'
*/
costs.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: costs.url(options),
    method: 'get',
})

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/costs'
*/
costs.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: costs.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Repositories\RepositoryController::repos
* @see app/Http/Controllers/Repositories/RepositoryController.php:22
* @route '/repos'
*/
export const repos = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: repos.url(options),
    method: 'get',
})

repos.definition = {
    methods: ["get","head"],
    url: '/repos',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Repositories\RepositoryController::repos
* @see app/Http/Controllers/Repositories/RepositoryController.php:22
* @route '/repos'
*/
repos.url = (options?: RouteQueryOptions) => {
    return repos.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Repositories\RepositoryController::repos
* @see app/Http/Controllers/Repositories/RepositoryController.php:22
* @route '/repos'
*/
repos.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: repos.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Repositories\RepositoryController::repos
* @see app/Http/Controllers/Repositories/RepositoryController.php:22
* @route '/repos'
*/
repos.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: repos.url(options),
    method: 'head',
})

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/health'
*/
export const health = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: health.url(options),
    method: 'get',
})

health.definition = {
    methods: ["get","head"],
    url: '/health',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/health'
*/
health.url = (options?: RouteQueryOptions) => {
    return health.definition.url + queryParams(options)
}

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/health'
*/
health.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: health.url(options),
    method: 'get',
})

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/health'
*/
health.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: health.url(options),
    method: 'head',
})

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/channels'
*/
export const channels = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: channels.url(options),
    method: 'get',
})

channels.definition = {
    methods: ["get","head"],
    url: '/channels',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/channels'
*/
channels.url = (options?: RouteQueryOptions) => {
    return channels.definition.url + queryParams(options)
}

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/channels'
*/
channels.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: channels.url(options),
    method: 'get',
})

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/channels'
*/
channels.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: channels.url(options),
    method: 'head',
})

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/prompts'
*/
export const prompts = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: prompts.url(options),
    method: 'get',
})

prompts.definition = {
    methods: ["get","head"],
    url: '/prompts',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/prompts'
*/
prompts.url = (options?: RouteQueryOptions) => {
    return prompts.definition.url + queryParams(options)
}

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/prompts'
*/
prompts.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: prompts.url(options),
    method: 'get',
})

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/prompts'
*/
prompts.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: prompts.url(options),
    method: 'head',
})

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/skills'
*/
export const skills = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: skills.url(options),
    method: 'get',
})

skills.definition = {
    methods: ["get","head"],
    url: '/skills',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/skills'
*/
skills.url = (options?: RouteQueryOptions) => {
    return skills.definition.url + queryParams(options)
}

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/skills'
*/
skills.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: skills.url(options),
    method: 'get',
})

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/skills'
*/
skills.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: skills.url(options),
    method: 'head',
})

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/pr-reviews'
*/
export const prReviews = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: prReviews.url(options),
    method: 'get',
})

prReviews.definition = {
    methods: ["get","head"],
    url: '/pr-reviews',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/pr-reviews'
*/
prReviews.url = (options?: RouteQueryOptions) => {
    return prReviews.definition.url + queryParams(options)
}

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/pr-reviews'
*/
prReviews.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: prReviews.url(options),
    method: 'get',
})

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/pr-reviews'
*/
prReviews.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: prReviews.url(options),
    method: 'head',
})

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/deployments'
*/
export const deployments = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: deployments.url(options),
    method: 'get',
})

deployments.definition = {
    methods: ["get","head"],
    url: '/deployments',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/deployments'
*/
deployments.url = (options?: RouteQueryOptions) => {
    return deployments.definition.url + queryParams(options)
}

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/deployments'
*/
deployments.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: deployments.url(options),
    method: 'get',
})

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/deployments'
*/
deployments.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: deployments.url(options),
    method: 'head',
})

/**
* @see routes/web.php:120
* @route '/inertia-boot'
*/
export const inertiaBoot = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: inertiaBoot.url(options),
    method: 'get',
})

inertiaBoot.definition = {
    methods: ["get","head"],
    url: '/inertia-boot',
} satisfies RouteDefinition<["get","head"]>

/**
* @see routes/web.php:120
* @route '/inertia-boot'
*/
inertiaBoot.url = (options?: RouteQueryOptions) => {
    return inertiaBoot.definition.url + queryParams(options)
}

/**
* @see routes/web.php:120
* @route '/inertia-boot'
*/
inertiaBoot.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: inertiaBoot.url(options),
    method: 'get',
})

/**
* @see routes/web.php:120
* @route '/inertia-boot'
*/
inertiaBoot.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: inertiaBoot.url(options),
    method: 'head',
})

