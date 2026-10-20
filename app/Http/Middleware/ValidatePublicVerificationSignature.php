<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Routing\Exceptions\InvalidSignatureException;

class ValidatePublicVerificationSignature
{
    public function handle(Request $request, Closure $next)
    {
        $query = (string) $request->server->get('QUERY_STRING', '');
        $public = Request::create(rtrim((string) config('app.url'), '/')
            .'/'.$request->path().($query === '' ? '' : '?'.$query));
        if (! URL::hasValidSignature($public)) {
            throw new InvalidSignatureException;
        }
        return $next($request);
    }
}
