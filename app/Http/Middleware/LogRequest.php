<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class LogRequest
{
    /**
     * Record API activity without persisting credentials, payment details or
     * arbitrary request bodies. This operational trail complements financial
     * ledger entries and domain-specific activity events.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $this->record($request, 500, $startedAt);
            throw $exception;
        }

        $this->record($request, $response->getStatusCode(), $startedAt);

        return $response;
    }

    private function record(Request $request, int $status, float $startedAt): void
    {
        if (!$request->is('api/*') || $request->is('api/v1/health') || app()->runningInConsole()) {
            return;
        }

        try {
            $actor = $request->user();
            $roles = $actor?->getRoleNames() ?? collect();
            $route = $request->route();
            $parameters = $route?->parameters() ?? [];
            $subjectType = null;
            $subjectId = null;

            foreach ($parameters as $key => $value) {
                if (is_object($value) && method_exists($value, 'getKey')) {
                    $subjectType = class_basename($value);
                    $subjectId = (string) $value->getKey();
                    break;
                }
                if (is_scalar($value)) {
                    $subjectType = Str::studly(Str::singular((string) $key));
                    $subjectId = Str::limit((string) $value, 120, '');
                    break;
                }
            }

            $path = $request->path();
            $action = $this->resolveAction($request->method(), $path, $route?->getName());

            AuditLog::create([
                'actor_user_id' => $actor?->id,
                'actor_role' => $roles->first() ?? 'Guest',
                'action' => $action,
                'method' => $request->method(),
                'path' => Str::limit($path, 500, ''),
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'status_code' => $status,
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
                'metadata' => [
                    'route' => $route?->getName(),
                    'query_keys' => array_keys($request->query()),
                    'body_keys' => array_keys($request->except([
                        'password', 'password_confirmation', 'current_password',
                        'token', 'access_token', 'refresh_token', 'authorization',
                        'card_number', 'cvv', 'cvc', 'expiry', 'payment_token',
                        'bank_account', 'iban', 'secret',
                    ])),
                    'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
                    'outcome' => $status >= 400 ? 'failed' : 'succeeded',
                ],
            ]);
        } catch (Throwable $loggingException) {
            // Audit infrastructure failures must not take down customer actions.
            report($loggingException);
        }
    }

    private function resolveAction(string $method, string $path, ?string $routeName): string
    {
        $path = preg_replace('#^api/v1/?#', '', $path) ?: $path;
        $segments = explode('/', trim($path, '/'));
        $resource = $segments[0] ?? 'api';
        $verb = match (strtoupper($method)) {
            'GET' => 'view',
            'POST' => 'create',
            'PUT', 'PATCH' => 'update',
            'DELETE' => 'delete',
            default => strtolower($method),
        };

        if ($resource === 'products' && strtoupper($method) === 'GET' && count($segments) > 1) {
            $resource = 'product';
            $verb = 'view';
        } elseif ($resource === 'orders' && strtoupper($method) === 'POST') {
            $verb = 'purchase';
        }

        return Str::limit($routeName ?: $resource . '.' . $verb, 160, '');
    }
}
