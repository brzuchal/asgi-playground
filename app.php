<?php declare(strict_types=1);

const NOT_FOUND_RESPONSE = [
    [
        'type' => 'http.response.start',
        'status' => 404,
        'headers' => [['content-type', 'text/plain']],
    ],
    ['type' => 'http.response.body', 'body' => 'Not Found'],
];

function parseFormUrlEncoded(iterable $receive): array {
    $data = '';
    foreach ($receive as $event) {
        $data .= urldecode($event['body']);
        if (($event['more_body'] ?? false) === false) {
            break;
        }
    }

    parse_str($data, $formData);

    return $formData;
}

/**
 * The connection scope information is divided into $scope and $context,
 * where the $context corresponds to the <code>scope[asgi]</code> dictionary
 *
 * {@see https://asgi.readthedocs.io/en/latest/specs/www.html#http-connection-scope}
 *
 * @param array{type:non-empty-string,http_version:non-empty-string,method:non-empty-string,scheme?:non-empty-string,path:non-empty-string,raw_path:non-empty-string,query_string:string,root_path?:string,headers:list<array{0:string,1:string}>,client?:array{0:string,1:int}|null,server?:array{0:string,1:int}|array{0:string,1?:null},state?:array} $scope
 * @param iterable<array-key,array{type:non-empty-string,body:string,more_body?:boolean}> $receive
 * @param array{version:string,spec_version:string} $context
 * @return iterable<array-key,array{type:non-empty-string,status:int,headers?:list<array{0:string,1:string}>,trailers?:boolean}>
 */
return function (array $scope, iterable $receive, array $context = []): iterable {
    assert($scope['type'] === 'http');

    // Respond with "Hello, World!" for GET /hello
    if ($scope['method'] === 'GET' && $scope['path'] === '/hello') {
        yield [
            'type' => 'http.response.start',
            'status' => 200,
            'headers' => [['content-type', 'text/plain']],
        ];
        yield ['type' => 'http.response.body', 'body' => 'Hello, World!'];
        return;
    }

    // Respond with a customized message for POST /hello
    if ($scope['method'] === 'POST' && $scope['path'] === '/hello') {
        $formData = parseFormUrlEncoded($receive);
        $name = $formData['name'] ?? 'World';

        yield [
            'type' => 'http.response.start',
            'status' => 200,
            'headers' => [['content-type', 'text/plain']],
        ];
        yield [
            'type' => 'http.response.body',
            'body' => "Hello, $name!"
        ];
        return;
    }

    // Additional routes can be introduced here...

    // Not found
    yield from NOT_FOUND_RESPONSE;
};
