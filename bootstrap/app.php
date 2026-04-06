<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Add Sanctum middleware to API routes
        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);

        
        // CORS configuration
        $middleware->validateCsrfTokens(except: [
            'api/*',
        ]);
    })

    ->withMiddleware(function (Middleware $middleware) {
        // Add your middleware here
        $middleware->web([
            \App\Http\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \App\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);
    })
    
    ->withExceptions(function (Exceptions $exceptions) {
        // Handle unauthenticated requests
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            // If the request expects JSON or is for API
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                    'redirect' => null
                ], 401);
            }
            
            // For web routes, redirect to frontend login
            return redirect()->to('http://localhost:5173/login');
        });
        
        // Handle method not allowed exceptions
        $exceptions->render(function (Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException $e, Request $request) {
            return response()->json([
                'success' => false,
                'message' => 'Method not allowed. Use the correct HTTP method.',
                'allowed_methods' => $e->getHeaders()['Allow'] ?? []
            ], 405);
        });
        
        // Handle not found exceptions
        $exceptions->render(function (Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, Request $request) {
            return response()->json([
                'success' => false,
                'message' => 'Resource not found.'
            ], 404);
        });
        
    })->create();