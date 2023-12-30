<?php declare(strict_types=1);

/**
 * The connection scope information is divided into $scope and $context,
 * where the $context corresponds to the <code>scope[asgi]</code> dictionary
 *
 * {@see https://asgi.readthedocs.io/en/latest/specs/www.html#http-connection-scope}
 *
 * @psalm-type HeadersList = list<array{0:string,1:string}>
 * @psalm-type Scope = array{
 *     type:non-empty-string,
 *     http_version:non-empty-string,
 *     method:non-empty-string,
 *     scheme?:non-empty-string,
 *     path:non-empty-string,
 *     raw_path:non-empty-string,
 *     query_string:string,
 *     root_path?:string,
 *     headers:HeadersList,
 *     client?:array{0:string,1:int}|null,
 *     server?:array{0:string,1:int}|array{0:string,1?:null},
 *     state?:array
 * }
 * @psalm-type SendEvent = array{
 *     type:non-empty-string,
 *     status:int,
 *     headers?:HeadersList,
 *     trailers?:boolean
 * }
 * @psalm-type SendIterable = iterable<array-key,SendEvent>
 * @psalm-type ReceiveData = array{
 *     type:non-empty-string,
 *     body:string,
 *     more_body?:boolean
 * }
 * @psalm-type ReceiveIterable = Generator<array-key,ReceiveData>
 * @psalm-type Context = array{
 *     version:string,
 *     spec_version:string
 * }
 * @psalm-type Handler = callable(Scope $scope,ReceiveIterable $receive,Context $context): SendIterable
 */
final class FastCgiAsgiAdapter
{
    private const CONTEXT = [
//    'version' => '2.0',
//    'spec_version' => '2.0'
    ];
    /**
     * @param Handler $handler
     * @return void
     */
    public function handle(callable $handler): void
    {
        $scope = $this->extractScope();

//        $handler = require_once __DIR__ . '/app.php';

        $responseStart = false;
        $responseStatusCode = null;
        $responseHeaders = [];
        $responseBody = '';
        $responseSent = false;
        $receive = static function () use (&$responseSent) {
            yield [
                'type' => 'http.request',
                'body' => ini_get('enable_post_data_reading') ? file_get_contents('php://input') : null,
                'more_body' => false,
            ];

            while (true) {
                if ($responseSent) {
                    yield ['type' => 'http.disconnect'];
                } else {
                    yield null;
                }
            }
        };

        foreach ($handler($scope, $receive(), self::CONTEXT) as $event) {
            assert(array_key_exists('type', $event));
            if ($event['type'] === 'http.response.start') {
                $responseStart = true;
                $responseStatusCode = $event['status'];
                $responseHeaders = $event['headers'];
                continue;
            }

            if (!$responseStart) {
                // something went wrong
            }

            if ($event['type'] == 'http.response.body') {
                $responseBody .= $event['body'];
                if (($event['more_body'] ?? false) === true) {
                    continue;
                }

                http_response_code($responseStatusCode);
                foreach ($responseHeaders as [$headerName, $headerValue]) {
                    header("$headerName: $headerValue");
                }

                echo $responseBody;
                $responseSent = true;
                continue;
            }

            http_response_code(500);
            break;
        }
    }

    /**
     * @return HeadersList
     */
    protected function extractHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[] = [str_replace('_', '-', substr($key, 5)), $value];
            } elseif (\in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'], true)) {
                $headers[] = [str_replace('_', '-', $key), $value];
            }
        }

        // PHP_AUTH_USER/PHP_AUTH_PW
        if (isset($_SERVER['PHP_AUTH_USER'])) {
            $headers[] = ['authorization', 'Basic ' . base64_encode($_SERVER['PHP_AUTH_USER'] . ':' . ($_SERVER['PHP_AUTH_PW'] ?? ''))];
        } elseif (isset($_SERVER['PHP_AUTH_DIGEST'])) {
            $headers[] = ['authorization', $_SERVER['PHP_AUTH_DIGEST']];
        } else {
            /*
             * php-cgi under Apache does not pass HTTP Basic user/pass to PHP by default
             * For this workaround to work, add these lines to your .htaccess file:
             * RewriteCond %{HTTP:Authorization} .+
             * RewriteRule ^ - [E=HTTP_AUTHORIZATION:%0]
             *
             * A sample .htaccess file:
             * RewriteEngine On
             * RewriteCond %{HTTP:Authorization} .+
             * RewriteRule ^ - [E=HTTP_AUTHORIZATION:%0]
             * RewriteCond %{REQUEST_FILENAME} !-f
             * RewriteRule ^(.*)$ index.php [QSA,L]
             */

            $authorizationHeader = null;
            if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
                $headers[] = ['authorization', $_SERVER['HTTP_AUTHORIZATION']];
            } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
                $headers[] = ['authorization', $_SERVER['REDIRECT_HTTP_AUTHORIZATION']];
            }
        }

        return $headers;
    }

    /**
     * @return Scope
     */
    protected function extractScope(): array
    {
        return [
            'type' => 'http',
            'http_version' => substr($_SERVER['SERVER_PROTOCOL'], 5),
            'method' => $_SERVER['REQUEST_METHOD'],
            'scheme' => ($_SERVER['HTTPS'] ?? false ? 'https' : 'http') ?? 'http',
            'path' => explode('?', $_SERVER['REQUEST_URI'])[0],
            'raw_path' => $_SERVER['ORIG_PATH_INFO'] ?? $_SERVER['PHP_SELF'],
            'query_string' => $_SERVER['QUERY_STRING'],
            'root_path' => $_SERVER['DOCUMENT_ROOT'],
            'headers' => $this->extractHeaders(),
            'client' => [$_SERVER['REMOTE_ADDR'], $_SERVER['REMOTE_PORT']],
            'server' => [$_SERVER['SERVER_ADDR'], $_SERVER['SERVER_PORT']],
        ];
    }
}
