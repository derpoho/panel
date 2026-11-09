<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeploySource;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves an uploaded project to the daemon as a read-only Git repository,
 * over Git's smart HTTP protocol, by handing the request to `git
 * http-backend`. Only fetching is allowed (no pushes), and only for an
 * upload that's ready and not expired. The token in the address is the
 * only key, so it's long and random and the repository is gone in a day.
 */
class GitSourceController extends Controller
{
    public function __invoke(Request $request, string $token, string $path): Response
    {
        $source = DeploySource::query()->where('token', $token)->first();
        abort_if($source === null || ! $source->usable() || ! is_dir($source->repoPath()), 404);

        $fetching = ($path === 'info/refs' && $request->isMethod('GET') && $request->query('service') === 'git-upload-pack')
            || ($path === 'git-upload-pack' && $request->isMethod('POST'));
        abort_unless($fetching, 403);

        // Sending a large project to the daemon can outlast PHP's usual limit.
        set_time_limit(0);

        $env = [
            'GIT_PROJECT_ROOT' => $source->directory(),
            'GIT_HTTP_EXPORT_ALL' => '1',
            'PATH_INFO' => '/repo.git/'.$path,
            'REQUEST_METHOD' => $request->method(),
            'QUERY_STRING' => (string) $request->getQueryString(),
            'CONTENT_TYPE' => (string) $request->header('Content-Type'),
            'HTTP_CONTENT_ENCODING' => (string) $request->header('Content-Encoding'),
            'GIT_PROTOCOL' => (string) $request->header('Git-Protocol'),
            'REMOTE_ADDR' => (string) $request->ip(),
            'PATH' => (string) getenv('PATH'),
        ];

        $process = proc_open(['git', 'http-backend'], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $source->directory(), $env);
        abort_unless(is_resource($process), 500);

        fwrite($pipes[0], $request->getContent());
        fclose($pipes[0]);

        // CGI: headers, a blank line, then the body.
        $status = 200;
        $headers = [];
        while (($line = fgets($pipes[1])) !== false && trim($line) !== '') {
            [$name, $value] = array_map('trim', explode(':', $line, 2)) + [1 => ''];
            if (strcasecmp($name, 'Status') === 0) {
                $status = (int) $value;
            } else {
                $headers[$name] = $value;
            }
        }

        return new StreamedResponse(function () use ($pipes, $process): void {
            while (! feof($pipes[1])) {
                echo fread($pipes[1], 65536);
                flush();
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }, $status, $headers);
    }
}
