import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Deployments\DeploymentController::index
* @see app/Http/Controllers/Deployments/DeploymentController.php:21
* @route '/app/embed/deployments'
*/
const indexbe1243fdc40be1d435b49c5c35e2ccf3 = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: indexbe1243fdc40be1d435b49c5c35e2ccf3.url(options),
    method: 'get',
})

indexbe1243fdc40be1d435b49c5c35e2ccf3.definition = {
    methods: ["get","head"],
    url: '/app/embed/deployments',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Deployments\DeploymentController::index
* @see app/Http/Controllers/Deployments/DeploymentController.php:21
* @route '/app/embed/deployments'
*/
indexbe1243fdc40be1d435b49c5c35e2ccf3.url = (options?: RouteQueryOptions) => {
    return indexbe1243fdc40be1d435b49c5c35e2ccf3.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Deployments\DeploymentController::index
* @see app/Http/Controllers/Deployments/DeploymentController.php:21
* @route '/app/embed/deployments'
*/
indexbe1243fdc40be1d435b49c5c35e2ccf3.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: indexbe1243fdc40be1d435b49c5c35e2ccf3.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Deployments\DeploymentController::index
* @see app/Http/Controllers/Deployments/DeploymentController.php:21
* @route '/app/embed/deployments'
*/
indexbe1243fdc40be1d435b49c5c35e2ccf3.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: indexbe1243fdc40be1d435b49c5c35e2ccf3.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Deployments\DeploymentController::index
* @see app/Http/Controllers/Deployments/DeploymentController.php:21
* @route '/deployments'
*/
const index4a179efa3e1327fe1fa966165df8127c = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index4a179efa3e1327fe1fa966165df8127c.url(options),
    method: 'get',
})

index4a179efa3e1327fe1fa966165df8127c.definition = {
    methods: ["get","head"],
    url: '/deployments',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Deployments\DeploymentController::index
* @see app/Http/Controllers/Deployments/DeploymentController.php:21
* @route '/deployments'
*/
index4a179efa3e1327fe1fa966165df8127c.url = (options?: RouteQueryOptions) => {
    return index4a179efa3e1327fe1fa966165df8127c.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Deployments\DeploymentController::index
* @see app/Http/Controllers/Deployments/DeploymentController.php:21
* @route '/deployments'
*/
index4a179efa3e1327fe1fa966165df8127c.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index4a179efa3e1327fe1fa966165df8127c.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Deployments\DeploymentController::index
* @see app/Http/Controllers/Deployments/DeploymentController.php:21
* @route '/deployments'
*/
index4a179efa3e1327fe1fa966165df8127c.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index4a179efa3e1327fe1fa966165df8127c.url(options),
    method: 'head',
})

/**
* Multiple routes resolve to \App\Http\Controllers\Deployments\DeploymentController::index, so this export is a
* dictionary keyed by URI rather than a callable. Call a specific route with `index['<uri>'](...)`,
* or import the route by name from your generated `routes/` directory.
*/
export const index = {
    '/app/embed/deployments': indexbe1243fdc40be1d435b49c5c35e2ccf3,
    '/deployments': index4a179efa3e1327fe1fa966165df8127c,
}

/**
* @see \App\Http\Controllers\Deployments\DeploymentController::show
* @see app/Http/Controllers/Deployments/DeploymentController.php:33
* @route '/app/embed/deployments/{deployment}'
*/
const show9ced3244e9539e7413ba9bb81479d15b = (args: { deployment: string | { public_id: string } } | [deployment: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show9ced3244e9539e7413ba9bb81479d15b.url(args, options),
    method: 'get',
})

show9ced3244e9539e7413ba9bb81479d15b.definition = {
    methods: ["get","head"],
    url: '/app/embed/deployments/{deployment}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Deployments\DeploymentController::show
* @see app/Http/Controllers/Deployments/DeploymentController.php:33
* @route '/app/embed/deployments/{deployment}'
*/
show9ced3244e9539e7413ba9bb81479d15b.url = (args: { deployment: string | { public_id: string } } | [deployment: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { deployment: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'public_id' in args) {
        args = { deployment: args.public_id }
    }

    if (Array.isArray(args)) {
        args = {
            deployment: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        deployment: typeof args.deployment === 'object'
        ? args.deployment.public_id
        : args.deployment,
    }

    return show9ced3244e9539e7413ba9bb81479d15b.definition.url
            .replace('{deployment}', parsedArgs.deployment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Deployments\DeploymentController::show
* @see app/Http/Controllers/Deployments/DeploymentController.php:33
* @route '/app/embed/deployments/{deployment}'
*/
show9ced3244e9539e7413ba9bb81479d15b.get = (args: { deployment: string | { public_id: string } } | [deployment: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show9ced3244e9539e7413ba9bb81479d15b.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Deployments\DeploymentController::show
* @see app/Http/Controllers/Deployments/DeploymentController.php:33
* @route '/app/embed/deployments/{deployment}'
*/
show9ced3244e9539e7413ba9bb81479d15b.head = (args: { deployment: string | { public_id: string } } | [deployment: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show9ced3244e9539e7413ba9bb81479d15b.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Deployments\DeploymentController::show
* @see app/Http/Controllers/Deployments/DeploymentController.php:33
* @route '/deployments/{deployment}'
*/
const show5479acbafef394b07eb8e9477867ef33 = (args: { deployment: string | { public_id: string } } | [deployment: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show5479acbafef394b07eb8e9477867ef33.url(args, options),
    method: 'get',
})

show5479acbafef394b07eb8e9477867ef33.definition = {
    methods: ["get","head"],
    url: '/deployments/{deployment}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Deployments\DeploymentController::show
* @see app/Http/Controllers/Deployments/DeploymentController.php:33
* @route '/deployments/{deployment}'
*/
show5479acbafef394b07eb8e9477867ef33.url = (args: { deployment: string | { public_id: string } } | [deployment: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { deployment: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'public_id' in args) {
        args = { deployment: args.public_id }
    }

    if (Array.isArray(args)) {
        args = {
            deployment: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        deployment: typeof args.deployment === 'object'
        ? args.deployment.public_id
        : args.deployment,
    }

    return show5479acbafef394b07eb8e9477867ef33.definition.url
            .replace('{deployment}', parsedArgs.deployment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Deployments\DeploymentController::show
* @see app/Http/Controllers/Deployments/DeploymentController.php:33
* @route '/deployments/{deployment}'
*/
show5479acbafef394b07eb8e9477867ef33.get = (args: { deployment: string | { public_id: string } } | [deployment: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show5479acbafef394b07eb8e9477867ef33.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Deployments\DeploymentController::show
* @see app/Http/Controllers/Deployments/DeploymentController.php:33
* @route '/deployments/{deployment}'
*/
show5479acbafef394b07eb8e9477867ef33.head = (args: { deployment: string | { public_id: string } } | [deployment: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show5479acbafef394b07eb8e9477867ef33.url(args, options),
    method: 'head',
})

/**
* Multiple routes resolve to \App\Http\Controllers\Deployments\DeploymentController::show, so this export is a
* dictionary keyed by URI rather than a callable. Call a specific route with `show['<uri>'](...)`,
* or import the route by name from your generated `routes/` directory.
*/
export const show = {
    '/app/embed/deployments/{deployment}': show9ced3244e9539e7413ba9bb81479d15b,
    '/deployments/{deployment}': show5479acbafef394b07eb8e9477867ef33,
}

const DeploymentController = { index, show }

export default DeploymentController