<?php

use Illuminate\Http\Request;
use App\Support\ApiResponse;
use Illuminate\Foundation\Application;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'account.active' => \App\Http\Middleware\EnsureAccountIsActive::class,
        ]);

        $middleware->append([
            \App\Http\Middleware\ForceJsonResponse::class,
            \App\Http\Middleware\LogRequest::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {

        $exceptions->render(function (ValidationException $e, Request $request) {
            return ApiResponse::validationErrorResponse($e->errors());
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return ApiResponse::unauthorizedResponse('Unauthenticated.');
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            return ApiResponse::forbiddenResponse();
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            return ApiResponse::notFoundResponse('Resource not found.');
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            return ApiResponse::notFoundResponse('Route not found.');
        });

        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $request) {
            return ApiResponse::methodNotAllowedResponse();
        });

        $exceptions->render(function (TooManyRequestsHttpException $e, Request $request) {
            return ApiResponse::tooManyRequestsResponse();
        });

        $exceptions->render(function (HttpException $e, Request $request) {
            return ApiResponse::errorResponse(
                $e->getMessage() ?: 'HTTP Error.',
                null,
                $e->getStatusCode()
            );
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            return ApiResponse::serverErrorResponse(
                config('app.debug')
                    ? $e->getMessage()
                    : 'Internal server error.'
            );
        });

        $exceptions->shouldRenderJsonWhen(function (Request $request) {
            return $request->is('api/*');
        });

    })->create();
