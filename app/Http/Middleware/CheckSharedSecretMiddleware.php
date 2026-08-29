<?php

namespace App\Http\Middleware;

use App\Enum\ServerStatusEnum;
use App\Models\Server;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Proves a request came from the streaming server it claims to be.
 *
 * Identity first, credential second. Every route behind this carries the server in its
 * path, so the row is resolved here and the presented secret is then checked against
 * *that* row. The old middleware looked the server up by the secret instead, which meant
 * any box holding a valid one could address any other box's endpoints - a compromised
 * edge could ask for the origin's rendered config, and that carries the DVR credentials.
 *
 * Header only. The query-string fallback put the credential in nginx access logs, in any
 * proxy in front of the app, and in cloud-init's own log on the box.
 *
 * The row is resolved here rather than by route binding, which is why these routes opt
 * out of SubstituteBindings: binding answers 404 for an id that does not exist, and an
 * endpoint that serves credentials should not tell an unauthenticated caller which
 * server ids are real. Every refusal is the same 401.
 *
 * Fail closed at every branch: no header, an empty header, an id that is not a number,
 * a row that does not exist, a row with no credential stored, a deleted row, and a
 * mismatch all answer 401 and nothing falls through.
 */
class CheckSharedSecretMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $server = $this->server($request);

        if (! $server || $server->status === ServerStatusEnum::DELETED) {
            return $this->reject();
        }

        if (! $server->verifySharedSecret($request->header('X-Shared-Secret'))) {
            $server->recordCredentialRejection();

            return $this->reject();
        }

        $server->clearCredentialRejection();

        // The controllers type-hint the model; with binding off, this is what puts it
        // in their hands.
        $request->route()->setParameter('server', $server);

        return $next($request);
    }

    private function server(Request $request): ?Server
    {
        $id = $request->route('server');

        if ($id instanceof Server) {
            return $id;
        }

        if (! is_string($id) && ! is_int($id)) {
            return null;
        }

        if (! ctype_digit((string) $id)) {
            return null;
        }

        return Server::find((int) $id);
    }

    private function reject(): Response
    {
        return response()->json(['error' => 'Unauthorized'], 401);
    }
}
